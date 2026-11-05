<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Equivalente PHP de create_household, update_household, delete_household,
 * set_household_status e get_household_history (supabase/tests/0018 e 0024).
 */
class HouseholdManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->role(UserRole::MealManager)->create();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function newHousehold(Community $community, array $overrides = []): array
    {
        return [
            'household_code' => '1234af000001', 'first_name' => ' Ana ', 'last_name' => 'Silva', 'sex' => 'F',
            'birth_date' => '1985-06-15', 'relationship' => 'Chefe', 'community_id' => $community->id, ...$overrides,
        ];
    }

    public function test_a_household_is_created_by_hand_with_the_location_of_the_community(): void
    {
        $community = Community::factory()->create();

        $this->actingAs($this->manager)->postJson('/admin/households', $this->newHousehold($community))
            ->assertOk()->assertJson(['household_code' => '1234AF000001']);

        $household = Household::query()->sole();
        $this->assertSame('Ana Silva', $household->representative_name);
        $this->assertSame($community->locality_id, $household->locality_id);
        $this->assertSame($community->district_id, $household->district_id);
        $this->assertNotNull($household->province_id);
        $this->assertSame('HOUSEHOLD_CREATED', AuditLog::query()->sole()->action);
    }

    public function test_creation_rules(): void
    {
        $community = Community::factory()->create();
        Household::factory()->inCommunity($community)->create(['household_code' => '1234AF000009']);
        $post = fn (array $overrides) => $this->actingAs($this->manager)->postJson('/admin/households', $this->newHousehold($community, $overrides));

        $post(['household_code' => '1234AF000009'])->assertJsonValidationErrors(['message' => 'Já existe um agregado com este CODIGO AF.']);
        $post(['household_code' => '12AF1'])->assertJsonValidationErrors(['message' => 'O CODIGO AF tem o formato 1234AF000001.']);
        $post(['sex' => 'X'])->assertJsonValidationErrors(['message' => 'Género inválido: use Feminino ou Masculino.']);
        $post(['birth_date' => '2999-01-01'])->assertJsonValidationErrors(['message' => 'A data de nascimento tem de ser entre 1900 e hoje.']);
        $post(['community_id' => null])->assertJsonValidationErrors(['message' => 'Bairro não encontrado.']);
        $post(['relationship' => ''])->assertJsonValidationErrors(['message' => 'Preencha nome, apelido e grau de parentesco.']);
        $this->assertSame(1, Household::query()->count());
    }

    public function test_a_meal_officer_cannot_create_or_edit(): void
    {
        $officer = User::factory()->role(UserRole::MealOfficer)->create();
        $household = Household::factory()->create();

        $this->actingAs($officer)->postJson('/admin/households', $this->newHousehold($household->community))->assertForbidden();
        $this->actingAs($officer)->patchJson("/admin/households/{$household->id}", ['changes' => ['first_name' => 'X'], 'reason' => 'r'])->assertForbidden();
        $this->actingAs($officer)->putJson("/admin/households/{$household->id}/status", ['status' => 'INACTIVE', 'reason' => 'r'])->assertForbidden();
    }

    public function test_edits_need_a_reason_and_the_code_is_fixed(): void
    {
        $household = Household::factory()->create();
        $patch = fn (array $body) => $this->actingAs($this->manager)->patchJson("/admin/households/{$household->id}", $body);

        $patch(['changes' => ['first_name' => 'Rosa'], 'reason' => ' '])->assertJsonValidationErrors(['message' => 'Indique o motivo da alteração.']);
        $patch(['changes' => ['household_code' => '1234AF000099'], 'reason' => 'erro'])->assertJsonValidationErrors(['message' => 'Esse dado não pode ser alterado (o CODIGO AF é fixo).']);
    }

    public function test_only_real_changes_are_saved_and_audited_with_old_and_new_value(): void
    {
        $household = Household::factory()->create(['representative_first_name' => 'Ana', 'representative_last_name' => 'Silva', 'representative_name' => 'Ana Silva']);
        $patch = fn (array $changes) => $this->actingAs($this->manager)->patchJson("/admin/households/{$household->id}", ['changes' => $changes, 'reason' => 'nome errado na lista']);

        $patch(['first_name' => 'Rosa'])->assertJson(['changed' => true, 'changes' => [
            'representative_first_name' => ['old' => 'Ana', 'new' => 'Rosa'],
            'representative_name' => ['old' => 'Ana Silva', 'new' => 'Rosa Silva'],
        ]]);
        $patch(['birth_date' => '02/03/1970'])->assertJson(['changed' => true]);
        $patch(['first_name' => 'Rosa'])->assertJson(['changed' => false]);

        $this->assertSame('1970-03-02', $household->fresh()->representative_birth_date->toDateString());
        $this->assertSame(2, AuditLog::query()->where('action', 'HOUSEHOLD_UPDATED')->count());
    }

    public function test_moving_to_another_community_moves_the_whole_location(): void
    {
        $household = Household::factory()->create();
        $elsewhere = Community::factory()->create();

        $this->actingAs($this->manager)
            ->patchJson("/admin/households/{$household->id}", ['changes' => ['community_id' => $elsewhere->id], 'reason' => 'mudou-se'])
            ->assertJson(['changed' => true]);

        $household->refresh();
        $this->assertSame($elsewhere->id, $household->community_id);
        $this->assertSame($elsewhere->district_id, $household->district_id);
        $this->assertSame($elsewhere->locality_id, $household->locality_id);
    }

    public function test_deactivation_and_reactivation_need_a_reason_and_are_in_the_history(): void
    {
        $household = Household::factory()->create();
        $put = fn (array $body) => $this->actingAs($this->manager)->putJson("/admin/households/{$household->id}/status", $body);

        $put(['status' => 'INACTIVE', 'reason' => ''])->assertJsonValidationErrors(['message' => 'Indique o motivo.']);
        $put(['status' => 'INACTIVE', 'reason' => 'mudou-se'])->assertJson(['changed' => true]);
        $put(['status' => 'INACTIVE', 'reason' => 'outra vez'])->assertJson(['changed' => false]);

        $household->refresh();
        $this->assertSame(RecordStatus::Inactive, $household->status);
        $this->assertSame('mudou-se', $household->status_reason);
        $this->assertSame($this->manager->id, $household->status_changed_by);

        $this->travel(1)->minutes();
        $put(['status' => 'ACTIVE', 'reason' => 'voltou'])->assertJson(['changed' => true]);
        $this->actingAs($this->manager)->getJson("/admin/households/{$household->id}/history")
            ->assertJsonCount(2)
            ->assertJsonPath('0.action', 'HOUSEHOLD_REACTIVATED')
            ->assertJsonPath('0.reason', 'voltou')
            ->assertJsonPath('0.by', $this->manager->full_name)
            ->assertJsonPath('1.action', 'HOUSEHOLD_DEACTIVATED');
    }

    public function test_a_household_registered_by_mistake_is_deleted_with_a_copy_in_the_audit(): void
    {
        $household = Household::factory()->create(['household_code' => '1234AF000007']);

        $this->actingAs($this->manager)->deleteJson("/admin/households/{$household->id}", ['reason' => ''])
            ->assertJsonValidationErrors(['message' => 'Indique o motivo da alteração.']);
        $this->actingAs($this->manager)->deleteJson("/admin/households/{$household->id}", ['reason' => 'registado duas vezes'])
            ->assertOk();

        $this->assertModelMissing($household);
        $audit = AuditLog::query()->where('action', 'HOUSEHOLD_DELETED')->sole();
        $this->assertSame('1234AF000007', $audit->metadata['household']['household_code']);
    }
}
