<?php

namespace App\Policies;

use App\Models\User;

class DistrictPolicy
{
    /**
     * Ver a página dos distritos do programa (back office MEAL).
     */
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->role->isMealBackOffice();
    }

    /**
     * Adicionar um distrito ao programa.
     */
    public function create(User $user): bool
    {
        return $user->isActive() && $user->role->canManageCatalogue();
    }
}
