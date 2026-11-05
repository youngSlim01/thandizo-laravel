<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cartões (vouchers): lotes por bairro, impressão, distribuição cartão a
 * cartão (também a um substituto), cancelamento e substituição de um cartão
 * perdido.
 *
 * Substitui list_card_communities, generate_card_batch, list_card_batches,
 * get_card_batch_cards, mark_card_batch_printed, distribute_cards,
 * cancel_card_batch, find_household_cards, replace_voucher e os auxiliares
 * luhn_check_digit, next_voucher_code e voucher_card_json (20261008052549,
 * 20261008113500, 20261008113700, 20261008114600).
 *
 * Regras: um cartão nasce PENDING com saldo 0 e só fica ACTIVE, com
 * 6 000 MT e uma entrada INITIAL_ALLOCATION, quando é entregue. Um cartão
 * perdido é REPLACED e o saldo passa para um cartão novo (duas entradas
 * REPLACEMENT_TRANSFER).
 */
class CardService
{
    public const CARD_VALUE = 6000;

    private const NOT_LIVE = ['REPLACED', 'CANCELLED', 'INVALIDATED'];

    public function __construct(
        private CodeSequence $sequences,
        private ProvinceScope $scope,
        private LedgerService $ledger,
        private AgrodealerService $agrodealers,
    ) {}

    /**
     * Bairros com agregados activos e quantos ainda não têm cartão.
     *
     * @return list<array<string, mixed>>
     */
    public function communities(User $viewer): array
    {
        $query = DB::table('communities as c')
            ->join('districts as d', 'd.id', '=', 'c.district_id')
            ->join('provinces as p', 'p.id', '=', 'd.province_id')
            ->leftJoin('localities as l', 'l.id', '=', 'c.locality_id')
            ->leftJoin('administrative_posts as a', 'a.id', '=', 'l.administrative_post_id')
            ->join('households as h', fn ($join) => $join->on('h.community_id', '=', 'c.id')->where('h.status', 'ACTIVE'))
            ->groupBy('c.id', 'c.name', 'l.name', 'a.name', 'd.name', 'p.name')
            ->orderBy('p.name')->orderBy('d.name')->orderBy('a.name')->orderBy('l.name')->orderBy('c.name')
            ->selectRaw("
                c.id as community_id, c.name as community, l.name as locality, a.name as administrative_post,
                d.name as district, p.name as province, count(h.id) as households,
                sum(not exists (select 1 from vouchers v where v.household_id = h.id and v.status not in ('REPLACED', 'CANCELLED', 'INVALIDATED'))) as households_without_card,
                (select count(*) from card_batches b where b.community_id = c.id and b.status in ('GENERATED', 'PRINTED')) as open_batches
            ");

        return $this->scope->apply($query, $viewer, 'p.id')->get()->map(fn (object $row): array => [
            ...(array) $row,
            'households' => (int) $row->households,
            'households_without_card' => (int) $row->households_without_card,
            'open_batches' => (int) $row->open_batches,
        ])->all();
    }

    /**
     * Um cartão PENDING para cada agregado activo do bairro que ainda não tem
     * cartão vivo.
     *
     * @return array{success: true, batch_id: string, batch_code: string, card_count: int}
     */
    public function generateBatch(User $actor, ?string $communityId): array
    {
        return DB::transaction(function () use ($actor, $communityId): array {
            $community = DB::table('communities as c')->join('districts as d', 'd.id', '=', 'c.district_id')
                ->where('c.id', $communityId)->lockForUpdate()->first(['c.id', 'd.name as district_name', 'd.province_id']);
            if ($community === null || ! $this->scope->allows($actor, $community->province_id)) {
                throw new DomainRuleException('COMMUNITY_NOT_FOUND');
            }

            $households = DB::table('households as h')
                ->where('h.community_id', $communityId)->where('h.status', 'ACTIVE')
                ->whereNotExists(fn ($q) => $q->from('vouchers as v')->whereColumn('v.household_id', 'h.id')->whereNotIn('v.status', self::NOT_LIVE))
                ->orderBy('h.household_code')->pluck('h.id');
            if ($households->isEmpty()) {
                throw new DomainRuleException('NO_HOUSEHOLDS_WITHOUT_CARD');
            }

            $prefix = $this->agrodealers->districtCodePrefix($community->district_name);
            $batchId = (string) Str::uuid7();
            $batchCode = 'LOTE-'.$prefix.'-'.str_pad((string) $this->sequences->next('card_batch'), 4, '0', STR_PAD_LEFT);
            DB::table('card_batches')->insert([
                'id' => $batchId, 'batch_code' => $batchCode, 'community_id' => $communityId,
                'card_count' => $households->count(), 'created_by' => $actor->id, 'created_at' => now(),
            ]);

            $now = now();
            DB::table('vouchers')->insert($households->map(fn (string $householdId): array => [
                'id' => (string) Str::uuid7(),
                'voucher_code' => $this->nextVoucherCode($community->district_name),
                'household_id' => $householdId,
                'initial_value' => self::CARD_VALUE,
                'current_balance' => 0,
                'status' => 'PENDING',
                'card_batch_id' => $batchId,
                'issued_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ])->all());

            Audit::log($actor->id, 'CARD_BATCH_GENERATED', 'CARD_BATCH', $batchId, [
                'batch_code' => $batchCode, 'community_id' => $communityId, 'card_count' => $households->count(),
            ]);

            return ['success' => true, 'batch_id' => $batchId, 'batch_code' => $batchCode, 'card_count' => $households->count()];
        });
    }

    /**
     * Lotes, mais recentes primeiro, com cartões entregues e por entregar.
     *
     * @return list<array<string, mixed>>
     */
    public function batches(User $viewer): array
    {
        $query = DB::table('card_batches as b')
            ->join('communities as c', 'c.id', '=', 'b.community_id')
            ->join('districts as d', 'd.id', '=', 'c.district_id')
            ->leftJoin('localities as l', 'l.id', '=', 'c.locality_id')
            ->orderByDesc('b.created_at')->orderByDesc('b.id')
            ->selectRaw("
                b.id as batch_id, b.batch_code, b.status, b.card_count, b.created_at, b.printed_at, b.distributed_at,
                b.cancelled_at, b.cancel_reason, c.name as community, l.name as locality, d.name as district,
                (select count(*) from vouchers v where v.card_batch_id = b.id and v.distributed_at is not null) as active_cards,
                (select count(*) from vouchers v where v.card_batch_id = b.id and v.status = 'PENDING') as pending_cards
            ");

        return $this->scope->apply($query, $viewer, 'd.province_id')->get()->map(fn (object $row): array => [
            ...(array) $row,
            'card_count' => (int) $row->card_count,
            'active_cards' => (int) $row->active_cards,
            'pending_cards' => (int) $row->pending_cards,
        ])->all();
    }

    /**
     * @return array{batch_id: string, batch_code: string, status: string, cards: list<array<string, mixed>>}
     */
    public function batchCards(User $viewer, ?string $batchId): array
    {
        $batch = $this->visibleBatch($viewer, $batchId);
        $ids = DB::table('vouchers as v')->join('households as h', 'h.id', '=', 'v.household_id')
            ->where('v.card_batch_id', $batch->id)->orderBy('h.household_code')->pluck('v.id');

        return [
            'batch_id' => $batch->id,
            'batch_code' => $batch->batch_code,
            'status' => $batch->status,
            'cards' => $ids->map(fn (string $id): array => $this->cardJson($id))->all(),
        ];
    }

    /**
     * @return array{success: true, duplicate: bool, status: string}
     */
    public function markPrinted(User $actor, ?string $batchId): array
    {
        return DB::transaction(function () use ($actor, $batchId): array {
            $batch = $this->visibleBatch($actor, $batchId, lock: true);
            if ($batch->status === 'PRINTED') {
                return ['success' => true, 'duplicate' => true, 'status' => 'PRINTED'];
            }
            if ($batch->status !== 'GENERATED') {
                throw new DomainRuleException('CARD_BATCH_STATUS_NOT_ALLOWED');
            }
            DB::table('card_batches')->where('id', $batch->id)->update(['status' => 'PRINTED', 'printed_at' => now(), 'printed_by' => $actor->id]);
            Audit::log($actor->id, 'CARD_BATCH_PRINTED', 'CARD_BATCH', $batch->id, ['batch_code' => $batch->batch_code]);

            return ['success' => true, 'duplicate' => false, 'status' => 'PRINTED'];
        });
    }

    /**
     * Activa os cartões entregues (ACTIVE, 6 000 MT, INITIAL_ALLOCATION).
     * Um cartão entregue a um substituto guarda o nome e o parentesco.
     *
     * @param  list<string>|null  $voucherIds
     * @param  list<array{voucher_id?: string, name?: string, relationship?: string}>|null  $substitutes
     * @return array<string, mixed>
     */
    public function distribute(User $actor, ?string $batchId, ?array $voucherIds, ?array $substitutes): array
    {
        return DB::transaction(function () use ($actor, $batchId, $voucherIds, $substitutes): array {
            $batch = $this->visibleBatch($actor, $batchId, lock: true);
            if ($batch->status === 'GENERATED') {
                throw new DomainRuleException('CARD_BATCH_NOT_PRINTED');
            }
            if (! in_array($batch->status, ['PRINTED', 'IN_DISTRIBUTION'], true)) {
                throw new DomainRuleException('CARD_BATCH_STATUS_NOT_ALLOWED');
            }
            $ids = array_values(array_unique(array_filter($voucherIds ?? [])));
            if ($ids === []) {
                throw new DomainRuleException('CARDS_REQUIRED');
            }
            $cards = DB::table('vouchers as v')->join('households as h', 'h.id', '=', 'v.household_id')
                ->whereIn('v.id', $ids)->where('v.card_batch_id', $batch->id)->lockForUpdate()
                ->get(['v.id', 'v.status', 'h.status as household_status'])->keyBy('id');
            if ($cards->count() !== count($ids)) {
                throw new DomainRuleException('CARD_NOT_IN_BATCH');
            }
            if ($cards->contains(fn (object $card) => ! in_array($card->status, ['PENDING', 'ACTIVE'], true))) {
                throw new DomainRuleException('CARD_NOT_PENDING');
            }
            if ($cards->contains(fn (object $card) => $card->status === 'PENDING' && $card->household_status !== 'ACTIVE')) {
                throw new DomainRuleException('CARD_HOUSEHOLD_INACTIVE');
            }

            $subs = [];
            foreach ($substitutes ?? [] as $substitute) {
                $subs[(string) ($substitute['voucher_id'] ?? '')] = [
                    'name' => trim((string) ($substitute['name'] ?? '')),
                    'relationship' => trim((string) ($substitute['relationship'] ?? '')),
                ];
            }
            foreach ($subs as $voucherId => $sub) {
                if ($sub['name'] === '' || $sub['relationship'] === '') {
                    throw new DomainRuleException('CARD_SUBSTITUTE_INCOMPLETE');
                }
                if (! in_array($voucherId, $ids, true)) {
                    throw new DomainRuleException('CARD_NOT_IN_BATCH');
                }
            }

            $activated = 0;
            foreach ($cards->where('status', 'PENDING') as $card) {
                DB::table('vouchers')->where('id', $card->id)->update([
                    'status' => 'ACTIVE', 'current_balance' => self::CARD_VALUE, 'distributed_at' => now(), 'updated_at' => now(),
                    'received_by_name' => $subs[$card->id]['name'] ?? null,
                    'received_by_relationship' => $subs[$card->id]['relationship'] ?? null,
                ]);
                $this->ledger->record($card->id, 'INITIAL_ALLOCATION', self::CARD_VALUE, self::CARD_VALUE, [
                    'notes' => "Card distributed ({$batch->batch_code})", 'created_by' => $actor->id,
                ]);
                $activated++;
            }

            $remaining = DB::table('vouchers')->where('card_batch_id', $batch->id)->where('status', 'PENDING')->count();
            $status = $remaining === 0 ? 'DISTRIBUTED' : 'IN_DISTRIBUTION';
            DB::table('card_batches')->where('id', $batch->id)->update([
                'status' => $status,
                'distributed_at' => $remaining === 0 ? now() : $batch->distributed_at,
                'distributed_by' => $remaining === 0 ? $actor->id : $batch->distributed_by,
            ]);

            if ($activated > 0) {
                Audit::log($actor->id, 'CARDS_DISTRIBUTED', 'CARD_BATCH', $batch->id, [
                    'batch_code' => $batch->batch_code, 'activated' => $activated, 'amount_each' => self::CARD_VALUE,
                    'voucher_ids' => $ids, 'remaining' => $remaining, 'substitutes' => $subs,
                ]);
            }

            return ['success' => true, 'status' => $status, 'activated' => $activated, 'remaining' => $remaining, 'duplicate' => $activated === 0];
        });
    }

    /**
     * Antes de qualquer entrega cancela o lote; durante a distribuição
     * "fecha-o", cancelando só os cartões não entregues.
     *
     * @return array<string, mixed>
     */
    public function cancelBatch(User $actor, ?string $batchId, ?string $reason): array
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new DomainRuleException('CANCEL_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($actor, $batchId, $reason): array {
            $batch = DB::table('card_batches')->where('id', $batchId)->lockForUpdate()->first()
                ?? throw new DomainRuleException('CARD_BATCH_NOT_FOUND');
            if ($batch->status === 'CANCELLED') {
                return ['success' => true, 'duplicate' => true, 'status' => 'CANCELLED'];
            }
            if ($batch->status === 'DISTRIBUTED') {
                throw new DomainRuleException('CARD_BATCH_ALREADY_DISTRIBUTED');
            }

            $cancelled = DB::table('vouchers')->where('card_batch_id', $batch->id)->where('status', 'PENDING')->update([
                'status' => 'CANCELLED', 'replacement_reason' => 'Not delivered, batch closed: '.$reason, 'updated_at' => now(),
            ]);
            $status = $batch->status === 'IN_DISTRIBUTION' ? 'DISTRIBUTED' : 'CANCELLED';
            DB::table('card_batches')->where('id', $batch->id)->update([
                'status' => $status, 'cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancel_reason' => $reason,
                'distributed_at' => $status === 'DISTRIBUTED' ? now() : $batch->distributed_at,
                'distributed_by' => $status === 'DISTRIBUTED' ? $actor->id : $batch->distributed_by,
            ]);
            Audit::log($actor->id, $status === 'DISTRIBUTED' ? 'CARD_BATCH_CLOSED' : 'CARD_BATCH_CANCELLED', 'CARD_BATCH', $batch->id, [
                'batch_code' => $batch->batch_code, 'cancelled_cards' => $cancelled, 'reason' => $reason,
            ]);

            return ['success' => true, 'duplicate' => false, 'status' => $status, 'cancelled_cards' => $cancelled];
        });
    }

    /**
     * Pelo código do cartão ou pelo CODIGO AF: os cartões do agregado, o
     * vivo primeiro.
     *
     * @return list<array<string, mixed>>
     */
    public function findHouseholdCards(User $viewer, ?string $query): array
    {
        $query = mb_strtoupper(trim((string) $query));
        if ($query === '') {
            return [];
        }
        $households = DB::table('vouchers')->where('voucher_code', $query)->pluck('household_id')
            ->merge(DB::table('households')->where('household_code', $query)->pluck('id'))
            ->unique()
            ->filter(fn (string $id): bool => app(HouseholdSearchService::class)->isVisible($viewer, $id));

        return DB::table('vouchers')->whereIn('household_id', $households)
            ->orderByRaw("status in ('ACTIVE', 'PENDING') desc")->orderByDesc('created_at')
            ->get(['id', 'issued_at', 'distributed_at', 'replaced_voucher_id'])
            ->map(fn (object $v): array => [
                ...$this->cardJson($v->id),
                'issued_at' => $v->issued_at, 'distributed_at' => $v->distributed_at, 'replaced_voucher_id' => $v->replaced_voucher_id,
            ])->all();
    }

    /**
     * Cartão perdido: fica REPLACED com saldo 0 e um cartão novo do mesmo
     * agregado recebe o saldo que restava.
     *
     * @return array<string, mixed>
     */
    public function replace(User $actor, ?string $voucherCode, ?string $reason): array
    {
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new DomainRuleException('REPLACE_REASON_REQUIRED');
        }

        return DB::transaction(function () use ($actor, $voucherCode, $reason): array {
            $old = DB::table('vouchers')->where('voucher_code', mb_strtoupper(trim((string) $voucherCode)))->lockForUpdate()->first()
                ?? throw new DomainRuleException('VOUCHER_NOT_FOUND');
            if ($old->status !== 'ACTIVE') {
                throw new DomainRuleException('VOUCHER_NOT_ACTIVE');
            }
            if ((float) $old->current_balance <= 0) {
                throw new DomainRuleException('VOUCHER_BALANCE_EMPTY');
            }
            // Uma venda recente ainda pode ser anulada (15 minutos): esperar.
            $recentSale = DB::table('transactions')->where('voucher_id', $old->id)->where('status', 'CONFIRMED')
                ->where('confirmed_at', '>', now()->subMinutes(15))->exists();
            if ($recentSale) {
                throw new DomainRuleException('VOUCHER_HAS_RECENT_SALE');
            }

            $remaining = $old->current_balance;
            $district = DB::table('households as h')->leftJoin('communities as c', 'c.id', '=', 'h.community_id')
                ->leftJoin('districts as d', 'd.id', '=', DB::raw('coalesce(h.district_id, c.district_id)'))
                ->where('h.id', $old->household_id)->value('d.name');

            DB::table('vouchers')->where('id', $old->id)->update(['status' => 'REPLACED', 'current_balance' => 0, 'replacement_reason' => $reason, 'updated_at' => now()]);
            $newId = (string) Str::uuid7();
            $newCode = $this->nextVoucherCode((string) $district);
            DB::table('vouchers')->insert([
                'id' => $newId, 'voucher_code' => $newCode, 'household_id' => $old->household_id, 'initial_value' => self::CARD_VALUE,
                'current_balance' => $remaining, 'status' => 'ACTIVE', 'replaced_voucher_id' => $old->id, 'replacement_reason' => $reason,
                'distributed_at' => now(), 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->ledger->record($old->id, 'REPLACEMENT_TRANSFER', -$remaining, 0, [
                'reference_voucher_id' => $newId, 'notes' => "Replaced by {$newCode}: {$reason}", 'created_by' => $actor->id,
            ]);
            $this->ledger->record($newId, 'REPLACEMENT_TRANSFER', $remaining, $remaining, [
                'reference_voucher_id' => $old->id, 'notes' => "Replaces {$old->voucher_code}: {$reason}", 'created_by' => $actor->id,
            ]);
            Audit::log($actor->id, 'VOUCHER_REPLACED', 'VOUCHER', $newId, [
                'old_voucher_code' => $old->voucher_code, 'new_voucher_code' => $newCode, 'transferred' => $remaining, 'reason' => $reason,
            ]);

            return [
                'success' => true, 'old_voucher_code' => $old->voucher_code, 'new_voucher_code' => $newCode,
                'transferred' => $remaining, 'card' => $this->cardJson($newId),
            ];
        });
    }

    /**
     * Dados de um cartão para imprimir e listar (antigo voucher_card_json).
     *
     * @return array<string, mixed>
     */
    public function cardJson(string $voucherId): array
    {
        return (array) DB::table('vouchers as v')
            ->join('households as h', 'h.id', '=', 'v.household_id')
            ->leftJoin('communities as c', 'c.id', '=', 'h.community_id')
            ->leftJoin('localities as l', 'l.id', '=', 'h.locality_id')
            ->leftJoin('administrative_posts as a', 'a.id', '=', 'h.administrative_post_id')
            ->leftJoin('districts as d', 'd.id', '=', DB::raw('coalesce(h.district_id, c.district_id)'))
            ->leftJoin('provinces as p', 'p.id', '=', DB::raw('coalesce(h.province_id, d.province_id)'))
            ->where('v.id', $voucherId)
            ->first([
                'v.id as voucher_id', 'v.voucher_code', 'v.status as voucher_status', 'v.current_balance',
                'h.household_code', 'h.status as household_status', 'h.representative_name',
                'v.received_by_name', 'v.received_by_relationship', 'c.name as community', 'l.name as locality',
                'a.name as administrative_post', 'd.name as district', 'p.name as province',
            ]);
    }

    /**
     * VCH-<DIS>-<NNNNNN>-<C>: três letras do distrito, número de série e um
     * dígito de controlo Luhn que apanha erros de escrita.
     */
    public function nextVoucherCode(string $districtName): string
    {
        $serial = str_pad((string) $this->sequences->next('voucher_card'), 6, '0', STR_PAD_LEFT);

        return 'VCH-'.$this->agrodealers->districtCodePrefix($districtName).'-'.$serial.'-'.self::luhnCheckDigit($serial);
    }

    public static function luhnCheckDigit(string $digits): int
    {
        $sum = 0;
        $double = true;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $digit = (int) $digits[$i];
            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
            $double = ! $double;
        }

        return (10 - ($sum % 10)) % 10;
    }

    private function visibleBatch(User $viewer, ?string $batchId, bool $lock = false): object
    {
        $query = DB::table('card_batches as b')->join('communities as c', 'c.id', '=', 'b.community_id')
            ->join('districts as d', 'd.id', '=', 'c.district_id')->where('b.id', $batchId)
            ->select('b.*', 'd.province_id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $batch = $query->first();
        if ($batch === null || ! $this->scope->allows($viewer, $batch->province_id)) {
            throw new DomainRuleException('CARD_BATCH_NOT_FOUND');
        }

        return $batch;
    }
}
