<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\DomainRuleException;
use App\Models\District;
use App\Models\Province;
use App\Models\User;
use App\Services\CycleDashboardService;
use App\Services\CyclePlanService;
use App\Services\PosService;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MoneyScenario;
use Tests\TestCase;

/**
 * Metas e feiras por distrito no ciclo (CyclePlanService).
 */
class CyclePlanTest extends TestCase
{
    use MoneyScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // O cenário traz uma feira o ano todo: aqui cada teste marca a sua.
        DB::table('cycle_districts')->delete();
    }

    private function cycleId(): string
    {
        return DB::table('programme_cycles')->where('status', 'OPEN')->value('id');
    }

    private function plan(User $user, array $data, ?string $district = null)
    {
        return $this->actingAs($user)->put("/admin/cycles/{$this->cycleId()}/districts/".($district ?? $this->district->id), $data);
    }

    public function test_both_meal_profiles_plan_and_the_officer_only_in_the_province(): void
    {
        $officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]);
        $elsewhere = District::factory()->inProgramme()->create(['province_id' => Province::factory()->create()->id]);

        $this->plan($officer, ['target_households' => 450, 'fair_starts_on' => now()->addDays(3)->toDateString(), 'fair_ends_on' => now()->addDays(5)->toDateString()])
            ->assertSessionHas('success');
        $this->assertSame(450, (int) DB::table('cycle_districts')->value('target_households'));
        $this->plan($officer, ['target_households' => 10], $elsewhere->id)->assertSessionHasErrors(['rule' => 'Distrito não encontrado.']);
        $this->actingAs($officer)->get('/admin/cycles')->assertInertia(fn (Assert $page) => $page
            ->where('plan.0.district', 'Mutarara')->where('plan.0.fair_status', 'planned')->has('plan', 1));
    }

    public function test_fair_dates_must_be_complete_ordered_and_inside_the_cycle(): void
    {
        DB::table('programme_cycles')->where('id', $this->cycleId())->update(['ends_on' => now()->addDays(30)->toDateString()]);
        $tomorrow = now()->addDay()->toDateString();

        $this->plan($this->admin, ['fair_starts_on' => $tomorrow])->assertSessionHasErrors(['rule' => 'Indique o início e o fim da feira.']);
        $this->plan($this->admin, ['fair_starts_on' => $tomorrow, 'fair_ends_on' => now()->toDateString()])->assertSessionHasErrors('rule');
        $this->plan($this->admin, ['fair_starts_on' => $tomorrow, 'fair_ends_on' => now()->addDays(40)->toDateString()])
            ->assertSessionHasErrors(['rule' => 'As datas da feira têm de ficar dentro das datas do ciclo.']);
        $this->plan($this->admin, ['target_households' => 'muitos'])->assertSessionHasErrors(['rule' => 'A meta é um número inteiro de agregados.']);
    }

    public function test_once_the_fair_started_only_the_manager_changes_its_dates_and_with_a_reason(): void
    {
        $officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]);
        $manager = User::factory()->role(UserRole::MealManager)->create();
        $started = ['fair_starts_on' => now()->subDay()->toDateString(), 'fair_ends_on' => now()->addDay()->toDateString()];
        $this->plan($manager, $started)->assertSessionHas('success');
        $longer = [...$started, 'fair_ends_on' => now()->addDays(2)->toDateString()];

        $this->plan($officer, $longer)->assertSessionHasErrors(['rule' => 'Esta feira já começou: só o gestor MEAL pode mudar as datas.']);
        $this->plan($officer, [...$started, 'target_households' => 300])->assertSessionHas('success');
        $this->plan($manager, $longer)->assertSessionHasErrors(['rule' => 'Indique o motivo da mudança de datas (decisão da direcção do programa).']);
        $this->plan($manager, [...$longer, 'reason' => 'Decisão da direcção: mais um dia'])->assertSessionHas('success');
        $this->assertSame('Decisão da direcção: mais um dia', DB::table('audit_logs')->where('action', 'CYCLE_PLAN_UPDATED')->orderByDesc('id')->value('metadata->reason'));
    }

    public function test_the_cycle_dates_cannot_leave_a_fair_outside(): void
    {
        app(CyclePlanService::class)->save($this->admin, $this->cycleId(), $this->district->id, ['fair_starts_on' => now()->addDays(10)->toDateString(), 'fair_ends_on' => now()->addDays(12)->toDateString()]);
        $cycle = DB::table('programme_cycles')->where('id', $this->cycleId())->first();

        $this->actingAs($this->admin)->put("/admin/cycles/{$cycle->id}", ['name' => $cycle->name, 'starts_on' => $cycle->starts_on, 'ends_on' => now()->addDays(5)->toDateString()])
            ->assertSessionHasErrors(['rule' => 'Há feiras marcadas fora destas datas. Mude primeiro as datas das feiras.']);
    }

    private function preview(): array
    {
        $voucher = $this->householdWithCard()['voucher'];

        return app(PosService::class)->preview($this->operator, $voucher->voucher_code, $this->agrodealer->id, $this->pos->id, $this->operator->id,
            [['product_id' => $this->product->id, 'quantity' => 1]], null, 0);
    }

    public function test_the_pos_sells_only_on_the_days_of_the_district_fair(): void
    {
        $this->assertRuleFails('FAIR_NOT_RUNNING', fn () => $this->preview());

        $plans = app(CyclePlanService::class);
        $plans->save($this->admin, $this->cycleId(), $this->district->id, ['fair_starts_on' => now('Africa/Maputo')->addDays(2)->toDateString(), 'fair_ends_on' => now('Africa/Maputo')->addDays(4)->toDateString()]);
        try {
            $this->preview();
            $this->fail('A venda antes da feira devia ser recusada.');
        } catch (DomainRuleException $e) {
            $this->assertSame('FAIR_NOT_RUNNING', $e->errorCode);
            $this->assertStringStartsWith('A feira está marcada de ', $e->details);
        }

        DB::table('cycle_districts')->update(['fair_starts_on' => now('Africa/Maputo')->toDateString(), 'fair_ends_on' => now('Africa/Maputo')->addDay()->toDateString()]);
        $this->assertSame(1000.0, (float) $this->preview()['total_amount']);

        DB::table('cycle_districts')->update(['fair_starts_on' => now('Africa/Maputo')->subDays(5)->toDateString(), 'fair_ends_on' => now('Africa/Maputo')->subDays(3)->toDateString()]);
        $this->assertRuleFails('FAIR_NOT_RUNNING', fn () => $this->preview());
    }

    public function test_a_fair_without_prices_is_flagged_in_the_plan_and_on_the_dashboard(): void
    {
        $this->plan($this->admin, ['fair_starts_on' => now()->addDays(3)->toDateString(), 'fair_ends_on' => now()->addDays(5)->toDateString()]);
        $this->actingAs($this->admin)->get('/admin/cycles')
            ->assertInertia(fn (Assert $page) => $page->where('plan.0.priced_products', 1));

        app(PriceService::class)->setPrice($this->admin, $this->product, $this->district, null);

        $this->actingAs($this->admin)->get('/admin/cycles')
            ->assertInertia(fn (Assert $page) => $page->where('plan.0.priced_products', 0));
        $alerts = collect(app(CycleDashboardService::class)->forViewer($this->admin, $this->cycleId())['alerts']);
        $this->assertSame(1, $alerts->firstWhere('key', 'fair_no_prices')['value']);
    }
}
