<?php

namespace App\Enums;

// Os 6 papéis do THANDIZO (antigo enum public.user_role no PostgreSQL).
enum UserRole: string
{
    case AgrodealerOperator = 'AGRODEALER_OPERATOR';
    case AgrodealerManager = 'AGRODEALER_MANAGER';
    case MealOfficer = 'MEAL_OFFICER';
    case MealManager = 'MEAL_MANAGER';
    case FinanceOfficer = 'FINANCE_OFFICER';
    case SystemAdmin = 'SYSTEM_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::AgrodealerOperator => 'Operador de agrodealer',
            self::AgrodealerManager => 'Gestor de agrodealer',
            self::MealOfficer => 'Oficial MEAL',
            self::MealManager => 'Gestor MEAL',
            self::FinanceOfficer => 'Oficial de Finanças',
            self::SystemAdmin => 'Administrador do sistema',
        };
    }

    /**
     * Papéis centrais que vêem as operações de todo o programa
     * (antigo private.can_view_all_operations).
     */
    public function canViewAllOperations(): bool
    {
        return in_array($this, [self::MealManager, self::MealOfficer, self::FinanceOfficer, self::SystemAdmin], true);
    }

    /**
     * Papéis que gerem distritos, agrodealers, produtos e preços
     * (antigo erro CATALOGUE_NOT_ALLOWED).
     */
    public function canManageCatalogue(): bool
    {
        return in_array($this, [self::MealManager, self::SystemAdmin], true);
    }

    /**
     * Papéis que vendem no POS e por isso pertencem a um agrodealer.
     */
    public function isSeller(): bool
    {
        return in_array($this, [self::AgrodealerOperator, self::AgrodealerManager], true);
    }
}
