<?php

namespace App\Policies;

use App\Models\User;

class DistrictPolicy
{
    /**
     * Adicionar um distrito ao programa.
     */
    public function create(User $user): bool
    {
        return $user->isActive() && $user->role->canManageCatalogue();
    }
}
