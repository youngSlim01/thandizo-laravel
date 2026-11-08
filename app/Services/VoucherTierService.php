<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Models\VoucherTier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Escalões do voucher, geridos pelo gestor MEAL e pelo administrador do
 * sistema na Configuração.
 *
 * Regras: dois escalões activos nunca têm o mesmo valor (a importação
 * encontra o escalão pelo valor da coluna VALOR); um escalão nunca se apaga,
 * desactiva-se, e só quando já não tem agregados activos; há sempre um
 * escalão padrão, activo, para os registos sem valor. Mudar o valor só
 * afecta as próximas activações e recargas: o que já está nos cartões fica.
 */
class VoucherTierService
{
    /**
     * Escalões, activos primeiro e por valor, com o número de agregados
     * activos.
     *
     * @return Collection<int, VoucherTier>
     */
    public function list(): Collection
    {
        return VoucherTier::query()
            ->withCount(['households' => fn ($query) => $query->where('status', RecordStatus::Active)])
            ->orderBy('status')->orderByDesc('amount')->orderBy('name')
            ->get();
    }

    public function default(): VoucherTier
    {
        return VoucherTier::query()->where('is_default', true)->firstOrFail();
    }

    /**
     * Escalão activo com este valor, ou null.
     */
    public function activeWithAmount(float $amount): ?VoucherTier
    {
        return VoucherTier::query()->where('status', RecordStatus::Active)->where('amount', $amount)->first();
    }

    /**
     * Escalão activo para um agregado (por id); sem id, o padrão.
     */
    public function assignable(?string $tierId): VoucherTier
    {
        if ($tierId === null || $tierId === '') {
            return $this->default();
        }
        $tier = VoucherTier::query()->find($tierId) ?? throw new DomainRuleException('TIER_NOT_FOUND');
        if ($tier->status !== RecordStatus::Active) {
            throw new DomainRuleException('TIER_INACTIVE');
        }

        return $tier;
    }

    /**
     * @param  array{name?: string|null, amount?: mixed, description?: string|null}  $data
     */
    public function create(User $actor, array $data): VoucherTier
    {
        return DB::transaction(function () use ($actor, $data): VoucherTier {
            VoucherTier::query()->lockForUpdate()->get(['id']);
            $name = $this->name($data['name'] ?? null);
            $amount = $this->amount($data['amount'] ?? null);
            $this->ensureNameFree($name);
            $this->ensureAmountFree($amount);

            $tier = VoucherTier::query()->create([
                'name' => $name, 'amount' => $amount, 'description' => $this->description($data['description'] ?? null),
                'status' => RecordStatus::Active, 'created_by' => $actor->id,
            ]);
            Audit::log($actor->id, 'VOUCHER_TIER_CREATED', 'VOUCHER_TIER', $tier->id, ['name' => $name, 'amount' => $amount]);

            return $tier;
        });
    }

    /**
     * Nome, valor e descrição. Fica na auditoria o antes e o depois.
     *
     * @param  array{name?: string|null, amount?: mixed, description?: string|null}  $data
     */
    public function update(User $actor, VoucherTier $tier, array $data): VoucherTier
    {
        return DB::transaction(function () use ($actor, $tier, $data): VoucherTier {
            VoucherTier::query()->lockForUpdate()->get(['id']);
            $tier->refresh();
            $name = $this->name($data['name'] ?? null);
            $amount = $this->amount($data['amount'] ?? null);
            $this->ensureNameFree($name, $tier->id);
            if ($tier->status === RecordStatus::Active) {
                $this->ensureAmountFree($amount, $tier->id);
            }

            $before = ['name' => $tier->name, 'amount' => (float) $tier->amount, 'description' => $tier->description];
            $tier->fill(['name' => $name, 'amount' => $amount, 'description' => $this->description($data['description'] ?? null)]);
            $after = ['name' => $tier->name, 'amount' => (float) $tier->amount, 'description' => $tier->description];
            if ($before === $after) {
                return $tier;
            }
            $tier->save();
            $changes = [];
            foreach ($after as $key => $value) {
                if ($before[$key] !== $value) {
                    $changes[$key] = ['old' => $before[$key], 'new' => $value];
                }
            }
            Audit::log($actor->id, 'VOUCHER_TIER_UPDATED', 'VOUCHER_TIER', $tier->id, ['changes' => $changes]);

            return $tier;
        });
    }

    public function setStatus(User $actor, VoucherTier $tier, RecordStatus $status): void
    {
        DB::transaction(function () use ($actor, $tier, $status): void {
            VoucherTier::query()->lockForUpdate()->get(['id']);
            $tier->refresh();
            if ($tier->status === $status) {
                return;
            }
            if ($status === RecordStatus::Inactive) {
                if ($tier->is_default) {
                    throw new DomainRuleException('TIER_IS_DEFAULT');
                }
                $households = $tier->households()->where('status', RecordStatus::Active)->count();
                if ($households > 0) {
                    throw new DomainRuleException('TIER_IN_USE', (string) $households);
                }
            } else {
                $this->ensureAmountFree((float) $tier->amount, $tier->id);
            }
            $tier->update(['status' => $status]);
            Audit::log($actor->id, $status === RecordStatus::Active ? 'VOUCHER_TIER_REACTIVATED' : 'VOUCHER_TIER_DEACTIVATED', 'VOUCHER_TIER', $tier->id, ['name' => $tier->name]);
        });
    }

    /**
     * O escalão dos agregados registados ou importados sem valor.
     */
    public function makeDefault(User $actor, VoucherTier $tier): void
    {
        DB::transaction(function () use ($actor, $tier): void {
            VoucherTier::query()->lockForUpdate()->get(['id']);
            $tier->refresh();
            if ($tier->status !== RecordStatus::Active) {
                throw new DomainRuleException('TIER_INACTIVE');
            }
            if ($tier->is_default) {
                return;
            }
            $previous = VoucherTier::query()->where('is_default', true)->first();
            $previous?->update(['is_default' => false]);
            $tier->update(['is_default' => true]);
            Audit::log($actor->id, 'VOUCHER_TIER_DEFAULT_CHANGED', 'VOUCHER_TIER', $tier->id, ['from' => $previous?->name, 'to' => $tier->name]);
        });
    }

    private function name(mixed $value): string
    {
        return HouseholdInput::clean($value) ?? throw new DomainRuleException('TIER_NAME_REQUIRED');
    }

    private function description(mixed $value): ?string
    {
        return HouseholdInput::clean($value);
    }

    /**
     * Valor em meticais, maior que zero e com no máximo dois decimais.
     */
    private function amount(mixed $value): float
    {
        $text = str_replace([' ', ','], ['', '.'], trim((string) $value));
        if (preg_match('/^\d+(\.\d{1,2})?$/', $text) !== 1 || (float) $text <= 0) {
            throw new DomainRuleException('TIER_AMOUNT_INVALID');
        }

        return round((float) $text, 2);
    }

    private function ensureNameFree(string $name, ?string $exceptId = null): void
    {
        $taken = VoucherTier::query()->where('name', $name)->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw new DomainRuleException('TIER_NAME_TAKEN');
        }
    }

    private function ensureAmountFree(float $amount, ?string $exceptId = null): void
    {
        $other = VoucherTier::query()->where('status', RecordStatus::Active)->where('amount', $amount)
            ->when($exceptId, fn ($q) => $q->whereKeyNot($exceptId))->value('name');
        if ($other !== null) {
            throw new DomainRuleException('TIER_AMOUNT_TAKEN', $other);
        }
    }
}
