<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Painel do administrador do sistema.
 */
class SystemDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_the_system_administrator_opens_it(): void
    {
        $admin = User::factory()->role(UserRole::SystemAdmin)->create();

        $this->actingAs($admin)->get('/system/dashboard')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('system/dashboard')->where('dashboard.cycles_total', 0));
        $this->actingAs(User::factory()->role(UserRole::MealManager)->create())->get('/system/dashboard')->assertForbidden();
    }
}
