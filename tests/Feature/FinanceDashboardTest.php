<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\SettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MoneyScenario;
use Tests\Concerns\SellsInThePast;
use Tests\TestCase;

/**
 * Painel das Finanças: estado actual das reconciliações, prazo de validação
 * (Configuração) e o que se deve por comerciante e por ciclo.
 */
class FinanceDashboardTest extends TestCase
{
    use MoneyScenario, RefreshDatabase, SellsInThePast;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->finance = User::factory()->role(UserRole::FinanceOfficer)->create();
        $voucher = $this->householdWithCard()['voucher'];
        $this->saleOn('2026-10-03 10:00', $voucher, 2);
        $this->saleOn('2026-10-20 10:00', $voucher, 1);
    }

    /**
     * Reconciliação de Outubro enviada às Finanças pelo gestor MEAL, há $daysAgo dias.
     */
    private function sentToFinance(int $daysAgo, string $from = '2026-10-01', string $to = '2026-10-10'): string
    {
        $manager = User::factory()->role(UserRole::MealManager)->create();
        $settlements = app(SettlementService::class);
        $id = $settlements->create($manager, $this->agrodealer->id, $from, $to);
        $this->travelTo(now()->subDays($daysAgo));
        $settlements->submit($manager, $id);
        $this->travelBack();

        return $id;
    }

    public function test_the_inbox_shows_what_waits_for_validation_and_what_is_late(): void
    {
        $this->sentToFinance(20);

        $this->actingAs($this->finance)->get('/finance')->assertInertia(fn (Assert $page) => $page
            ->component('finance/dashboard')
            ->where('dashboard.deadline_days', 15)
            ->where('dashboard.kpis.awaiting', 1)
            ->where('dashboard.kpis.awaiting_amount', 2000)
            ->where('dashboard.kpis.overdue', 1)
            ->where('dashboard.kpis.oldest_days', 20)
            ->where('dashboard.kpis.not_reconciled_amount', 1000)
            ->where('dashboard.inbox.0.overdue', true)
            ->where('dashboard.by_agrodealer.0.awaiting_finance', 2000)
            ->where('dashboard.by_agrodealer.0.not_reconciled', 1000)
            ->where('dashboard.by_cycle.0.used', 3000)
            ->where('dashboard.by_cycle.0.pending', 3000));
    }

    public function test_the_deadline_comes_from_the_settings_and_validation_moves_the_money(): void
    {
        $id = $this->sentToFinance(10);
        $this->actingAs($this->admin)->put('/admin/settings/settlement-deadline', ['days' => 7])->assertSessionHas('success');
        $this->actingAs($this->admin)->put('/admin/settings/settlement-deadline', ['days' => 0])
            ->assertSessionHasErrors(['rule' => 'O prazo de validação tem de ser entre 1 e 90 dias.']);
        $this->actingAs($this->finance)->get('/finance')->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.deadline_days', 7)->where('dashboard.kpis.overdue', 1));

        app(SettlementService::class)->financeValidate($this->finance, $id);
        $this->actingAs($this->finance)->get('/finance')->assertInertia(fn (Assert $page) => $page
            ->where('dashboard.kpis.awaiting', 0)->where('dashboard.kpis.validated_amount', 2000)->where('dashboard.by_cycle.0.validated', 2000));
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'SETTLEMENT_DEADLINE_UPDATED')->count());
    }

    public function test_a_meal_officer_of_a_province_cannot_open_it(): void
    {
        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]))->get('/finance')
            ->assertForbidden();
    }
}
