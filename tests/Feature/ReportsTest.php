<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
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

    private function search(User $user, array $filters, bool $withItems = false): TestResponse
    {
        return $this->actingAs($user)->postJson('/transactions/search', ['filters' => $filters, 'with_items' => $withItems]);
    }

    public function test_transaction_search_with_period_status_and_summary(): void
    {
        $this->search($this->admin, ['from' => '2026-10-01', 'to' => '2026-10-31'], true)
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('summary.total_amount', 3000)
            ->assertJsonPath('summary.households', 1)
            ->assertJsonPath('rows.0.items.0.product', 'Semente')
            ->assertJsonCount(1, 'agrodealers');
        $this->search($this->admin, ['from' => '2026-10-04'])->assertJsonPath('total', 1);
        $this->search($this->admin, ['status' => 'GONE'])->assertJson(['code' => 'INVALID_STATUS']);
        $this->search($this->operator, [])->assertForbidden();

        $finance = User::factory()->role(UserRole::FinanceOfficer)->create();
        $this->actingAs($finance)->get('/finance/transactions')->assertInertia(fn (Assert $page) => $page->component('transactions')->where('area', 'finance'));
        $this->actingAs($finance)->getJson('/transactions/households/'.DB::table('households')->value('id'))->assertOk()->assertJsonPath('cards.0.current_balance', '3000.00');
    }

    public function test_agrodealer_report_is_recorded_as_printed(): void
    {
        $this->actingAs($this->admin)->postJson('/admin/reports/agrodealer', ['agrodealer_id' => $this->agrodealer->id, 'from' => '2026-10-01', 'to' => '2026-10-31'])
            ->assertOk()->assertJsonPath('totals.transactions', 2)->assertJsonPath('totals.voucher_amount', 3000)->assertJsonPath('by_product.0.quantity', 3);

        $this->assertSame(1, DB::table('report_prints')->count());
        $this->actingAs($this->admin)->get('/admin/reports')->assertInertia(fn (Assert $page) => $page
            ->component('admin/reports')->where('prints.0.agrodealer', $this->agrodealer->name)->where('prints.0.printed_by_role', 'Administrador do sistema'));
    }
}
