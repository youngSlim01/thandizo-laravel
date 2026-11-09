<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\User;

class UserPolicy
{
    /**
     * Ver a página de utilizadores e criar contas: gestor MEAL e
     * administrador; o oficial MEAL só as contas dos comerciantes da sua
     * província (UserAdminService::guardAccess).
     */
    public function viewAny(User $actor): bool
    {
        return $actor->isActive()
            && ($actor->role->canAdministerUsers() || ($actor->role === UserRole::MealOfficer && $actor->province_id !== null));
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    /**
     * Mudar o acesso ou repor a palavra-passe de outra pessoa. Nunca a
     * própria conta (antigo CANNOT_CHANGE_OWN_ACCESS): outro administrador
     * tem de o fazer.
     */
    public function update(User $actor, User $user): bool
    {
        if (! $this->viewAny($actor) || $actor->is($user)) {
            return false;
        }

        return ! $actor->isProvinceScoped()
            || ($user->role->isSeller() && Agrodealer::query()->visibleTo($actor)->whereKey($user->agrodealer_id)->exists());
    }
}
