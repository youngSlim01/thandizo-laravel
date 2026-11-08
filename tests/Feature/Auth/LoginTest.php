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

    public function test_guest_is_sent_to_login_from_a_protected_page(): void
    {
        $this->get('/account')->assertRedirect('/login');
    }

    public function test_login_page_renders(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('auth/login'));
    }

    public function test_active_user_logs_in_and_the_last_login_is_recorded(): void
    {
        $user = User::factory()->role(UserRole::MealManager)->create();

        $this->post('/login', ['login' => $user->email, 'password' => 'password'])
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_wrong_password_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['login' => $user->email, 'password' => 'errada'])
            ->assertSessionHasErrors(['login' => 'Utilizador ou palavra-passe incorretos.']);
        $this->assertGuest();
    }

    public function test_inactive_user_is_refused_even_with_right_password(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['login' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors(['login' => 'O seu utilizador não está ativo no programa.']);
        $this->assertGuest();
    }

    public function test_sixth_attempt_in_a_minute_is_blocked(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $_) {
            $this->post('/login', ['login' => $user->email, 'password' => 'errada']);
        }

        $this->post('/login', ['login' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('login');
        $this->assertGuest();
    }

    public function test_the_username_also_logs_in_ignoring_case_and_spaces(): void
    {
        $user = User::factory()->create(['username' => 'joao.operador']);

        $this->post('/login', ['login' => ' Joao.Operador ', 'password' => 'password'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_an_unknown_username_is_refused_like_a_wrong_password(): void
    {
        User::factory()->create(['username' => 'joao.operador']);

        $this->post('/login', ['login' => 'maria', 'password' => 'password'])
            ->assertSessionHasErrors(['login' => 'Utilizador ou palavra-passe incorretos.']);
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
