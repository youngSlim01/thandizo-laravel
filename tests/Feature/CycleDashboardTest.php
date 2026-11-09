<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Province;
use App\Models\User;
use App\Models\VoucherTier;
use App\Services\CardService;
use App\Services\CycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MoneyScenario;
use Tests\Concerns\SellsInThePast;
use Tests\TestCase;

/**
 * Painel MEAL do dia-a-dia, por ciclo (CycleDashboardService).
 */
class CycleDashboardTest extends TestCase
{
    use MoneyScenario, RefreshDatabase, SellsInThePast;

    private function openCycleId(): string
    {
        return DB::table('programme_cycles')->where('status', 'OPEN')->value('id');
    }

    public function test_the_open_cycle_shows_use_who_has_not_bought_tiers_and_what_is_owed_to_merchants(): void
    {
        $big = VoucherTier::query()->create(['name' => 'Grupo A', 'amount' => 7500]);
        ['voucher' => $buyer] = $this->householdWithCard(['voucher_tier_id' => $big->id, 'representative_sex' => 'F']);
        $this->householdWithCard(['representative_sex' => 'M']);
        $this->saleOn('2026-10-03 10:00', $buyer, 2);

        $this->actingAs($this->admin)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->component('admin/dashboard')
            ->where('dashboard.cycle.code', 'CIC-01')
            ->where('dashboard.kpis.households', 2)
            ->where('dashboard.kpis.allocated', 13500)
            ->where('dashboard.kpis.used', 2000)
            ->where('dashboard.kpis.not_bought', 1)
            ->where('dashboard.kpis.not_bought_value', 6000)
            ->where('dashboard.kpis.unpaid', 2000)
            ->where('dashboard.by_tier.0.tier', 'Grupo A')
            ->where('dashboard.by_tier.0.bought', 1)
            ->where('dashboard.by_tier.1.bought', 0)
            ->where('dashboard.by_sex.0.bought', 1)
            ->where('dashboard.by_sex.1.bought', 0)
            ->where('dashboard.by_agrodealer.0.to_reconcile', 2000)
            ->where('dashboard.not_bought_by_community.0.not_bought', 1)
            ->where('dashboard.live_fairs.0.district', 'Mutarara')
            ->where('dashboard.live_fairs.0.bought', 1)
            ->where('dashboard.live_fairs.0.missing', 1)
            ->where('dashboard.alerts.0.level', 'critical')
            ->where('dashboard.alerts.0.value', 1)
            ->where('dashboard.activity.0.action', 'CARDS_DISTRIBUTED'));
    }

    public function test_alerts_count_cards_still_to_deliver(): void
    {
        $this->household();
        $cards = app(CardService::class);
        $batch = $cards->generateBatch($this->admin, $this->community->id);
        $cards->markPrinted($this->admin, $batch['batch_id']);

        $this->actingAs($this->admin)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.alerts', fn ($alerts) => collect($alerts)->contains(fn ($a) => $a['key'] === 'to_deliver' && $a['value'] === 1)));
    }

    public function test_a_meal_officer_sees_only_the_province(): void
    {
        $this->householdWithCard();
        $other = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => Province::factory()->create()->id]);
        $own = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]);

        $this->actingAs($own)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page->where('dashboard.kpis.households', 1));
        $this->actingAs($other)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.kpis.households', 0)->where('dashboard.activity', fn ($a) => collect($a)->every(fn ($x) => str_starts_with($x['action'], 'CYCLE_'))));
    }

    public function test_another_cycle_is_chosen_and_compared_with_the_previous_one(): void
    {
        $first = $this->openCycleId();
        $cycles = app(CycleService::class);
        $cycles->close($this->admin, $first, true);
        $cycles->open($this->admin, 'Ciclo 2', null, null);

        $this->actingAs($this->admin)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.cycle.code', 'CIC-02')->where('dashboard.usage.previous.code', 'CIC-01')->has('dashboard.cycles', 2));
        $this->actingAs($this->admin)->get("/admin/dashboard?cycle={$first}")->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.cycle.code', 'CIC-01')->where('dashboard.cycle.status', 'CLOSED'));
        $this->actingAs($this->admin)->get('/admin/dashboard?cycle=abc')->assertSessionHasErrors('cycle');
    }

    public function test_the_first_steps_tick_themselves_and_only_who_sets_up_the_programme_sees_them(): void
    {
        $manager = User::factory()->role(UserRole::MealManager)->create();
        $done = fn () => collect($this->actingAs($manager)->get('/admin/dashboard')->viewData('page')['props']['setup'])->pluck('done', 'key')->all();

        $this->assertSame([
            'settings' => false, 'cycle' => true, 'products' => true, 'merchants' => true, 'merchant_users' => true,
            'households' => false, 'cards' => false,
        ], $done());

        $this->householdWithCard();
        $this->actingAs($manager)->put('/admin/settings/client', ['programme' => 'Projecto LINK GCF']);
        $this->assertNotContains(false, $done());

        $officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]);
        $this->actingAs($officer)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page->where('setup', []));
    }

    public function test_targets_and_the_list_of_who_still_has_to_buy(): void
    {
        DB::table('cycle_districts')->update(['target_households' => 4]);
        ['voucher' => $buyer] = $this->householdWithCard();
        ['household' => $missing] = $this->householdWithCard();
        $this->saleOn('2026-10-03 10:00', $buyer, 1);

        $this->actingAs($this->admin)->get('/admin/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.kpis.target', 4)->where('dashboard.districts.0.target', 4)->where('dashboard.districts.0.households', 2));

        $csv = $this->actingAs($this->admin)->get('/admin/dashboard/faltosos?cycle='.DB::table('programme_cycles')->value('id').'&district='.$this->district->id)
            ->assertOk()->streamedContent();
        $this->assertStringContainsString($missing->household_code, $csv);
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1);
    }
}
