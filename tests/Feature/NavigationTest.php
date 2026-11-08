<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Agrodealer;
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
        $meal = ['/admin/dashboard', '/admin/households', '/admin/cards', '/admin/cycles', '/admin/transactions', '/admin/settlements', '/admin/reports', '/admin/comerciantes', '/admin/products'];

        return [
            'administrador' => [UserRole::SystemAdmin, [...$meal, '/admin/users', '/finance/settlements', '/admin/settings']],
            'gestor MEAL' => [UserRole::MealManager, [...$meal, '/admin/users', '/admin/settings']],
            'oficial MEAL' => [UserRole::MealOfficer, $meal],
            'finanças' => [UserRole::FinanceOfficer, ['/finance', '/finance/settlements', '/finance/transactions', '/finance/reports', '/admin/products']],
            'operador de comerciante' => [UserRole::AgrodealerOperator, []],
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
            ->assertRedirect('/admin/dashboard');
    }

    public function test_finance_opens_its_area_and_sellers_the_pos(): void
    {
        $this->actingAs(User::factory()->role(UserRole::FinanceOfficer)->create())->get('/')->assertRedirect('/finance');

        $seller = User::factory()->role(UserRole::AgrodealerOperator)->create(['agrodealer_id' => Agrodealer::factory()->create()->id]);
        $this->actingAs($seller)->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('pos/home'));
    }

    public function test_an_account_without_any_area_sees_the_provisional_home(): void
    {
        $this->actingAs(User::factory()->role(UserRole::AgrodealerOperator)->create(['agrodealer_id' => null]))
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('home'));
    }
}
