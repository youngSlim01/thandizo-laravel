<?php

namespace App\Policies;

use App\Models\Agrodealer;
use App\Models\User;

class AgrodealerPolicy
{
    /**
     * Ver a página de agrodealers (back office MEAL; o Oficial MEAL só vê a
     * sua província, ver Agrodealer::visibleTo).
     */
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->role->isMealBackOffice();
    }

    /**
     * Criar agrodealers (gestor MEAL, administrador).
     */
    public function create(User $user): bool
    {
        return $user->isActive() && $user->role->canManageCatalogue();
    }

    /**
     * Editar o agrodealer e criar ou editar os seus POS.
     */
    public function update(User $user, Agrodealer $agrodealer): bool
    {
        return $this->create($user);
    }
}
