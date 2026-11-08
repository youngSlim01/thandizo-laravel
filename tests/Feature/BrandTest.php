<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\ClientProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * O cliente da instalação, editado em Configuração, chega a todas as
 * páginas para aparecer junto do nome THANDIZO.
 */
class BrandTest extends TestCase
{
    use RefreshDatabase;

    public function test_without_a_client_only_thandizo_is_shown(): void
    {
        $this->get('/login')->assertInertia(fn (Assert $page) => $page
            ->where('brand', ['client' => null, 'programme' => null, 'tagline' => null, 'logos' => []]));
    }

    public function test_the_administrator_sets_the_client_and_every_page_receives_it(): void
    {
        $admin = User::factory()->role(UserRole::SystemAdmin)->create();

        $this->actingAs($admin)->put('/admin/settings/client', [
            'name' => '  Save the   Children ', 'programme' => 'Projecto LINK GCF', 'tagline' => ' ',
        ])->assertSessionHas('success');
        $this->actingAs($admin)->put('/admin/settings/client', ['name' => 'Save the Children', 'programme' => 'Projecto LINK GCF'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, ClientProfile::query()->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'CLIENT_PROFILE_UPDATED')->count());
        $this->actingAs($admin)->get('/admin/settings')->assertInertia(fn (Assert $page) => $page
            ->where('client.client', 'Save the Children')
            ->where('brand.client', 'Save the Children')
            ->where('brand.programme', 'Projecto LINK GCF')
            ->where('brand.tagline', null));
    }

    public function test_only_the_system_administrator_changes_the_client(): void
    {
        $manager = User::factory()->role(UserRole::MealManager)->create();
        $admin = User::factory()->role(UserRole::SystemAdmin)->create();

        $this->actingAs($manager)->put('/admin/settings/client', ['name' => 'Outra'])->assertForbidden();
        $this->actingAs($admin)->put('/admin/settings/client', ['name' => str_repeat('a', 121)])
            ->assertSessionHasErrors(['name' => 'O nome da organização tem no máximo 120 caracteres.']);
        $this->assertSame(0, ClientProfile::query()->count());
    }
}
