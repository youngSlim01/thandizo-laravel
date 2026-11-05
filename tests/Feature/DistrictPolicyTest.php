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
     * @return array<string, array{UserRole, bool, bool}>
     */
    public static function roles(): array
    {
        // papel => [pode ver, pode adicionar]
        return [
            'gestor MEAL' => [UserRole::MealManager, true, true],
            'administrador' => [UserRole::SystemAdmin, true, true],
            'oficial MEAL' => [UserRole::MealOfficer, true, false],
            'finanças' => [UserRole::FinanceOfficer, false, false],
            'gestor de agrodealer' => [UserRole::AgrodealerManager, false, false],
            'operador de agrodealer' => [UserRole::AgrodealerOperator, false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_role_matrix(UserRole $role, bool $canView, bool $canCreate): void
    {
        $user = User::factory()->role($role)->create();

        $this->assertSame($canView, $user->can('viewAny', District::class));
        $this->assertSame($canCreate, $user->can('create', District::class));
    }

    public function test_inactive_manager_can_do_nothing(): void
    {
        $user = User::factory()->role(UserRole::MealManager)->inactive()->create();

        $this->assertFalse($user->can('viewAny', District::class));
        $this->assertFalse($user->can('create', District::class));
    }
}
