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

    // Papéis que vendem no POS e por isso pertencem a um agrodealer.
    public function isSeller(): bool
    {
        return in_array($this, [self::AgrodealerOperator, self::AgrodealerManager], true);
    }
}
