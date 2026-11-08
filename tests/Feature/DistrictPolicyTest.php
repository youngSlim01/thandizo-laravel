<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\District;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DistrictPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{UserRole, bool}>
     */
    public static function roles(): array
    {
        // papel => pode adicionar distritos ao programa
        return [
            'gestor MEAL' => [UserRole::MealManager, true],
            'administrador' => [UserRole::SystemAdmin, true],
            'oficial MEAL' => [UserRole::MealOfficer, false],
            'finanças' => [UserRole::FinanceOfficer, false],
            'gestor de comerciante' => [UserRole::AgrodealerManager, false],
            'operador de comerciante' => [UserRole::AgrodealerOperator, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_role_matrix(UserRole $role, bool $canCreate): void
    {
        $user = User::factory()->role($role)->create();

        $this->assertSame($canCreate, $user->can('create', District::class));
    }

    public function test_inactive_manager_can_do_nothing(): void
    {
        $user = User::factory()->role(UserRole::MealManager)->inactive()->create();

        $this->assertFalse($user->can('create', District::class));
    }
}
