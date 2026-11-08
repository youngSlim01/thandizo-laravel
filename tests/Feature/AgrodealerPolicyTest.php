<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AgrodealerPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{UserRole, bool, bool}>
     */
    public static function roles(): array
    {
        // papel => [pode ver, pode gerir]
        return [
            'gestor MEAL' => [UserRole::MealManager, true, true],
            'administrador' => [UserRole::SystemAdmin, true, true],
            'oficial MEAL' => [UserRole::MealOfficer, true, false],
            'finanças' => [UserRole::FinanceOfficer, false, false],
            'gestor de comerciante' => [UserRole::AgrodealerManager, false, false],
            'operador de comerciante' => [UserRole::AgrodealerOperator, false, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_role_matrix(UserRole $role, bool $canView, bool $canManage): void
    {
        $user = User::factory()->role($role)->create();
        $agrodealer = Agrodealer::factory()->create();

        $this->assertSame($canView, $user->can('viewAny', Agrodealer::class));
        $this->assertSame($canManage, $user->can('create', Agrodealer::class));
        $this->assertSame($canManage, $user->can('update', $agrodealer));
    }
}
