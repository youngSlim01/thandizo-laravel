<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Troca obrigatória no primeiro acesso, alteração no perfil e recuperação por
 * email (supabase/tests/0014_force_password_change_tests.sql e o
 * ChangePasswordForm do Next.js).
 */
class PasswordTest extends TestCase
{
    use RefreshDatabase;

    private function mustChange(): User
    {
        return User::factory()->create(['must_change_password' => true]);
    }

    public function test_temporary_password_only_opens_the_first_password_page(): void
    {
        $user = $this->mustChange();

        $this->actingAs($user)->get('/admin/products')->assertRedirect('/password/first');
        $this->actingAs($user)->get('/account')->assertRedirect('/password/first');
        $this->actingAs($user)->get('/password/first')->assertOk();
    }

    public function test_choosing_the_new_password_frees_the_account_and_is_audited(): void
    {
        $user = $this->mustChange();

        $this->actingAs($user)
            ->put('/password/first', ['password' => 'Minha2345', 'password_confirmation' => 'Minha2345'])
            ->assertRedirect('/');

        $user->refresh();
        $this->assertFalse($user->must_change_password);
        $this->assertNotNull($user->password_changed_at);
        $this->assertTrue(Hash::check('Minha2345', $user->password));
        $this->assertSame('PASSWORD_CHANGED', AuditLog::query()->sole()->action);
    }

    public function test_new_password_must_differ_from_the_temporary_one(): void
    {
        $this->actingAs($this->mustChange())
            ->put('/password/first', ['password' => 'password', 'password_confirmation' => 'password'])
            ->assertSessionHasErrors(['password' => 'A nova palavra-passe tem de ser diferente da atual.']);
    }

    public function test_new_password_must_be_repeated_and_long_enough(): void
    {
        $user = $this->mustChange();

        $this->actingAs($user)
            ->put('/password/first', ['password' => 'Minha2345', 'password_confirmation' => 'Outra2345'])
            ->assertSessionHasErrors(['password' => 'As duas palavras-passe não são iguais.']);

        $this->actingAs($user)
            ->put('/password/first', ['password' => 'curta', 'password_confirmation' => 'curta'])
            ->assertSessionHasErrors(['password' => 'A palavra-passe tem de ter pelo menos 8 caracteres.']);
    }

    public function test_account_password_change_needs_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->put('/account/password', ['current_password' => 'errada', 'password' => 'Minha2345', 'password_confirmation' => 'Minha2345'])
            ->assertSessionHasErrors(['current_password' => 'A palavra-passe atual não está correta.']);

        $this->actingAs($user)
            ->put('/account/password', ['current_password' => 'password', 'password' => 'Minha2345', 'password_confirmation' => 'Minha2345'])
            ->assertSessionHas('success', 'A sua palavra-passe foi alterada.');
        $this->assertTrue(Hash::check('Minha2345', $user->fresh()->password));
    }

    public function test_forgot_password_sends_the_link_and_answers_the_same_for_unknown_emails(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $answer = 'Se o email estiver registado, enviámos um link de recuperação.';

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHas('success', $answer);
        $this->post('/forgot-password', ['email' => 'ninguem@org.org'])->assertSessionHas('success', $answer);

        Notification::assertSentTo($user, ResetPassword::class);
        Notification::assertCount(1);
    }

    public function test_reset_link_sets_the_new_password_and_clears_the_temporary_flag(): void
    {
        $user = $this->mustChange();
        $token = Password::createToken($user);

        $this->post('/reset-password', ['token' => $token, 'email' => $user->email, 'password' => 'Minha2345', 'password_confirmation' => 'Minha2345'])
            ->assertRedirect('/login');

        $user->refresh();
        $this->assertTrue(Hash::check('Minha2345', $user->password));
        $this->assertFalse($user->must_change_password);
    }

    public function test_an_invalid_reset_link_is_refused(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', ['token' => 'falso', 'email' => $user->email, 'password' => 'Minha2345', 'password_confirmation' => 'Minha2345'])
            ->assertSessionHasErrors(['email' => 'O link de recuperação é inválido ou já expirou. Peça outro.']);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }
}
