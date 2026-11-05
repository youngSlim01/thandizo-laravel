<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\AgrodealerPos;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Equivalente PHP de save_pos e next_pos_code (20261008114300) e dos casos de
 * POS de supabase/tests/0020.
 */
class AgrodealerPosTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->role(UserRole::MealManager)->create();
    }

    public function test_pos_code_follows_the_agrodealer_code_and_is_audited(): void
    {
        $agrodealer = Agrodealer::factory()->create(['code' => 'AGD-MUT-001']);
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post("/admin/agrodealers/{$agrodealer->id}/pos", ['device_label' => ' Tablet 1 ', 'pos_code' => 'T-POS-A'])
            ->assertRedirect('/admin/agrodealers')
            ->assertSessionHas('success', 'POS POS-MUT-001-01 criado.');

        $this->actingAs($manager)
            ->post("/admin/agrodealers/{$agrodealer->id}/pos", ['device_label' => '']);

        $this->assertSame(['POS-MUT-001-01', 'POS-MUT-001-02'], $agrodealer->pos()->orderBy('pos_code')->pluck('pos_code')->all());
        $this->assertSame('Tablet 1', $agrodealer->pos()->where('pos_code', 'POS-MUT-001-01')->value('device_label'));
        $this->assertNull($agrodealer->pos()->where('pos_code', 'POS-MUT-001-02')->value('device_label'));

        $audit = AuditLog::query()->where('action', 'POS_CREATED')->first();
        $this->assertSame($manager->id, $audit->actor_id);
        $this->assertSame('POS', $audit->entity_type);
    }

    public function test_update_changes_label_and_status_but_not_the_code(): void
    {
        $pos = AgrodealerPos::factory()->create(['pos_code' => 'POS-MUT-001-01']);

        $this->actingAs($this->manager())
            ->put("/admin/agrodealers/{$pos->agrodealer_id}/pos/{$pos->id}", ['device_label' => 'Tablet novo', 'status' => 'INACTIVE', 'pos_code' => 'X'])
            ->assertSessionHas('success', 'POS POS-MUT-001-01 atualizado.');

        $pos->refresh();
        $this->assertSame('POS-MUT-001-01', $pos->pos_code);
        $this->assertSame('Tablet novo', $pos->device_label);
        $this->assertSame(RecordStatus::Inactive, $pos->status);
        $this->assertSame('POS_UPDATED', AuditLog::query()->sole()->action);
    }

    public function test_pos_of_another_agrodealer_is_not_found(): void
    {
        $pos = AgrodealerPos::factory()->create();
        $other = Agrodealer::factory()->create();

        $this->actingAs($this->manager())
            ->put("/admin/agrodealers/{$other->id}/pos/{$pos->id}", ['device_label' => 'X', 'status' => 'ACTIVE'])
            ->assertNotFound();

        $this->assertSame($pos->agrodealer_id, $pos->fresh()->agrodealer_id);
    }

    public function test_meal_officer_cannot_create_a_pos(): void
    {
        $agrodealer = Agrodealer::factory()->create();

        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $agrodealer->province_id]))
            ->post("/admin/agrodealers/{$agrodealer->id}/pos", ['device_label' => 'Tablet'])
            ->assertForbidden();

        $this->assertDatabaseCount('agrodealer_pos', 0);
    }

    public function test_update_refuses_an_unknown_status(): void
    {
        $pos = AgrodealerPos::factory()->create();

        $this->actingAs($this->manager())
            ->put("/admin/agrodealers/{$pos->agrodealer_id}/pos/{$pos->id}", ['device_label' => 'X', 'status' => 'LOST'])
            ->assertSessionHasErrors(['status' => 'Situação inválida.']);
    }
}
