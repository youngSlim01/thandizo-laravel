<?php

namespace App\Policies;

use App\Models\User;

class DistrictPolicy
{
    /**
     * Ver os distritos do programa.
     */
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->role->canViewAllOperations();
    }

    /**
     * Adicionar um distrito ao programa.
     */
    public function create(User $user): bool
    {
        return $user->isActive() && $user->role->canManageCatalogue();
    }
}
