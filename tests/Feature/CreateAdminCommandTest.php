<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * php artisan thandizo:create-admin: o superuser de uma instalação, com
 * palavra-passe temporária a trocar no primeiro acesso.
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_active_system_admin_that_must_change_the_password(): void
    {
        $this->artisan('thandizo:create-admin', ['--name' => 'Gil Romão', '--email' => 'Gil@Thandizo.org', '--username' => 'Gil'])
            ->expectsOutputToContain('Administrador do sistema criado: Gil Romão')
            ->expectsOutputToContain('Palavra-passe temporária:')
            ->assertSuccessful();

        $user = User::query()->where('email', 'gil@thandizo.org')->sole();
        $this->assertSame([UserRole::SystemAdmin, RecordStatus::Active, 'gil', true],
            [$user->role, $user->status, $user->username, $user->must_change_password]);
        $this->assertSame('SYSTEM_ADMIN_CREATED', AuditLog::query()->sole()->action);
    }

    public function test_refuses_an_existing_email_or_an_invalid_username(): void
    {
        User::factory()->create(['email' => 'gil@thandizo.org']);

        $this->artisan('thandizo:create-admin', ['--name' => 'Gil', '--email' => 'gil@thandizo.org', '--username' => ''])
            ->expectsOutputToContain('Já existe uma conta com este e-mail.')
            ->assertFailed();
        $this->artisan('thandizo:create-admin', ['--name' => 'Gil', '--email' => 'novo@thandizo.org', '--username' => 'gil@x'])
            ->expectsOutputToContain('Nome de utilizador inválido')
            ->assertFailed();
        $this->assertSame(1, User::query()->count());
    }
}
