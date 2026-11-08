<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Household;
use App\Models\User;
use App\Models\VoucherTier;
use App\Services\CardService;
use App\Services\CycleService;
use App\Services\VoucherTierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MoneyScenario;
use Tests\TestCase;

/**
 * Escalões do voucher: o valor de cada agregado vem do seu escalão, na
 * activação do cartão e em cada recarga.
 */
class VoucherTiersTest extends TestCase
{
    use MoneyScenario, RefreshDatabase;

    private function tiers(): VoucherTierService
    {
        return app(VoucherTierService::class);
    }

    private function tier(string $name, float $amount): VoucherTier
    {
        return $this->tiers()->create($this->admin, ['name' => $name, 'amount' => $amount]);
    }

    private function standard(): VoucherTier
    {
        return VoucherTier::query()->where('is_default', true)->sole();
    }

    public function test_the_meal_manager_and_the_administrator_manage_tiers_and_nobody_else(): void
    {
        $manager = User::factory()->role(UserRole::MealManager)->create();
        $officer = User::factory()->role(UserRole::MealOfficer)->create();

        $this->actingAs($manager)->post('/admin/settings/tiers', ['name' => 'Grupo A', 'amount' => '7 500'])
            ->assertSessionHas('success', 'Escalão Grupo A criado.');
        $this->assertSame('7500.00', VoucherTier::query()->where('name', 'Grupo A')->value('amount'));
        $this->actingAs($officer)->post('/admin/settings/tiers', ['name' => 'Grupo B', 'amount' => 3000])->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()
            ->assertInertia(fn ($page) => $page->where('tiers.0.name', 'Grupo A')->where('tiers.1.name', 'Padrão')->where('tiers.1.is_default', true));
    }

    public function test_tier_rules(): void
    {
        $this->assertRuleFails('TIER_AMOUNT_TAKEN', fn () => $this->tier('Outro', 6000));
        $this->assertRuleFails('TIER_AMOUNT_INVALID', fn () => $this->tier('Zero', 0));
        $this->assertRuleFails('TIER_NAME_TAKEN', fn () => $this->tier('padrao', 3000));
        $this->assertRuleFails('TIER_IS_DEFAULT', fn () => $this->tiers()->setStatus($this->admin, $this->standard(), RecordStatus::Inactive));

        $small = $this->tier('Grupo C', 3000);
        $this->household(['voucher_tier_id' => $small->id]);
        $this->actingAs($this->admin)->put("/admin/settings/tiers/{$small->id}/status", ['status' => 'INACTIVE'])
            ->assertSessionHasErrors(['rule' => 'Este escalão ainda tem 1 agregado(s) ativo(s). Mude-os primeiro para outro escalão.']);

        $empty = $this->tier('Grupo D', 2000);
        $this->tiers()->setStatus($this->admin, $empty, RecordStatus::Inactive);
        $this->tier('Grupo E', 2000);
        $this->assertRuleFails('TIER_AMOUNT_TAKEN', fn () => $this->tiers()->setStatus($this->admin, $empty, RecordStatus::Active));
        $this->assertRuleFails('TIER_INACTIVE', fn () => $this->tiers()->makeDefault($this->admin, $empty));

        $this->actingAs($this->admin)->put("/admin/settings/tiers/{$small->id}/default")->assertSessionHas('success');
        $this->assertSame(['Grupo C'], VoucherTier::query()->where('is_default', true)->pluck('name')->all());
    }

    public function test_each_card_is_activated_and_recharged_with_its_tier_value_and_a_new_value_only_counts_from_then_on(): void
    {
        $big = $this->tier('Grupo A', 7500);
        ['household' => $rich, 'voucher' => $richCard] = $this->householdWithCard(['voucher_tier_id' => $big->id]);
        ['voucher' => $standardCard] = $this->householdWithCard();
        $this->assertSame(['7500.00', '6000.00'], [$richCard->current_balance, $standardCard->current_balance]);
        $this->assertSame('7500.00', DB::table('voucher_ledger')->where('voucher_id', $richCard->id)->value('amount'));
        $this->assertSame($big->id, DB::table('cycle_households')->where('household_id', $rich->id)->value('voucher_tier_id'));

        $this->actingAs($this->admin)->put("/admin/settings/tiers/{$big->id}", ['name' => 'Grupo A', 'amount' => 8000])->assertSessionHas('success');
        $this->assertSame('7500.00', DB::table('vouchers')->where('id', $richCard->id)->value('current_balance'));

        $cycles = app(CycleService::class);
        $cycles->close($this->admin, DB::table('programme_cycles')->where('status', 'OPEN')->value('id'), true);
        $cycle = $cycles->open($this->admin, 'Ciclo 2', null, null)['cycle_id'];
        $preview = $cycles->allocate($this->admin, $cycle, Household::query()->pluck('id')->all(), null, false);
        $this->assertSame(14000.0, $preview['total_amount']);
        $this->assertEquals([
            ['tier' => 'Grupo A', 'amount' => 8000, 'households' => 1, 'total_amount' => 8000],
            ['tier' => 'Padrão', 'amount' => 6000, 'households' => 1, 'total_amount' => 6000],
        ], $preview['by_tier']);

        $cycles->allocate($this->admin, $cycle, [$rich->id], null, true);
        $card = DB::table('vouchers')->where('id', $richCard->id)->first();
        $this->assertSame(['8000.00', '8000.00'], [$card->current_balance, $card->initial_value]);
        $summary = $cycles->summary($this->admin, $cycle);
        $this->assertSame([['tier' => 'Grupo A', 'households' => 1, 'households_bought' => 0, 'allocated' => 8000.0, 'spent' => 0.0, 'unused' => 8000.0]], $summary['by_tier']);
    }

    public function test_the_value_shown_to_deliver_is_the_tier_value(): void
    {
        $small = $this->tier('Grupo C', 3000);
        $this->household(['voucher_tier_id' => $small->id]);
        $batch = app(CardService::class)->generateBatch($this->admin, $this->community->id);

        $card = app(CardService::class)->batchCards($this->admin, $batch['batch_id'])['cards'][0];
        $this->assertSame(['Grupo C', '3000.00'], [$card['tier_name'], $card['tier_amount']]);
    }

    public function test_a_household_is_registered_in_a_tier_and_a_change_of_tier_is_in_its_history(): void
    {
        $big = $this->tier('Grupo A', 7500);
        $household = $this->household();
        $this->assertSame($this->standard()->id, $household->voucher_tier_id);

        $this->actingAs($this->admin)->patchJson("/admin/households/{$household->id}", [
            'changes' => ['voucher_tier_id' => $big->id], 'reason' => 'Reavaliação de vulnerabilidade',
        ])->assertOk()->assertJson(['changed' => true]);

        $this->assertSame($big->id, $household->fresh()->voucher_tier_id);
        $this->actingAs($this->admin)->getJson("/admin/households/{$household->id}/history")
            ->assertJsonPath('0.changes.voucher_tier.old', 'Padrão')->assertJsonPath('0.changes.voucher_tier.new', 'Grupo A');
        $this->actingAs($this->admin)->getJson("/admin/households/{$household->id}")
            ->assertJson(['voucher_tier' => 'Grupo A', 'voucher_tier_amount' => 7500]);

        $inactive = $this->tier('Antigo', 1000);
        $this->tiers()->setStatus($this->admin, $inactive, RecordStatus::Inactive);
        $this->actingAs($this->admin)->patchJson("/admin/households/{$household->id}", [
            'changes' => ['voucher_tier_id' => $inactive->id], 'reason' => 'Teste',
        ])->assertUnprocessable()->assertJson(['code' => 'TIER_INACTIVE']);
    }
}
