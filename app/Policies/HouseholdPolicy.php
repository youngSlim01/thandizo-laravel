<?php

namespace App\Policies;

use App\Models\Household;
use App\Models\User;
use App\Services\HouseholdSearchService;

class HouseholdPolicy
{
    public function __construct(private HouseholdSearchService $search) {}

    /**
     * Página de beneficiários e pesquisa (back office MEAL; o Oficial MEAL
     * só vê a sua província).
     */
    public function viewAny(User $user): bool
    {
        return $user->isActive() && $user->role->isMealBackOffice();
    }

    /**
     * Ficha e histórico: só agregados que a pessoa pode ver. Fora do âmbito
     * responde-se como "não encontrado".
     */
    public function view(User $user, Household $household): bool
    {
        return $this->viewAny($user) && $this->search->isVisible($user, $household->id);
    }

    /**
     * Importar a lista MEAL: também o Oficial MEAL, só da sua província.
     */
    public function import(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Registar, alterar, desactivar, reactivar e eliminar.
     */
    public function create(User $user): bool
    {
        return $user->isActive() && $user->role->canManageHouseholds();
    }

    public function update(User $user, Household $household): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, Household $household): bool
    {
        return $this->create($user);
    }
}
