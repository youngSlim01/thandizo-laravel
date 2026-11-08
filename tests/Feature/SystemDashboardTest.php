<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MoneyScenario;
use Tests\Concerns\SellsInThePast;
use Tests\TestCase;

/**
 * Painel técnico do administrador do sistema.
 */
class SystemDashboardTest extends TestCase
{
    use MoneyScenario, RefreshDatabase, SellsInThePast;

    public function test_only_the_system_administrator_opens_it_with_sales_in_the_recent_activity(): void
    {
        $this->saleOn('2026-10-03 10:00', $this->householdWithCard()['voucher'], 1);

        $this->actingAs($this->admin)->get('/system/dashboard')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('system/dashboard')
                ->where('dashboard.cycles_total', 1)->where('dashboard.transactions_total', 1));
        $this->actingAs(User::factory()->role(UserRole::MealManager)->create())->get('/system/dashboard')->assertForbidden();
    }
}
