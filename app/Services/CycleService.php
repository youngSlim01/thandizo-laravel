<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ciclos do programa: o mesmo cartão é recarregado em cada ciclo e o saldo
 * não usado expira no fim do ciclo.
 *
 * Substitui open_programme_cycle, allocate_cycle, close_programme_cycle,
 * list_programme_cycles e cycle_summary (20261008114400, 20261008114600).
 *
 * Regras: um só ciclo aberto; cada agregado recebe por ciclo o valor do seu
 * escalão no momento da recarga; no fecho o
 * saldo que resta expira (CYCLE_EXPIRY) e fica registado por agregado; um
 * ciclo só fecha 15 minutos depois da última venda (janela de anulação).
 */
class CycleService
{
    /** Hora de Moçambique para as datas do ciclo. */
    private const TIMEZONE = 'Africa/Maputo';

    public function __construct(
        private ProvinceScope $scope,
        private LedgerService $ledger,
        private HouseholdSearchService $households,
    ) {}

    /**
     * @return array{success: true, cycle_id: string, code: string}
     */
    public function open(User $actor, ?string $name, ?string $startsOn, ?string $endsOn, ?string $description = null): array
    {
        return DB::transaction(function () use ($actor, $name, $startsOn, $endsOn, $description): array {
            DB::table('programme_cycles')->lockForUpdate()->get(['id']);
            if (DB::table('programme_cycles')->where('status', 'OPEN')->exists()) {
                throw new DomainRuleException('CYCLE_ALREADY_OPEN');
            }
            if ($startsOn && $endsOn && $endsOn < $startsOn) {
                throw new DomainRuleException('INVALID_PERIOD');
            }
            $n = DB::table('programme_cycles')->pluck('code')
                ->map(fn (string $code): int => preg_match('/^CIC-(\d+)$/', $code, $m) ? (int) $m[1] : 0)
                ->max() + 1;
            $code = 'CIC-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT);
            $id = (string) Str::uuid7();
            DB::table('programme_cycles')->insert([
                'id' => $id, 'code' => $code,
                'name' => trim((string) $name) !== '' ? trim((string) $name) : "Ciclo {$n}",
                'description' => trim((string) $description) !== '' ? trim((string) $description) : null,
                'starts_on' => $startsOn ?: CarbonImmutable::now(self::TIMEZONE)->toDateString(),
                'ends_on' => $endsOn ?: null, 'opened_by' => $actor->id, 'opened_at' => now(),
            ]);
            Audit::log($actor->id, 'CYCLE_OPENED', 'CYCLE', $id, ['code' => $code, 'starts_on' => $startsOn, 'ends_on' => $endsOn]);

            return ['success' => true, 'cycle_id' => $id, 'code' => $code];
        });
    }

    /**
     * Nome (a identificação do ciclo, ex.: "AA - Janela 1"), descrição e
     * datas. O código CIC-NN não muda. Num ciclo fechado só o nome e a
     * descrição mudam: as datas ficam como foram.
     *
     * @param  array{name?: string|null, description?: string|null, starts_on?: string|null, ends_on?: string|null}  $data
     * @return array{changed: bool}
     */
    public function update(User $actor, ?string $cycleId, array $data): array
    {
        return DB::transaction(function () use ($actor, $cycleId, $data): array {
            $cycle = DB::table('programme_cycles')->where('id', $cycleId)->lockForUpdate()->first()
                ?? throw new DomainRuleException('CYCLE_NOT_FOUND');
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw new DomainRuleException('CYCLE_NAME_REQUIRED');
            }
            $new = ['name' => $name, 'description' => trim((string) ($data['description'] ?? '')) ?: null];
            if ($cycle->status === 'OPEN') {
                $new['starts_on'] = ($data['starts_on'] ?? null) ?: $cycle->starts_on;
                $new['ends_on'] = ($data['ends_on'] ?? null) ?: null;
                if ($new['ends_on'] !== null && $new['ends_on'] < $new['starts_on']) {
                    throw new DomainRuleException('INVALID_PERIOD');
                }
            } elseif (($data['starts_on'] ?? $cycle->starts_on) != $cycle->starts_on || ($data['ends_on'] ?? $cycle->ends_on) != $cycle->ends_on) {
                throw new DomainRuleException('CYCLE_CLOSED_DATES');
            }

            $changes = [];
            foreach ($new as $field => $value) {
                if ((string) $cycle->{$field} !== (string) $value) {
                    $changes[$field] = ['old' => $cycle->{$field}, 'new' => $value];
                }
            }
            if ($changes === []) {
                return ['changed' => false];
            }
            DB::table('programme_cycles')->where('id', $cycle->id)->update($new);
            Audit::log($actor->id, 'CYCLE_UPDATED', 'CYCLE', $cycle->id, ['code' => $cycle->code, 'changes' => $changes]);

            return ['changed' => true];
        });
    }

    /**
     * Recarrega o cartão entregue de cada agregado escolhido (por ids ou por
     * CODIGO AF) com o valor do escalão do agregado. Sem commit, só mostra
     * quanto vai cada escalão, quem fica de fora e porquê.
     *
     * @param  list<string>|null  $householdIds
     * @param  list<string>|null  $householdCodes
     * @return array<string, mixed>
     */
    public function allocate(User $actor, ?string $cycleId, ?array $householdIds, ?array $householdCodes, bool $commit): array
    {
        return DB::transaction(function () use ($actor, $cycleId, $householdIds, $householdCodes, $commit): array {
            $cycle = $this->openCycle($cycleId);
            $ids = array_values(array_filter($householdIds ?? []));
            $codes = array_values(array_filter(array_map(fn ($c) => mb_strtoupper(trim((string) $c)), $householdCodes ?? [])));
            if ($ids === [] && $codes === []) {
                throw new DomainRuleException('CYCLE_HOUSEHOLDS_REQUIRED');
            }

            $households = DB::table('households as h')->join('voucher_tiers as vt', 'vt.id', '=', 'h.voucher_tier_id')
                ->where(fn ($q) => $q->whereIn('h.id', $ids)->orWhereIn('h.household_code', $codes))
                ->get(['h.id', 'h.household_code', 'h.status', 'vt.id as tier_id', 'vt.name as tier', 'vt.amount'])->keyBy('id');
            $known = $households->pluck('household_code')->map(fn ($c) => mb_strtoupper($c))->all();

            $picks = collect();
            foreach (array_unique(array_diff($codes, $known)) as $code) {
                $picks->push(['household_code' => $code, 'reason' => 'UNKNOWN_HOUSEHOLD']);
            }
            $inCycle = DB::table('cycle_households')->where('cycle_id', $cycle->id)->whereIn('household_id', $households->keys())->pluck('household_id')->flip();
            foreach ($households as $household) {
                $voucher = DB::table('vouchers')->where('household_id', $household->id)->where('status', 'ACTIVE')
                    ->orderByDesc('created_at')->first(['id', 'current_balance']);
                $reason = match (true) {
                    $inCycle->has($household->id) => 'ALREADY_IN_CYCLE',
                    $household->status !== 'ACTIVE' => 'HOUSEHOLD_INACTIVE',
                    $voucher === null => 'NO_DELIVERED_CARD',
                    (float) $voucher->current_balance > 0 => 'BALANCE_LEFT',
                    default => null,
                };
                $picks->push([
                    'household_id' => $household->id, 'household_code' => $household->household_code, 'reason' => $reason,
                    'voucher_id' => $voucher?->id, 'balance' => (float) ($voucher?->current_balance ?? 0),
                    'tier_id' => $household->tier_id, 'tier' => $household->tier, 'amount' => (float) $household->amount,
                ]);
            }

            $toAllocate = $picks->whereNull('reason');
            $leftOut = $picks->whereNotNull('reason');
            $result = [
                'cycle_id' => $cycle->id,
                'to_allocate' => $toAllocate->count(),
                'total_amount' => round($toAllocate->sum('amount'), 2),
                'by_tier' => $toAllocate->groupBy('tier')->map(fn (Collection $g, string $tier) => [
                    'tier' => $tier, 'amount' => $g->first()['amount'], 'households' => $g->count(), 'total_amount' => round($g->sum('amount'), 2),
                ])->sortByDesc('amount')->values()->all(),
                'left_out' => (object) $leftOut->countBy('reason')->all(),
                'left_out_rows' => $leftOut->sortBy(fn ($p) => $p['reason'].'|'.$p['household_code'])->take(500)
                    ->map(fn ($p) => ['household_code' => $p['household_code'], 'reason' => $p['reason']])->values()->all(),
            ];
            if (! $commit) {
                return [...$result, 'committed' => false];
            }

            // O saldo é zero (BALANCE_LEFT fica de fora): o valor carregado
            // passa a ser o valor inicial do cartão neste ciclo.
            foreach ($toAllocate as $pick) {
                DB::table('vouchers')->where('id', $pick['voucher_id'])->update([
                    'current_balance' => $pick['balance'] + $pick['amount'], 'initial_value' => $pick['amount'], 'updated_at' => now(),
                ]);
                $this->ledger->record($pick['voucher_id'], 'CYCLE_ALLOCATION', $pick['amount'], $pick['balance'] + $pick['amount'], [
                    'notes' => "{$cycle->code}: recarga do ciclo", 'created_by' => $actor->id,
                ]);
                DB::table('cycle_households')->insert([
                    'cycle_id' => $cycle->id, 'household_id' => $pick['household_id'], 'voucher_id' => $pick['voucher_id'],
                    'voucher_tier_id' => $pick['tier_id'], 'amount' => $pick['amount'], 'allocated_by' => $actor->id, 'allocated_at' => now(), 'source' => 'RECHARGE',
                ]);
            }
            Audit::log($actor->id, 'CYCLE_ALLOCATED', 'CYCLE', $cycle->id, [
                'code' => $cycle->code, 'allocated' => $toAllocate->count(), 'total_amount' => $result['total_amount'], 'by_tier' => $result['by_tier'], 'left_out' => $result['left_out'],
            ]);

            return [...$result, 'committed' => true, 'allocated' => $toAllocate->count()];
        });
    }

    /**
     * Fecha o ciclo: todo o saldo que resta expira. Sem commit mostra quem
     * ainda tem saldo e quanto.
     *
     * @return array<string, mixed>
     */
    public function close(User $actor, ?string $cycleId, bool $commit): array
    {
        return DB::transaction(function () use ($actor, $cycleId, $commit): array {
            $cycle = $this->openCycle($cycleId);
            $withBalance = DB::table('vouchers as v')
                ->join('households as h', 'h.id', '=', 'v.household_id')
                ->leftJoin('communities as c', 'c.id', '=', 'h.community_id')
                ->leftJoin('districts as d', 'd.id', '=', DB::raw('coalesce(h.district_id, c.district_id)'))
                ->where('v.current_balance', '>', 0)
                ->orderBy('d.name')->orderBy('c.name')->orderBy('h.household_code')
                ->get(['v.id', 'v.household_id', 'v.current_balance', 'v.distributed_at', 'v.issued_at', 'h.voucher_tier_id', 'h.household_code', 'h.representative_name', 'c.name as community', 'd.name as district']);
            $total = round($withBalance->sum(fn ($v) => (float) $v->current_balance), 2);

            if (! $commit) {
                return [
                    'committed' => false, 'households_with_balance' => $withBalance->count(), 'unused_amount' => $total,
                    'households' => $withBalance->map(fn ($v) => [
                        'household_code' => $v->household_code, 'representative_name' => $v->representative_name,
                        'community' => $v->community, 'district' => $v->district, 'balance' => $v->current_balance,
                    ])->values()->all(),
                ];
            }

            $lastSale = DB::table('transactions')->where('status', 'CONFIRMED')->max('confirmed_at');
            if ($lastSale !== null && CarbonImmutable::parse($lastSale)->gt(now()->subMinutes(15))) {
                throw new DomainRuleException('CYCLE_RECENT_SALES', CarbonImmutable::parse($lastSale)->addMinutes(15)->setTimezone(self::TIMEZONE)->format('H:i'));
            }

            foreach ($withBalance as $voucher) {
                DB::table('vouchers')->where('id', $voucher->id)->lockForUpdate()->first();
                $this->ledger->record($voucher->id, 'CYCLE_EXPIRY', -$voucher->current_balance, 0, [
                    'notes' => "{$cycle->code}: saldo não utilizado no fim do ciclo", 'created_by' => $actor->id,
                ]);
                DB::table('vouchers')->where('id', $voucher->id)->update(['current_balance' => 0, 'updated_at' => now()]);
                $updated = DB::table('cycle_households')->where('cycle_id', $cycle->id)->where('household_id', $voucher->household_id)
                    ->update(['unused_amount' => DB::raw('coalesce(unused_amount, 0) + '.(float) $voucher->current_balance)]);
                if ($updated === 0) {
                    DB::table('cycle_households')->insert([
                        'cycle_id' => $cycle->id, 'household_id' => $voucher->household_id, 'voucher_id' => $voucher->id,
                        'voucher_tier_id' => $voucher->voucher_tier_id, 'amount' => $voucher->current_balance, 'allocated_at' => $voucher->distributed_at ?? $voucher->issued_at ?? now(),
                        'source' => 'DELIVERY', 'unused_amount' => $voucher->current_balance,
                    ]);
                }
            }
            DB::table('cycle_households')->where('cycle_id', $cycle->id)->whereNull('unused_amount')->update(['unused_amount' => 0]);
            DB::table('programme_cycles')->where('id', $cycle->id)->update([
                'status' => 'CLOSED', 'closed_at' => now(), 'closed_by' => $actor->id,
                'ends_on' => $cycle->ends_on ?? CarbonImmutable::now(self::TIMEZONE)->toDateString(),
            ]);
            Audit::log($actor->id, 'CYCLE_CLOSED', 'CYCLE', $cycle->id, [
                'code' => $cycle->code, 'households_with_balance' => $withBalance->count(), 'unused_amount' => $total,
            ]);

            return ['committed' => true, 'households_with_balance' => $withBalance->count(), 'unused_amount' => $total];
        });
    }

    /**
     * Ciclos, mais recentes primeiro; um Oficial MEAL conta só os agregados
     * da sua província.
     *
     * @return list<array<string, mixed>>
     */
    public function list(User $viewer): array
    {
        return DB::table('programme_cycles')->orderByDesc('opened_at')->get()->map(function (object $cycle) use ($viewer): array {
            $members = $this->scopedMembers($viewer, $cycle->id);

            return [
                'cycle_id' => $cycle->id, 'code' => $cycle->code, 'name' => $cycle->name, 'description' => $cycle->description,
                'status' => $cycle->status, 'starts_on' => $cycle->starts_on, 'ends_on' => $cycle->ends_on,
                'opened_at' => $cycle->opened_at, 'closed_at' => $cycle->closed_at,
                'households' => $members->count(), 'allocated' => round($members->sum(fn ($m) => (float) $m->amount), 2),
            ];
        })->all();
    }

    /**
     * Relatório do ciclo: agregados, vendas, por distrito, género,
     * agrodealer e produto, e quem ficou com saldo por usar.
     *
     * @return array<string, mixed>
     */
    public function summary(User $viewer, ?string $cycleId): array
    {
        $cycle = DB::table('programme_cycles')->where('id', $cycleId)->first() ?? throw new DomainRuleException('CYCLE_NOT_FOUND');
        $closed = $cycle->status === 'CLOSED';

        $members = $this->scopedMembers($viewer, $cycle->id)->map(function (object $m) use ($closed): object {
            $m->unused = $closed
                ? (float) ($m->unused_amount ?? 0)
                : (float) DB::table('vouchers')->where('household_id', $m->household_id)->sum('current_balance');

            return $m;
        });

        $sales = DB::table('transactions as t')
            ->join('agrodealers as a', 'a.id', '=', 't.agrodealer_id')
            ->leftJoin('districts as ad', 'ad.id', '=', 'a.district_id')
            ->where('t.status', 'CONFIRMED')->where('t.confirmed_at', '>=', $cycle->opened_at)
            ->when($cycle->closed_at, fn ($q) => $q->where('t.confirmed_at', '<', $cycle->closed_at))
            ->when($viewer->isProvinceScoped(), fn ($q) => $this->scope->apply($q, $viewer, DB::raw('coalesce(a.province_id, ad.province_id)')))
            ->get(['t.id', 't.household_id', 't.agrodealer_id', 't.voucher_amount', 't.copayment_amount', 't.total_amount', 't.discount_amount', 'a.name as agrodealer', 'a.code as agrodealer_code', 'ad.name as agrodealer_district']);
        $buyers = $sales->groupBy('household_id')->map(fn (Collection $s) => $s->sum(fn ($t) => (float) $t->voucher_amount));
        $sum = fn (Collection $rows, string $field): float => round($rows->sum(fn ($r) => (float) $r->{$field}), 2);

        $items = DB::table('transaction_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->whereIn('i.transaction_id', $sales->pluck('id'))
            ->groupBy('p.id', 'p.name', 'p.code', 'p.category', 'p.unit')
            ->selectRaw('p.name as product, p.code, p.category, p.unit, sum(i.quantity) as quantity, sum(i.line_total) as total_amount')
            ->orderByDesc('total_amount')->get();

        return [
            'cycle' => $cycle,
            'totals' => [
                'households' => $members->count(),
                'recharged' => $members->where('source', 'RECHARGE')->count(),
                'delivered' => $members->where('source', 'DELIVERY')->count(),
                'allocated' => $sum($members, 'amount'),
                'households_bought' => $members->filter(fn ($m) => $buyers->has($m->household_id))->count(),
                'households_with_unused' => $members->filter(fn ($m) => $m->unused > 0)->count(),
                'unused' => $sum($members, 'unused'),
                'women' => $members->where('representative_sex', 'F')->count(),
                'men' => $members->where('representative_sex', 'M')->count(),
            ],
            'sales' => [
                'transactions' => $sales->count(), 'voucher_amount' => $sum($sales, 'voucher_amount'),
                'copayment_amount' => $sum($sales, 'copayment_amount'), 'total_amount' => $sum($sales, 'total_amount'),
                'discount_amount' => $sum($sales, 'discount_amount'),
            ],
            'by_district' => $members->groupBy(fn ($m) => $m->province.'|'.$m->district)->map(fn (Collection $g) => [
                'province' => $g->first()->province, 'district' => $g->first()->district, 'households' => $g->count(),
                'households_bought' => $g->filter(fn ($m) => $buyers->has($m->household_id))->count(),
                'allocated' => $sum($g, 'amount'), 'spent' => round($g->sum(fn ($m) => $buyers->get($m->household_id, 0)), 2), 'unused' => $sum($g, 'unused'),
            ])->sortBy(fn ($x) => $x['province'].'|'.$x['district'])->values()->all(),
            'by_tier' => $members->groupBy('tier')->map(fn (Collection $g, string $tier) => [
                'tier' => $tier, 'households' => $g->count(),
                'households_bought' => $g->filter(fn ($m) => $buyers->has($m->household_id))->count(),
                'allocated' => $sum($g, 'amount'), 'spent' => round($g->sum(fn ($m) => $buyers->get($m->household_id, 0)), 2), 'unused' => $sum($g, 'unused'),
            ])->sortByDesc('allocated')->values()->all(),
            'by_gender' => $members->groupBy(fn ($m) => $m->representative_sex ?? '-')->map(fn (Collection $g, string $sex) => [
                'sex' => $sex, 'households' => $g->count(),
                'households_bought' => $g->filter(fn ($m) => $buyers->has($m->household_id))->count(),
                'spent' => round($g->sum(fn ($m) => $buyers->get($m->household_id, 0)), 2),
            ])->sortKeys()->values()->all(),
            'by_agrodealer' => $sales->groupBy('agrodealer_id')->map(fn (Collection $g) => [
                'agrodealer' => $g->first()->agrodealer, 'code' => $g->first()->agrodealer_code, 'district' => $g->first()->agrodealer_district,
                'transactions' => $g->count(), 'voucher_amount' => $sum($g, 'voucher_amount'),
                'copayment_amount' => $sum($g, 'copayment_amount'), 'total_amount' => $sum($g, 'total_amount'),
            ])->sortByDesc('total_amount')->values()->all(),
            'by_product' => $items->map(fn ($i) => [...(array) $i, 'quantity' => (float) $i->quantity, 'total_amount' => (float) $i->total_amount])->all(),
            'substitutes' => DB::table('vouchers')->whereIn('household_id', $members->pluck('household_id'))
                ->whereNotNull('received_by_name')->where('distributed_at', '>=', $cycle->opened_at)
                ->when($cycle->closed_at, fn ($q) => $q->where('distributed_at', '<', $cycle->closed_at))->count(),
            'with_unused' => $members->filter(fn ($m) => $m->unused > 0)
                ->sortBy(fn ($m) => $m->district.'|'.$m->community.'|'.$m->household_code)
                ->map(fn ($m) => [
                    'household_code' => $m->household_code, 'representative_name' => $m->representative_name,
                    'community' => $m->community, 'district' => $m->district, 'unused' => $m->unused,
                ])->values()->all(),
        ];
    }

    private function openCycle(?string $cycleId): object
    {
        $cycle = DB::table('programme_cycles')->where('id', $cycleId)->lockForUpdate()->first()
            ?? throw new DomainRuleException('CYCLE_NOT_FOUND');
        if ($cycle->status !== 'OPEN') {
            throw new DomainRuleException('CYCLE_CLOSED');
        }

        return $cycle;
    }

    /**
     * Agregados do ciclo dentro da província de um Oficial MEAL.
     *
     * @return Collection<int, object>
     */
    private function scopedMembers(User $viewer, string $cycleId): Collection
    {
        $query = $this->households->baseQuery($viewer)
            ->join('cycle_households as ch', 'ch.household_id', '=', 'h.id')
            ->join('voucher_tiers as vt', 'vt.id', '=', DB::raw('coalesce(ch.voucher_tier_id, h.voucher_tier_id)'))
            ->where('ch.cycle_id', $cycleId)
            ->select(['vt.name as tier', 'ch.household_id', 'ch.amount', 'ch.source', 'ch.voucher_id', 'ch.unused_amount', 'h.household_code', 'h.representative_name', 'h.representative_sex', 'c.name as community', 'd.name as district', 'p.name as province']);

        return $query->get();
    }
}
