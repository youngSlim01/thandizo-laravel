<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Contas e acessos.
 *
 * Substitui admin_set_app_user, admin_list_app_users,
 * admin_require_password_change e as rotas /api/admin/users do Next.js. A
 * coerência papel ↔ agrodealer ↔ província é verificada nos pedidos
 * (StoreUserRequest, UpdateUserAccessRequest) e garantida aqui ao gravar.
 */
class UserAdminService
{
    /**
     * Todas as contas por nome, com agrodealer e província.
     *
     * @return Collection<int, User>
     */
    public function list(): Collection
    {
        return User::query()
            ->with(['agrodealer:id,name,code', 'province:id,name'])
            ->orderBy('full_name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cria uma conta activa com uma palavra-passe temporária: no primeiro
     * acesso a pessoa tem de escolher outra.
     *
     * @param  array{email: string, full_name: string, username?: string|null, role: string, agrodealer_id?: string|null, province_id?: string|null, password: string}  $data
     */
    public function create(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data): User {
            $user = new User([
                'email' => mb_strtolower(trim($data['email'])),
                'password' => $data['password'],
                ...$this->accessFields($data),
                'status' => RecordStatus::Active,
            ]);
            $user->must_change_password = true;
            $user->save();

            $this->auditAccess($actor, $user, null);

            return $user;
        });
    }

    /**
     * Muda nome, papel, agrodealer, província e estado de outra pessoa.
     *
     * @param  array{full_name: string, username?: string|null, role: string, agrodealer_id?: string|null, province_id?: string|null, status: string}  $data
     */
    public function updateAccess(User $actor, User $user, array $data): User
    {
        return DB::transaction(function () use ($actor, $user, $data): User {
            $before = $this->accessSnapshot($user);

            $user->update([
                ...$this->accessFields($data),
                'status' => RecordStatus::from($data['status']),
            ]);

            $this->auditAccess($actor, $user, $before);

            return $user;
        });
    }

    /**
     * Repõe a palavra-passe de outra pessoa sem saber a antiga. A conta fica
     * obrigada a escolher uma nova no próximo acesso.
     */
    public function resetPassword(User $actor, User $user, string $temporaryPassword): void
    {
        DB::transaction(function () use ($actor, $user, $temporaryPassword): void {
            $user->password = $temporaryPassword;
            $user->must_change_password = true;
            $user->setRememberToken(null);
            $user->save();

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'PASSWORD_RESET',
                'entity_type' => 'APP_USER',
                'entity_id' => $user->id,
                'metadata' => ['full_name' => $user->full_name, 'via' => 'app'],
            ]);
        });
    }

    /**
     * Papel, agrodealer e província coerentes: só os papéis de agrodealer têm
     * agrodealer, só o Oficial MEAL tem província.
     *
     * @param  array<string, mixed>  $data
     * @return array{full_name: string, role: UserRole, agrodealer_id: string|null, province_id: string|null}
     */
    private function accessFields(array $data): array
    {
        $role = UserRole::from($data['role']);

        return [
            'full_name' => trim($data['full_name']),
            'username' => $data['username'] ?? null,
            'role' => $role,
            'agrodealer_id' => $role->isSeller() ? $data['agrodealer_id'] : null,
            'province_id' => $role->needsProvince() ? $data['province_id'] : null,
        ];
    }

    /**
     * @return array{full_name: string, username: string|null, role: string, agrodealer_id: string|null, province_id: string|null, status: string}
     */
    private function accessSnapshot(User $user): array
    {
        return [
            'full_name' => $user->full_name,
            'username' => $user->username,
            'role' => $user->role->value,
            'agrodealer_id' => $user->agrodealer_id,
            'province_id' => $user->province_id,
            'status' => $user->status->value,
        ];
    }

    /**
     * @param  array<string, string|null>|null  $before
     */
    private function auditAccess(User $actor, User $user, ?array $before): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => 'APP_USER_ACCESS_SET',
            'entity_type' => 'APP_USER',
            'entity_id' => $user->id,
            'metadata' => ['before' => $before, 'after' => $this->accessSnapshot($user), 'via' => 'app'],
        ]);
    }
}
