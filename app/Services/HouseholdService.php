<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Household;
use App\Models\User;
use App\Models\VoucherTier;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Registo, edição, estado, eliminação, ficha e histórico de um agregado.
 *
 * Substitui create_household, update_household, delete_household,
 * set_household_status, get_household_detail e get_household_history
 * (20261008113600, 20261008114200, 20261008114600). Os erros saem como
 * InvalidArgumentException com a mensagem para o utilizador.
 */
class HouseholdService
{
    /** Campos que se podem alterar (o CODIGO AF é fixo). */
    public const EDITABLE = ['first_name', 'last_name', 'sex', 'birth_date', 'relationship', 'community_id', 'voucher_tier_id'];

    /** Acções da auditoria que formam o histórico do agregado. */
    private const HISTORY_ACTIONS = ['HOUSEHOLD_CREATED', 'HOUSEHOLD_UPDATED', 'HOUSEHOLD_DEACTIVATED', 'HOUSEHOLD_REACTIVATED'];

    /** Colunas comparadas para gravar e auditar só o que mudou. */
    private const TRACKED = [
        'representative_name', 'representative_first_name', 'representative_last_name', 'representative_sex',
        'representative_birth_date', 'representative_relationship', 'community_id', 'locality_id',
        'administrative_post_id', 'district_id', 'province_id', 'voucher_tier_id',
    ];

    public function __construct(private VoucherTierService $tiers) {}

    /**
     * Sem escalão, o agregado fica no escalão padrão.
     *
     * @param  array<string, mixed>  $data  household_code, first_name, last_name, sex, birth_date, relationship, community_id, voucher_tier_id
     */
    public function create(User $actor, array $data): Household
    {
        $code = HouseholdInput::householdCode($data['household_code'] ?? '');
        if (! HouseholdInput::isValidHouseholdCode($code)) {
            throw new InvalidArgumentException('O CODIGO AF tem o formato 1234AF000001.');
        }
        if (Household::query()->where('household_code', $code)->exists()) {
            throw new InvalidArgumentException('Já existe um agregado com este CODIGO AF.');
        }
        $first = HouseholdInput::clean($data['first_name'] ?? null);
        $last = HouseholdInput::clean($data['last_name'] ?? null);
        $relationship = HouseholdInput::clean($data['relationship'] ?? null);
        if ($first === null || $last === null || $relationship === null) {
            throw new InvalidArgumentException('Preencha nome, apelido e grau de parentesco.');
        }

        $tier = $this->tiers->assignable($data['voucher_tier_id'] ?? null);

        return DB::transaction(function () use ($actor, $data, $code, $first, $last, $relationship, $tier): Household {
            $household = Household::query()->create([
                'household_code' => $code,
                'representative_name' => "{$first} {$last}",
                'representative_first_name' => $first,
                'representative_last_name' => $last,
                'representative_sex' => $this->gender($data['sex'] ?? null),
                'representative_birth_date' => $this->birthDate($data['birth_date'] ?? null),
                'representative_relationship' => $relationship,
                ...$this->location($data['community_id'] ?? null),
                'voucher_tier_id' => $tier->id,
                'status' => RecordStatus::Active,
            ]);

            $this->audit($actor, 'HOUSEHOLD_CREATED', $household->id, [
                'household_code' => $code,
                'representative_name' => $household->representative_name,
                'community_id' => $household->community_id,
                'voucher_tier' => $tier->name,
            ]);

            return $household;
        });
    }

    /**
     * Grava só o que mudou, com o valor antigo e o novo na auditoria.
     *
     * @param  array<string, mixed>  $changes  chaves de EDITABLE
     * @return array{changed: bool, changes: array<string, array{old: mixed, new: mixed}>}
     */
    public function update(User $actor, Household $household, array $changes, string $reason): array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Indique o motivo da alteração.');
        }
        if ($changes === []) {
            throw new InvalidArgumentException('Não há alterações para guardar.');
        }
        if (array_diff(array_keys($changes), self::EDITABLE) !== []) {
            throw new InvalidArgumentException('Esse dado não pode ser alterado (o CODIGO AF é fixo).');
        }

        return DB::transaction(function () use ($actor, $household, $changes, $reason): array {
            $household = Household::query()->lockForUpdate()->findOrFail($household->id);
            $before = $this->tracked($household);
            $new = [];

            foreach (['first_name' => 'representative_first_name', 'last_name' => 'representative_last_name', 'relationship' => 'representative_relationship'] as $key => $column) {
                if (array_key_exists($key, $changes)) {
                    $new[$column] = HouseholdInput::clean($changes[$key])
                        ?? throw new InvalidArgumentException('Preencha nome, apelido e grau de parentesco.');
                }
            }
            if (array_key_exists('first_name', $changes) || array_key_exists('last_name', $changes)) {
                $new['representative_name'] = trim(($new['representative_first_name'] ?? $household->representative_first_name)
                    .' '.($new['representative_last_name'] ?? $household->representative_last_name));
            }
            if (array_key_exists('sex', $changes)) {
                $new['representative_sex'] = $this->gender($changes['sex']);
            }
            if (array_key_exists('birth_date', $changes)) {
                $new['representative_birth_date'] = $this->birthDate($changes['birth_date']);
            }
            if (array_key_exists('community_id', $changes)) {
                $new = [...$new, ...$this->location($changes['community_id'])];
            }
            // O novo escalão vale a partir da próxima ativação ou recarga.
            if (array_key_exists('voucher_tier_id', $changes)) {
                $new['voucher_tier_id'] = $this->tiers->assignable((string) $changes['voucher_tier_id'] ?: 'none')->id;
            }

            $household->fill($new);
            $after = $this->tracked($household);
            $diff = [];
            foreach (self::TRACKED as $column) {
                if ($before[$column] !== $after[$column]) {
                    $diff[$column] = ['old' => $before[$column], 'new' => $after[$column]];
                }
            }
            if ($diff === []) {
                return ['changed' => false, 'changes' => []];
            }
            if (isset($diff['voucher_tier_id'])) {
                $names = VoucherTier::query()->whereIn('id', $diff['voucher_tier_id'])->pluck('name', 'id');
                $diff['voucher_tier'] = ['old' => $names[$diff['voucher_tier_id']['old']] ?? null, 'new' => $names[$diff['voucher_tier_id']['new']] ?? null];
            }

            $household->save();
            $this->audit($actor, 'HOUSEHOLD_UPDATED', $household->id, [
                'household_code' => $household->household_code, 'reason' => $reason, 'changes' => $diff,
            ]);

            return ['changed' => true, 'changes' => $diff];
        });
    }

    /**
     * Desactiva (deixa de poder comprar; o saldo fica guardado) ou reactiva.
     *
     * @return bool verdadeiro quando o estado mudou
     */
    public function setStatus(User $actor, Household $household, RecordStatus $status, string $reason): bool
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Indique o motivo.');
        }

        return DB::transaction(function () use ($actor, $household, $status, $reason): bool {
            $household = Household::query()->lockForUpdate()->findOrFail($household->id);
            if ($household->status === $status) {
                return false;
            }

            $household->update([
                'status' => $status,
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'status_changed_by' => $actor->id,
            ]);
            $this->audit($actor, $status === RecordStatus::Inactive ? 'HOUSEHOLD_DEACTIVATED' : 'HOUSEHOLD_REACTIVATED', $household->id, [
                'household_code' => $household->household_code, 'reason' => $reason,
            ]);

            return true;
        });
    }

    /**
     * Elimina um registo feito por engano. Um agregado que já teve cartão ou
     * compras nunca se elimina (desactiva-se). Fica uma cópia na auditoria.
     */
    public function delete(User $actor, Household $household, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new InvalidArgumentException('Indique o motivo da alteração.');
        }

        DB::transaction(function () use ($actor, $household, $reason): void {
            $household = Household::query()->lockForUpdate()->findOrFail($household->id);
            if ($this->hasCardsOrSales($household->id)) {
                throw new InvalidArgumentException('Este agregado já teve cartão: não pode ser eliminado. Use Desativar agregado.');
            }

            $copy = $household->toArray();
            $household->delete();
            $this->audit($actor, 'HOUSEHOLD_DELETED', $household->id, ['reason' => $reason, 'household' => $copy]);
        });
    }

    /**
     * Ficha do agregado, com a forma da antiga get_household_detail.
     *
     * @return array<string, mixed>
     */
    public function detail(Household $household, HouseholdSearchService $search, User $viewer): array
    {
        // A visibilidade foi verificada por quem chama (Policy / isVisible).
        $row = $search->locationQuery()
            ->leftJoin('household_import_batches as b', 'b.id', '=', 'h.import_batch_id')
            ->join('voucher_tiers as vt', 'vt.id', '=', 'h.voucher_tier_id')
            ->where('h.id', $household->id)
            ->first([
                'vt.name as tier_name', 'vt.amount as tier_amount',
                'h.*', 'c.name as community_name', 'l.name as locality_name', 'a.name as post_name',
                'd.name as district_name', 'p.name as province_name', 'b.id as batch_id', 'b.file_name', 'b.created_at as imported_at',
            ]);

        return [
            'household_id' => $row->id,
            'household_code' => $row->household_code,
            'representative_name' => $row->representative_name,
            'first_name' => $row->representative_first_name,
            'last_name' => $row->representative_last_name,
            'gender' => $row->representative_sex,
            'birth_date' => $row->representative_birth_date,
            'relationship' => $row->representative_relationship,
            'voucher_tier_id' => $row->voucher_tier_id,
            'voucher_tier' => $row->tier_name,
            'voucher_tier_amount' => (float) $row->tier_amount,
            'status' => $row->status,
            'status_reason' => $row->status_reason,
            'status_changed_at' => $row->status_changed_at,
            'created_at' => $row->created_at,
            'community' => $row->community_name,
            'locality' => $row->locality_name,
            'administrative_post' => $row->post_name,
            'district' => $row->district_name,
            'province' => $row->province_name,
            'import' => $row->batch_id === null ? null : ['file_name' => $row->file_name, 'imported_at' => $row->imported_at],
            'cards' => DB::table('vouchers as v')
                ->leftJoin('card_batches as cb', 'cb.id', '=', 'v.card_batch_id')
                ->leftJoin('vouchers as old', 'old.id', '=', 'v.replaced_voucher_id')
                ->where('v.household_id', $row->id)
                ->orderByRaw("v.status in ('ACTIVE', 'PENDING') desc")->orderByDesc('v.created_at')
                ->get([
                    'v.voucher_code', 'v.status', 'v.initial_value', 'v.current_balance', 'v.issued_at', 'v.distributed_at',
                    'v.received_by_name', 'v.received_by_relationship', 'cb.batch_code', 'old.voucher_code as replaced_voucher_code', 'v.replacement_reason',
                ])->all(),
            'sales' => DB::table('transactions as t')
                ->join('vouchers as v', 'v.id', '=', 't.voucher_id')
                ->leftJoin('agrodealers as ag', 'ag.id', '=', 't.agrodealer_id')
                ->where('v.household_id', $row->id)->whereIn('t.status', ['CONFIRMED', 'REVERSED'])
                ->orderByDesc('t.confirmed_at')->limit(50)
                ->get([
                    't.transaction_code', 't.confirmed_at', 'ag.name as agrodealer', 'v.voucher_code', 't.total_amount',
                    't.voucher_amount', 't.copayment_amount', 't.status',
                ])->all(),
        ];
    }

    /**
     * Alterações do agregado, mais recentes primeiro.
     *
     * @return list<array{action: string, at: string, by: string|null, reason: string|null, changes: array<string, mixed>|null}>
     */
    public function history(Household $household): array
    {
        return AuditLog::query()
            ->with('actor:id,full_name')
            ->where('entity_id', $household->id)
            ->whereIn('action', self::HISTORY_ACTIONS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditLog $log): array => [
                'action' => $log->action,
                'at' => $log->created_at->toIso8601String(),
                'by' => $log->actor?->full_name,
                'reason' => $log->metadata['reason'] ?? $log->metadata['status_reason'] ?? null,
                'changes' => $log->metadata['changes'] ?? null,
            ])
            ->all();
    }

    /**
     * Os cinco níveis de localização a partir da comunidade.
     *
     * @return array{community_id: string, locality_id: string, administrative_post_id: string, district_id: string, province_id: string}
     */
    private function location(mixed $communityId): array
    {
        $community = is_string($communityId) && $communityId !== ''
            ? Community::query()->with(['locality.administrativePost', 'district'])->find($communityId)
            : null;
        if ($community === null) {
            throw new InvalidArgumentException('Bairro não encontrado.');
        }

        return [
            'community_id' => $community->id,
            'locality_id' => $community->locality_id,
            'administrative_post_id' => $community->locality->administrative_post_id,
            'district_id' => $community->district_id,
            'province_id' => $community->district->province_id,
        ];
    }

    private function gender(mixed $value): string
    {
        return HouseholdInput::gender($value) ?? throw new InvalidArgumentException('Género inválido: use Feminino ou Masculino.');
    }

    private function birthDate(mixed $value): string
    {
        $date = HouseholdInput::date($value) ?? throw new InvalidArgumentException('Data inválida.');
        if (! HouseholdInput::isPlausibleBirthDate($date)) {
            throw new InvalidArgumentException('A data de nascimento tem de ser entre 1900 e hoje.');
        }

        return $date->toDateString();
    }

    /**
     * @return array<string, string|null>
     */
    private function tracked(Household $household): array
    {
        $values = [];
        foreach (self::TRACKED as $column) {
            $value = $household->getAttribute($column);
            $values[$column] = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : ($value === null ? null : (string) $value);
        }

        return $values;
    }

    /**
     * Um agregado que já teve cartão ou compras nunca se elimina.
     */
    private function hasCardsOrSales(string $householdId): bool
    {
        return DB::table('vouchers')->where('household_id', $householdId)->exists()
            || DB::table('transactions')->where('household_id', $householdId)->exists();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(User $actor, string $action, string $householdId, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'entity_type' => 'HOUSEHOLD',
            'entity_id' => $householdId,
            'metadata' => $metadata,
        ]);
    }
}
