<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    /** Contas activas no máximo por comerciante (decidido a 9/11/2026). */
    public const MAX_ACTIVE_PER_AGRODEALER = 2;

    /**
     * As contas que quem vê pode gerir, por nome, com agrodealer e província:
     * todas para o gestor MEAL e o administrador; para o oficial MEAL, só as
     * dos comerciantes da sua província.
     *
     * @return Collection<int, User>
     */
    public function list(?User $viewer = null): Collection
    {
        return User::query()
            ->when($viewer?->isProvinceScoped(), fn ($q) => $q
                ->whereIn('role', [UserRole::AgrodealerManager->value, UserRole::AgrodealerOperator->value])
                ->whereIn('agrodealer_id', Agrodealer::query()->visibleTo($viewer)->select('id')))
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
            $this->guardAccess($actor, $data, RecordStatus::Active->value, null);
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
            $this->guardAccess($actor, $data, $data['status'], $user);
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
     * Cria um administrador do sistema fora do ecrã (comando
     * thandizo:create-admin), por exemplo o primeiro de uma instalação nova.
     * Fica activo, com palavra-passe temporária a trocar no primeiro acesso.
     */
    public function createSystemAdmin(string $fullName, string $email, ?string $username, string $temporaryPassword): User
    {
        return DB::transaction(function () use ($fullName, $email, $username, $temporaryPassword): User {
            $user = new User([
                'full_name' => trim($fullName),
                'email' => mb_strtolower(trim($email)),
                'username' => $username === null || trim($username) === '' ? null : mb_strtolower(trim($username)),
                'password' => $temporaryPassword,
                'role' => UserRole::SystemAdmin,
                'status' => RecordStatus::Active,
            ]);
            $user->must_change_password = true;
            $user->save();

            AuditLog::query()->create([
                'actor_id' => null,
                'action' => 'SYSTEM_ADMIN_CREATED',
                'entity_type' => 'APP_USER',
                'entity_id' => $user->id,
                'metadata' => ['full_name' => $user->full_name, 'email' => $user->email, 'via' => 'console'],
            ]);

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
     * O oficial MEAL só cria e muda contas de comerciantes da sua província;
     * cada comerciante tem no máximo duas contas activas (gestor ou operador).
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function guardAccess(User $actor, array $data, string $status, ?User $user): void
    {
        $role = UserRole::from($data['role']);
        $agrodealerId = $role->isSeller() ? ($data['agrodealer_id'] ?? null) : null;
        if ($actor->isProvinceScoped()) {
            if (! $role->isSeller()) {
                throw ValidationException::withMessages(['role' => 'O oficial MEAL só cria contas de comerciantes.']);
            }
            if (! Agrodealer::query()->visibleTo($actor)->whereKey($agrodealerId)->exists()) {
                throw ValidationException::withMessages(['agrodealer_id' => 'Escolha um comerciante da sua província.']);
            }
        }
        if ($agrodealerId === null || $status !== RecordStatus::Active->value) {
            return;
        }
        // Bloqueia o comerciante: duas contas criadas ao mesmo tempo não passam o limite.
        Agrodealer::query()->whereKey($agrodealerId)->lockForUpdate()->first();
        $active = User::query()->where('agrodealer_id', $agrodealerId)->where('status', RecordStatus::Active)
            ->when($user, fn ($q) => $q->whereKeyNot($user->id))->count();
        if ($active >= self::MAX_ACTIVE_PER_AGRODEALER) {
            throw ValidationException::withMessages(['agrodealer_id' => 'Este comerciante já tem '.self::MAX_ACTIVE_PER_AGRODEALER.' contas activas. Desactive uma antes de criar ou activar outra.']);
        }
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
