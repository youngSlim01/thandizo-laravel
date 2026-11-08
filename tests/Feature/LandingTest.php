<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Página pública: quem não tem sessão vê a apresentação; quem tem vai para
 * a sua área.
 */
class LandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_sees_the_presentation_and_a_signed_in_user_goes_to_their_area(): void
    {
        $this->get('/')->assertOk()->assertInertia(fn (Assert $page) => $page->component('landing'));
        $this->actingAs(User::factory()->role(UserRole::MealManager)->create())->get('/')->assertRedirect('/admin/dashboard');
    }

    public function test_the_website_opens_for_everyone_at_its_own_address(): void
    {
        $this->get('/website')->assertOk()->assertInertia(fn (Assert $page) => $page->component('landing'));
        $this->actingAs(User::factory()->role(UserRole::SystemAdmin)->create())->get('/website')
            ->assertOk()->assertInertia(fn (Assert $page) => $page->component('landing'));
    }
}
