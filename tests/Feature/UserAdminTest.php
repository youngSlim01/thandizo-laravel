<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\AuditLog;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Equivalente PHP de admin_set_app_user, admin_list_app_users,
 * admin_require_password_change e das rotas /api/admin/users
 * (supabase/tests/0007, 0008, 0015, 0027).
 */
class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->role(UserRole::SystemAdmin)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newAccount(array $overrides = []): array
    {
        return [
            'email' => 'Nova.Pessoa@Org.org', 'full_name' => ' Nova Pessoa ', 'role' => 'MEAL_MANAGER',
            'agrodealer_id' => null, 'province_id' => null, 'password' => 'Temp2345ab', ...$overrides,
        ];
    }

    public function test_meal_officer_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create())
            ->get('/admin/users')
            ->assertForbidden();
    }

    public function test_list_shows_every_account_by_name_and_marks_me(): void
    {
        $admin = User::factory()->role(UserRole::SystemAdmin)->create(['full_name' => 'Zulmira Admin']);
        $agrodealer = Agrodealer::factory()->create(['name' => 'Agro Guro']);
        User::factory()->role(UserRole::AgrodealerOperator)->create(['full_name' => 'Ana Operadora', 'agrodealer_id' => $agrodealer->id]);

        $this->actingAs($admin)
            ->get('/admin/users')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/users')
                ->where('users.0.full_name', 'Ana Operadora')
                ->where('users.0.agrodealer_name', 'Agro Guro')
                ->where('users.0.is_me', false)
                ->where('users.1.is_me', true)
                ->has('roles', 6)
                ->has('agrodealers', 1));
    }

    public function test_create_makes_an_active_account_that_must_change_password_and_is_audited(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/users', $this->newAccount())
            ->assertRedirect('/admin/users')
            ->assertSessionHas('credentials', [
                'full_name' => 'Nova Pessoa', 'role_label' => 'Gestor MEAL', 'email' => 'nova.pessoa@org.org', 'username' => null,
                'password' => 'Temp2345ab', 'kind' => 'created',
            ]);

        $user = User::query()->where('email', 'nova.pessoa@org.org')->sole();
        $this->assertSame(RecordStatus::Active, $user->status);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('Temp2345ab', $user->password));

        $audit = AuditLog::query()->sole();
        $this->assertSame('APP_USER_ACCESS_SET', $audit->action);
        $this->assertSame($admin->id, $audit->actor_id);
        $this->assertNull($audit->metadata['before']);
        $this->assertSame('MEAL_MANAGER', $audit->metadata['after']['role']);
    }

    public function test_email_must_be_new(): void
    {
        User::factory()->create(['email' => 'nova.pessoa@org.org']);

        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newAccount())
            ->assertSessionHasErrors(['email' => 'Já existe uma conta com este email.']);
    }

    public function test_the_username_is_optional_saved_in_lowercase_and_unique(): void
    {
        $admin = $this->admin();
        User::factory()->create(['username' => 'ocupado']);

        $this->actingAs($admin)->post('/admin/users', $this->newAccount(['username' => ' Joao.Operador ']))
            ->assertSessionHasNoErrors()->assertSessionHas('credentials.username', 'joao.operador');
        $this->assertSame('joao.operador', User::query()->where('email', 'nova.pessoa@org.org')->value('username'));

        $this->actingAs($admin)->post('/admin/users', $this->newAccount(['email' => 'outra@org.org', 'username' => 'OCUPADO']))
            ->assertSessionHasErrors(['username' => 'Já existe uma conta com este nome de utilizador.']);
        $this->actingAs($admin)->post('/admin/users', $this->newAccount(['email' => 'outra@org.org', 'username' => 'joão@x']))
            ->assertSessionHasErrors('username');
    }

    public function test_editing_keeps_or_clears_the_username(): void
    {
        $admin = $this->admin();
        $user = User::factory()->role(UserRole::MealManager)->create(['full_name' => 'Rui', 'username' => 'rui']);
        $access = ['full_name' => 'Rui', 'role' => 'MEAL_MANAGER', 'status' => 'ACTIVE'];

        $this->actingAs($admin)->put("/admin/users/{$user->id}", [...$access, 'username' => 'rui'])->assertSessionHasNoErrors();
        $this->assertSame('rui', $user->fresh()->username);
        $this->actingAs($admin)->put("/admin/users/{$user->id}", [...$access, 'username' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->username);
    }

    public function test_temporary_password_has_at_least_8_characters(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newAccount(['password' => 'curta']))
            ->assertSessionHasErrors(['password' => 'A palavra-passe tem de ter pelo menos 8 caracteres.']);
    }

    public function test_seller_role_needs_an_agrodealer(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newAccount(['role' => 'AGRODEALER_OPERATOR']))
            ->assertSessionHasErrors(['agrodealer_id' => 'Este papel precisa de um comerciante.']);
    }

    public function test_seller_cannot_be_put_in_an_inactive_agrodealer(): void
    {
        $agrodealer = Agrodealer::factory()->inactive()->create();

        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newAccount(['role' => 'AGRODEALER_OPERATOR', 'agrodealer_id' => $agrodealer->id]))
            ->assertSessionHasErrors(['agrodealer_id' => 'O comerciante escolhido não está ativo.']);
    }

    public function test_central_role_cannot_have_an_agrodealer(): void
    {
        $agrodealer = Agrodealer::factory()->create();

        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newAccount(['agrodealer_id' => $agrodealer->id]))
            ->assertSessionHasErrors(['agrodealer_id' => 'Papéis centrais (MEAL, finanças, administrador) não podem ficar ligados a um comerciante.']);
    }

    public function test_meal_officer_needs_a_province_and_only_the_officer_has_one(): void
    {
        $tete = Province::factory()->create();

        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newAccount(['role' => 'MEAL_OFFICER']))
            ->assertSessionHasErrors(['province_id' => 'Um oficial MEAL tem de ter uma província.']);

        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newAccount(['province_id' => $tete->id]))
            ->assertSessionHasErrors(['province_id' => 'Só o oficial MEAL tem província.']);

        $this->actingAs($this->admin())
            ->post('/admin/users', $this->newAccount(['role' => 'MEAL_OFFICER', 'province_id' => $tete->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($tete->id, User::query()->where('role', 'MEAL_OFFICER')->value('province_id'));
    }

    public function test_update_access_changes_role_and_audits_before_and_after(): void
    {
        $admin = $this->admin();
        $tete = Province::factory()->create();
        $user = User::factory()->role(UserRole::MealManager)->create(['full_name' => 'Rui']);

        $this->actingAs($admin)
            ->put("/admin/users/{$user->id}", ['full_name' => 'Rui Silva', 'role' => 'MEAL_OFFICER', 'province_id' => $tete->id, 'status' => 'INACTIVE'])
            ->assertRedirect('/admin/users')
            ->assertSessionHas('success', 'Rui Silva foi atualizado: Oficial MEAL, inativo.');

        $user->refresh();
        $this->assertSame(UserRole::MealOfficer, $user->role);
        $this->assertSame($tete->id, $user->province_id);
        $this->assertSame(RecordStatus::Inactive, $user->status);

        $audit = AuditLog::query()->sole();
        $this->assertSame('MEAL_MANAGER', $audit->metadata['before']['role']);
        $this->assertSame('MEAL_OFFICER', $audit->metadata['after']['role']);
    }

    public function test_an_inactive_seller_may_stay_on_an_inactive_agrodealer(): void
    {
        $agrodealer = Agrodealer::factory()->inactive()->create();
        $user = User::factory()->role(UserRole::AgrodealerOperator)->create(['agrodealer_id' => $agrodealer->id]);

        $this->actingAs($this->admin())
            ->put("/admin/users/{$user->id}", ['full_name' => $user->full_name, 'role' => 'AGRODEALER_OPERATOR', 'agrodealer_id' => $agrodealer->id, 'status' => 'INACTIVE'])
            ->assertSessionHasNoErrors();
    }

    public function test_nobody_changes_their_own_access(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put("/admin/users/{$admin->id}", ['full_name' => 'Eu', 'role' => 'MEAL_OFFICER', 'status' => 'ACTIVE'])
            ->assertForbidden();

        $this->assertSame(UserRole::SystemAdmin, $admin->fresh()->role);
    }

    public function test_meal_officer_cannot_create_accounts(): void
    {
        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create())
            ->post('/admin/users', $this->newAccount())
            ->assertForbidden();
    }

    public function test_reset_password_sets_a_temporary_one_and_is_audited(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)
            ->put("/admin/users/{$user->id}/password", ['password' => 'Nova2345xy'])
            ->assertSessionHas('credentials.kind', 'reset');

        $user->refresh();
        $this->assertTrue(Hash::check('Nova2345xy', $user->password));
        $this->assertTrue($user->must_change_password);
        $this->assertSame('PASSWORD_RESET', AuditLog::query()->sole()->action);
    }

    public function test_nobody_resets_their_own_password_here(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->put("/admin/users/{$admin->id}/password", ['password' => 'Nova2345xy'])
            ->assertForbidden();
    }
}
