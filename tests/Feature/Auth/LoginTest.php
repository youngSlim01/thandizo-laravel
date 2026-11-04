<?php

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
    }

    public function test_active_user_logs_in_and_sees_home_with_role(): void
    {
        $user = User::factory()->role(UserRole::MealManager)->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        $this->get('/')
            ->assertInertia(fn (Assert $page) => $page
                ->component('home')
                ->where('auth.user.id', $user->id)
                ->where('auth.user.role', 'MEAL_MANAGER')
                ->where('auth.user.role_label', 'Gestor MEAL'));
    }

    public function test_wrong_password_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'errada'])
            ->assertSessionHasErrors(['email' => 'Email ou palavra-passe incorretos.']);
        $this->assertGuest();
    }

    public function test_inactive_user_is_refused_even_with_right_password(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'O seu utilizador não está ativo no programa.']);
        $this->assertGuest();
    }

    public function test_sixth_attempt_in_a_minute_is_blocked(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $_) {
            $this->post('/login', ['email' => $user->email, 'password' => 'errada']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_logout_ends_the_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_user_ids_are_uuids(): void
    {
        $user = User::factory()->create();

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $user->id);
    }
}
