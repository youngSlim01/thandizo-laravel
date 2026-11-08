<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\DomainRuleException;
use App\Models\ClientProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cliente desta instalação, editado em Configuração (o programa e a frase
 * pelo gestor MEAL; o nome da organização só pelo administrador) e
 * mostrado junto do nome THANDIZO (ecrãs, recibo, cartão, PDFs e email).
 */
class ClientProfileService
{
    /**
     * Organização, programa e frase do cliente; vazios ficam a null.
     *
     * @return array{client: string|null, programme: string|null, tagline: string|null}
     */
    public function current(): array
    {
        $profile = ClientProfile::query()->first();

        return [
            'client' => $profile?->name,
            'programme' => $profile?->programme,
            'tagline' => $profile?->tagline,
        ];
    }

    /**
     * @param  array{name?: string|null, programme?: string|null, tagline?: string|null}  $data
     */
    public function update(User $actor, array $data): ClientProfile
    {
        return DB::transaction(function () use ($actor, $data): ClientProfile {
            $profile = ClientProfile::query()->lockForUpdate()->first() ?? new ClientProfile;
            $before = $profile->only(['name', 'programme', 'tagline']);
            $name = $this->clean($data['name'] ?? null);
            // O nome da organização é a identidade do cliente: só o administrador o muda.
            if ($name !== $profile->name && $actor->role !== UserRole::SystemAdmin) {
                throw new DomainRuleException('CLIENT_NAME_NOT_ALLOWED');
            }
            $profile->fill([
                'name' => $name,
                'programme' => $this->clean($data['programme'] ?? null),
                'tagline' => $this->clean($data['tagline'] ?? null),
                'updated_by' => $actor->id,
            ])->save();

            Audit::log($actor->id, 'CLIENT_PROFILE_UPDATED', 'CLIENT_PROFILE', $profile->id, [
                'before' => $before,
                'after' => $profile->only(['name', 'programme', 'tagline']),
            ]);

            return $profile;
        });
    }

    /** Prazo por omissão para validar uma reconciliação, em dias. */
    public const DEFAULT_SETTLEMENT_DEADLINE_DAYS = 15;

    /**
     * Dias que as Finanças têm para validar uma reconciliação depois de a
     * receberem; passado o prazo, o painel das Finanças avisa.
     */
    public function settlementDeadlineDays(): int
    {
        return (int) (ClientProfile::query()->value('settlement_deadline_days') ?? self::DEFAULT_SETTLEMENT_DEADLINE_DAYS);
    }

    public function updateSettlementDeadline(User $actor, int $days): void
    {
        if ($days < 1 || $days > 90) {
            throw new DomainRuleException('SETTLEMENT_DEADLINE_INVALID');
        }
        DB::transaction(function () use ($actor, $days): void {
            $profile = ClientProfile::query()->lockForUpdate()->first() ?? new ClientProfile;
            $before = $profile->settlement_deadline_days ?? self::DEFAULT_SETTLEMENT_DEADLINE_DAYS;
            $profile->fill(['settlement_deadline_days' => $days, 'updated_by' => $actor->id])->save();
            Audit::log($actor->id, 'SETTLEMENT_DEADLINE_UPDATED', 'CLIENT_PROFILE', $profile->id, ['before' => $before, 'after' => $days]);
        });
    }

    private function clean(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));

        return $value === '' ? null : $value;
    }
}
