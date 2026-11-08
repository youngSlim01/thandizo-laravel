<?php

namespace App\Services;

use App\Models\ClientProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Cliente desta instalação, editado pelo administrador em Configuração e
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
            $profile->fill([
                'name' => $this->clean($data['name'] ?? null),
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

    private function clean(?string $value): ?string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value));

        return $value === '' ? null : $value;
    }
}
