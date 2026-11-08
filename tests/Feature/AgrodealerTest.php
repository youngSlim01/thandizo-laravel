<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\Province;
use App\Models\User;
use App\Services\AgrodealerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Equivalente PHP de save_agrodealer, list_agrodealers_admin e
 * next_agrodealer_code (20261008114300, 20261008114600) e dos casos de
 * supabase/tests/0020 e 0027.
 */
class AgrodealerTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->role(UserRole::MealManager)->create();
    }

    private function programmeDistrict(string $name, string $province = 'Tete'): District
    {
        return District::factory()->inProgramme()
            ->for(Province::query()->firstOrCreate(['name' => $province]))
            ->create(['name' => $name]);
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin/comerciantes')->assertRedirect('/login');
    }

    public function test_finance_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->role(UserRole::FinanceOfficer)->create())
            ->get('/admin/comerciantes')
            ->assertForbidden();
    }

    public function test_list_shows_active_first_by_name_with_pos_and_active_users(): void
    {
        $mutarara = $this->programmeDistrict('Mutarara');
        Agrodealer::factory()->inactive()->for($mutarara)->create(['name' => 'Agro Antigo', 'province_id' => $mutarara->province_id]);
        $agro = Agrodealer::factory()->for($mutarara)->create(['name' => 'Agro Mutarara', 'province_id' => $mutarara->province_id]);
        $agro->pos()->create(['pos_code' => 'POS-MUT-001-02', 'status' => RecordStatus::Active]);
        $agro->pos()->create(['pos_code' => 'POS-MUT-001-01', 'status' => RecordStatus::Active]);
        User::factory()->role(UserRole::AgrodealerOperator)->create(['agrodealer_id' => $agro->id]);
        User::factory()->role(UserRole::AgrodealerOperator)->inactive()->create(['agrodealer_id' => $agro->id]);

        $this->actingAs($this->manager())
            ->get('/admin/comerciantes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/agrodealers')
                ->where('agrodealers.0.name', 'Agro Mutarara')
                ->where('agrodealers.0.district', 'Mutarara')
                ->where('agrodealers.0.province', 'Tete')
                ->where('agrodealers.0.users', 1)
                ->where('agrodealers.0.pos.0.pos_code', 'POS-MUT-001-01')
                ->where('agrodealers.1.name', 'Agro Antigo')
                ->has('districts', 1)
                ->where('canManage', true));
    }

    public function test_meal_officer_sees_only_the_agrodealers_of_the_province(): void
    {
        $mutarara = $this->programmeDistrict('Mutarara', 'Tete');
        $guro = $this->programmeDistrict('Guro', 'Manica');
        Agrodealer::factory()->for($mutarara)->create(['name' => 'Agro Tete', 'province_id' => $mutarara->province_id]);
        Agrodealer::factory()->for($mutarara)->create(['name' => 'Agro Tete antigo', 'province_id' => null]);
        Agrodealer::factory()->for($guro)->create(['name' => 'Agro Manica', 'province_id' => $guro->province_id]);
        $officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $mutarara->province_id]);

        $this->actingAs($officer)
            ->get('/admin/comerciantes')
            ->assertInertia(fn (Assert $page) => $page
                ->has('agrodealers', 2)
                ->where('agrodealers.0.name', 'Agro Tete')
                ->where('agrodealers.1.name', 'Agro Tete antigo')
                ->where('canManage', false));
    }

    public function test_meal_officer_without_province_sees_nothing(): void
    {
        Agrodealer::factory()->create();

        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create(['province_id' => null]))
            ->get('/admin/comerciantes')
            ->assertInertia(fn (Assert $page) => $page->has('agrodealers', 0));
    }

    public function test_create_generates_the_district_code_takes_the_province_and_is_audited(): void
    {
        $mutarara = $this->programmeDistrict('Mutarara');
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post('/admin/comerciantes', [
                'name' => ' Agro Mutarara ', 'district_id' => $mutarara->id, 'address' => 'Mercado', 'contact_phone' => '', 'code' => 'T-MER-X',
            ])
            ->assertRedirect('/admin/comerciantes')
            ->assertSessionHas('success', 'Comerciante Agro Mutarara (MER-MUT-001) criado. O catálogo começa vazio.');

        $agrodealer = Agrodealer::query()->sole();
        $this->assertSame('MER-MUT-001', $agrodealer->code);
        $this->assertSame($mutarara->province_id, $agrodealer->province_id);
        $this->assertSame('Mercado', $agrodealer->address);
        $this->assertNull($agrodealer->contact_phone);

        $audit = AuditLog::query()->sole();
        $this->assertSame('AGRODEALER_CREATED', $audit->action);
        $this->assertSame($manager->id, $audit->actor_id);
        $this->assertEquals(['code' => 'MER-MUT-001', 'name' => 'Agro Mutarara', 'district_id' => $mutarara->id, 'status' => 'ACTIVE'], $audit->metadata);
    }

    public function test_next_agrodealer_of_the_district_gets_the_next_number(): void
    {
        $mutarara = $this->programmeDistrict('Mutarara');
        Agrodealer::factory()->for($mutarara)->create(['code' => 'MER-MUT-004']);
        Agrodealer::factory()->for($mutarara)->create(['code' => 'MER-MUT-OLD']);

        $this->actingAs($this->manager())
            ->post('/admin/comerciantes', ['name' => 'Agro 2', 'district_id' => $mutarara->id]);

        $this->assertSame('MER-MUT-005', Agrodealer::query()->where('name', 'Agro 2')->value('code'));
    }

    #[TestWith(['Mutarara', 'MUT'])]
    #[TestWith(['Chókwè', 'CHO'])]
    #[TestWith(['Ka', 'KAX'])]
    #[TestWith(['Ma-po', 'MAP'])]
    public function test_district_code_prefix(string $district, string $prefix): void
    {
        $this->assertSame($prefix, app(AgrodealerService::class)->districtCodePrefix($district));
    }

    public function test_district_outside_the_programme_is_refused(): void
    {
        $outside = District::factory()->create(['programme_active' => false]);

        $this->actingAs($this->manager())
            ->post('/admin/comerciantes', ['name' => 'Fora', 'district_id' => $outside->id])
            ->assertSessionHasErrors(['district_id' => 'Escolha um distrito do programa.']);

        $this->assertDatabaseCount('agrodealers', 0);
    }

    public function test_name_and_district_are_required(): void
    {
        $this->actingAs($this->manager())
            ->post('/admin/comerciantes', ['name' => ' ', 'district_id' => ''])
            ->assertSessionHasErrors([
                'name' => 'Indique o nome do comerciante.',
                'district_id' => 'Escolha um distrito do programa.',
            ]);
    }

    public function test_meal_officer_cannot_create(): void
    {
        $mutarara = $this->programmeDistrict('Mutarara');

        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $mutarara->province_id]))
            ->post('/admin/comerciantes', ['name' => 'Agro', 'district_id' => $mutarara->id])
            ->assertForbidden();
    }

    public function test_moving_district_keeps_the_code_and_takes_the_new_province(): void
    {
        $mutarara = $this->programmeDistrict('Mutarara', 'Tete');
        $guro = $this->programmeDistrict('Guro', 'Manica');
        $agrodealer = Agrodealer::factory()->for($mutarara)->create(['code' => 'MER-MUT-001', 'province_id' => $mutarara->province_id]);

        $this->actingAs($this->manager())
            ->put("/admin/comerciantes/{$agrodealer->id}", [
                'name' => 'Agro Guro', 'district_id' => $guro->id, 'address' => '', 'contact_phone' => '840000000', 'status' => 'INACTIVE',
            ])
            ->assertSessionHas('success', 'Comerciante Agro Guro atualizado.');

        $agrodealer->refresh();
        $this->assertSame('MER-MUT-001', $agrodealer->code);
        $this->assertSame($guro->id, $agrodealer->district_id);
        $this->assertSame($guro->province_id, $agrodealer->province_id);
        $this->assertSame('840000000', $agrodealer->contact_phone);
        $this->assertSame(RecordStatus::Inactive, $agrodealer->status);
        $this->assertSame('AGRODEALER_UPDATED', AuditLog::query()->sole()->action);
    }

    public function test_update_refuses_an_unknown_status(): void
    {
        $agrodealer = Agrodealer::factory()->create();

        $this->actingAs($this->manager())
            ->put("/admin/comerciantes/{$agrodealer->id}", ['name' => 'Agro', 'district_id' => $agrodealer->district_id, 'status' => 'CLOSED'])
            ->assertSessionHasErrors(['status' => 'Situação inválida.']);
    }
}
