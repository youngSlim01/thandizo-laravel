<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{UserRole, bool}>
     */
    public static function roles(): array
    {
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
    public function test_role_matrix(UserRole $role, bool $canAdminister): void
    {
        $actor = User::factory()->role($role)->create();
        $other = User::factory()->create();

        $this->assertSame($canAdminister, $actor->can('viewAny', User::class));
        $this->assertSame($canAdminister, $actor->can('create', User::class));
        $this->assertSame($canAdminister, $actor->can('update', $other));
    }

    public function test_nobody_changes_their_own_access(): void
    {
        $admin = User::factory()->role(UserRole::SystemAdmin)->create();

        $this->assertFalse($admin->can('update', $admin));
    }
}
