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

    /**
     * Ver o catálogo: o back office (o Oficial MEAL só na sua província) ou
     * as contas do próprio agrodealer.
     */
    public function viewCatalogue(User $user, Agrodealer $agrodealer): bool
    {
        if (! $user->isActive()) {
            return false;
        }
        if ($user->role->isSeller()) {
            return $user->agrodealer_id === $agrodealer->id;
        }

        return $this->viewAny($user)
            && Agrodealer::query()->visibleTo($user)->whereKey($agrodealer->id)->exists();
    }

    /**
     * Juntar ou retirar produtos do catálogo: só o gestor MEAL e o
     * administrador. O catálogo vem da cadeia de abastecimento e do
     * fornecedor; o comerciante só o consulta (decidido a 9/11/2026).
     */
    public function manageCatalogue(User $user, Agrodealer $agrodealer): bool
    {
        return $user->isActive() && $user->role->canManageCatalogue();
    }
}
