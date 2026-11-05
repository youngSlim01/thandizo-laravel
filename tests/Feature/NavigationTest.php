<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NavigationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{UserRole, list<string>}>
     */
    public static function menus(): array
    {
        return [
            'administrador' => [UserRole::SystemAdmin, ['/admin/agrodealers', '/admin/products', '/admin/users']],
            'gestor MEAL' => [UserRole::MealManager, ['/admin/agrodealers', '/admin/products', '/admin/users']],
            'oficial MEAL' => [UserRole::MealOfficer, ['/admin/agrodealers', '/admin/products']],
            'finanças' => [UserRole::FinanceOfficer, []],
            'operador de agrodealer' => [UserRole::AgrodealerOperator, []],
        ];
    }

    #[DataProvider('menus')]
    public function test_menu_shows_only_the_sections_the_role_can_open(UserRole $role, array $hrefs): void
    {
        $this->actingAs(User::factory()->role($role)->create())
            ->get('/account')
            ->assertInertia(fn (Assert $page) => $page->where('navigation', fn ($navigation) => collect($navigation)->pluck('href')->all() === $hrefs));
    }

    public function test_back_office_home_opens_the_first_section(): void
    {
        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create())
            ->get('/')
            ->assertRedirect('/admin/agrodealers');
    }

    public function test_roles_without_sections_see_the_provisional_home(): void
    {
        $this->actingAs(User::factory()->role(UserRole::FinanceOfficer)->create())
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('home')->where('auth.user.role_label', 'Oficial de Finanças'));
    }
}
