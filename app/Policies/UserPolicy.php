<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Ver a página de utilizadores e criar contas (gestor MEAL, administrador).
     */
    public function viewAny(User $actor): bool
    {
        return $actor->isActive() && $actor->role->canAdministerUsers();
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
        return $this->viewAny($actor) && ! $actor->is($user);
    }
}
