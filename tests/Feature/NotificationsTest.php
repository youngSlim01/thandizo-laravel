<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Sino das notificações: o gestor MEAL vê os comerciantes que esperam pelo
 * catálogo; quem não confirma catálogos não vê esse aviso.
 */
class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_meal_manager_is_told_which_merchants_wait_for_the_catalogue(): void
    {
        $waiting = Agrodealer::factory()->create();
        $ready = Agrodealer::factory()->create();
        $ready->catalogue()->attach(Product::factory()->create()->id);
        Agrodealer::factory()->create(['status' => 'INACTIVE']);
        $manager = User::factory()->role(UserRole::MealManager)->create();

        $this->actingAs($manager)->get('/admin/comerciantes')->assertInertia(fn (Assert $page) => $page
            ->has('notifications', 1)
            ->where('notifications.0.key', 'catalogue_waiting')
            ->where('notifications.0.value', 1)
            ->where('notifications.0.href', '/admin/comerciantes?filtro=sem-catalogo'));

        $waiting->catalogue()->attach(Product::factory()->create()->id);
        $this->actingAs($manager)->get('/admin/comerciantes')->assertInertia(fn (Assert $page) => $page->has('notifications', 0));
    }

    public function test_the_meal_officer_does_not_get_the_catalogue_notice(): void
    {
        $agrodealer = Agrodealer::factory()->create();
        $officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $agrodealer->province_id]);

        $this->actingAs($officer)->get('/admin/comerciantes')->assertInertia(fn (Assert $page) => $page->has('notifications', 0));
    }
}
