<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A pessoa escolhe a sua própria palavra-passe (primeiro acesso, perfil ou
 * recuperação por email). Substitui mark_password_changed.
 */
class PasswordService
{
    /**
     * Verdadeiro quando a nova palavra-passe é igual à actual (antigo SAME_PASSWORD).
     */
    public function isCurrentPassword(User $user, string $password): bool
    {
        return Hash::check($password, $user->password);
    }

    /**
     * Grava a nova palavra-passe, retira a obrigação de a mudar e audita.
     */
    public function change(User $user, string $password, string $via): void
    {
        DB::transaction(function () use ($user, $password, $via): void {
            $user->password = $password;
            $user->must_change_password = false;
            $user->password_changed_at = now();
            $user->setRememberToken(Str::random(60));
            $user->save();

            AuditLog::query()->create([
                'actor_id' => $user->id,
                'action' => 'PASSWORD_CHANGED',
                'entity_type' => 'APP_USER',
                'entity_id' => $user->id,
                'metadata' => ['via' => $via],
            ]);
        });
    }
}
