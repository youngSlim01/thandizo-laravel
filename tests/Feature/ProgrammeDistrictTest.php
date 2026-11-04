<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Equivalente PHP de add_programme_district / list_programme_districts
 * (supabase/tests/0020_district_prices_and_catalogues_tests.sql e regras da
 * função em 20261008113800).
 */
class ProgrammeDistrictTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->role(UserRole::MealManager)->create();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin/districts')->assertRedirect('/login');
        $this->post('/admin/districts', ['province' => 'Tete', 'district' => 'Mutarara'])->assertRedirect('/login');
    }

    public function test_list_shows_only_programme_districts_by_name_with_province(): void
    {
        $gaza = Province::factory()->create(['name' => 'Gaza']);
        District::factory()->inProgramme()->for($gaza)->create(['name' => 'Mapai']);
        District::factory()->inProgramme()->for($gaza)->create(['name' => 'Mabalane']);
        District::factory()->for($gaza)->create(['name' => 'Chókwè']);

        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create())
            ->get('/admin/districts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/districts')
                ->has('districts', 2)
                ->where('districts.0.name', 'Mabalane')
                ->where('districts.0.province', 'Gaza')
                ->where('districts.1.name', 'Mapai')
                ->where('canCreate', false));
    }

    public function test_agrodealer_cannot_see_the_list(): void
    {
        $this->actingAs(User::factory()->role(UserRole::AgrodealerOperator)->create())
            ->get('/admin/districts')
            ->assertForbidden();
    }

    public function test_meal_officer_cannot_add(): void
    {
        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create())
            ->post('/admin/districts', ['province' => 'Tete', 'district' => 'Moatize'])
            ->assertForbidden();

        $this->assertDatabaseCount('districts', 0);
    }

    public function test_new_province_and_district_are_created_in_the_programme_and_audited(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post('/admin/districts', ['province' => '  Niassa ', 'district' => ' Lichinga  '])
            ->assertRedirect('/admin/districts')
            ->assertSessionHas('success', 'Distrito Lichinga adicionado ao programa.');

        $district = District::query()->where('name', 'Lichinga')->sole();
        $this->assertTrue($district->programme_active);
        $this->assertSame('Niassa', $district->province->name);

        $audit = AuditLog::query()->sole();
        $this->assertSame($manager->id, $audit->actor_id);
        $this->assertSame('PROGRAMME_DISTRICT_ADDED', $audit->action);
        $this->assertSame('DISTRICT', $audit->entity_type);
        $this->assertSame($district->id, $audit->entity_id);
        $this->assertEquals(['province' => 'Niassa', 'district' => 'Lichinga'], $audit->metadata);
    }

    public function test_existing_names_are_reused_ignoring_case(): void
    {
        $tete = Province::factory()->create(['name' => 'Tete']);
        $existing = District::factory()->inProgramme()->for($tete)->create(['name' => 'Mutarara']);

        $this->actingAs($this->manager())
            ->post('/admin/districts', ['province' => 'TETE', 'district' => 'mutarara'])
            ->assertRedirect('/admin/districts');

        $this->assertDatabaseCount('provinces', 1);
        $this->assertDatabaseCount('districts', 1);
        $this->assertSame('Mutarara', $existing->fresh()->name);
    }

    public function test_district_outside_the_programme_is_activated(): void
    {
        $gaza = Province::factory()->create(['name' => 'Gaza']);
        $outside = District::factory()->for($gaza)->create(['name' => 'Chókwè']);

        $this->actingAs($this->manager())
            ->post('/admin/districts', ['province' => 'Gaza', 'district' => 'Chókwè']);

        $this->assertTrue($outside->fresh()->programme_active);
        $this->assertDatabaseCount('districts', 1);
    }

    public function test_same_district_name_in_another_province_is_a_different_district(): void
    {
        $tete = Province::factory()->create(['name' => 'Tete']);
        District::factory()->inProgramme()->for($tete)->create(['name' => 'Cidade']);

        $this->actingAs($this->manager())
            ->post('/admin/districts', ['province' => 'Gaza', 'district' => 'Cidade']);

        $this->assertDatabaseCount('districts', 2);
    }

    public function test_province_and_district_are_required(): void
    {
        $this->actingAs($this->manager())
            ->post('/admin/districts', ['province' => '   ', 'district' => ''])
            ->assertSessionHasErrors([
                'province' => 'Indique a província e o distrito.',
                'district' => 'Indique a província e o distrito.',
            ]);

        $this->assertDatabaseCount('provinces', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
