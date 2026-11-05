<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MoneyScenario;
use Tests\Concerns\SellsInThePast;
use Tests\TestCase;

/**
 * Equivalente PHP de supabase/tests/0012, 0022, 0023 (painéis) e 0026
 * (pesquisa de transacções).
 */
class ReportsTest extends TestCase
{
    use MoneyScenario, RefreshDatabase, SellsInThePast;

    protected function setUp(): void
    {
        parent::setUp();
        $voucher = $this->householdWithCard()['voucher'];
        $this->saleOn('2026-10-03 10:00', $voucher, 2);
        $this->saleOn('2026-10-04 10:00', $voucher, 1);
    }

    public function test_transaction_search_with_period_status_and_summary(): void
    {
        $this->rpc($this->admin, 'search_transactions', ['filters' => ['from' => '2026-10-01', 'to' => '2026-10-31'], 'with_items' => true])
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('summary.total_amount', 3000)
            ->assertJsonPath('summary.households', 1)
            ->assertJsonPath('rows.0.items.0.product', 'Semente')
            ->assertJsonCount(1, 'agrodealers');
        $this->rpc($this->admin, 'search_transactions', ['filters' => ['from' => '2026-10-04']])->assertJsonPath('total', 1);
        $this->rpc($this->admin, 'search_transactions', ['filters' => ['status' => 'GONE']])->assertJson(['code' => 'INVALID_STATUS']);
        $this->rpc($this->operator, 'search_transactions')->assertJson(['code' => 'TRANSACTIONS_NOT_ALLOWED']);
    }

    public function test_agrodealer_report_is_recorded_as_printed(): void
    {
        $this->rpc($this->admin, 'agrodealer_transactions_report', ['agrodealer_id' => $this->agrodealer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertOk()->assertJsonPath('totals.transactions', 2)->assertJsonPath('totals.voucher_amount', 3000)->assertJsonPath('by_product.0.quantity', 3);

        $this->assertSame(1, DB::table('report_prints')->count());
        $this->rpc($this->admin, 'list_report_prints')->assertJsonPath('0.agrodealer', $this->agrodealer->name);
    }

    public function test_meal_dashboard_of_a_month(): void
    {
        $this->rpc($this->admin, 'meal_dashboard_month', ['month' => '2026-10'])
            ->assertOk()
            ->assertJsonPath('kpis.transactions', 2)
            ->assertJsonPath('values.total_amount', 3000)
            ->assertJsonPath('by_agrodealer.0.transactions', 2)
            ->assertJsonCount(31, 'daily');
        $this->rpc($this->admin, 'meal_dashboard_month', ['month' => '2026-13'])->assertJson(['code' => 'INVALID_PERIOD']);
    }

    public function test_finance_dashboard_is_not_for_a_meal_officer(): void
    {
        $this->rpc(User::factory()->role(UserRole::FinanceOfficer)->create(), 'finance_dashboard_month', ['month' => '2026-10'])
            ->assertOk()->assertJsonPath('trend.5.transactions', 2);
        $this->rpc(User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]), 'finance_dashboard_month')
            ->assertJson(['code' => 'DASHBOARD_NOT_ALLOWED']);
    }
}
