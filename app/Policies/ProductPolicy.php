<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Ver a página de produtos e preços: back office MEAL e Finanças (só
     * leitura).
     */
    public function viewAny(User $user): bool
    {
        return $user->isActive() && ($user->role->isMealBackOffice() || $user->role->isFinance());
    }

    /**
     * Criar produtos (gestor MEAL, administrador).
     */
    public function create(User $user): bool
    {
        return $user->isActive() && $user->role->canManageCatalogue();
    }

    /**
     * Editar ou desactivar um produto (gestor MEAL, administrador).
     */
    public function update(User $user, Product $product): bool
    {
        return $this->create($user);
    }
}
