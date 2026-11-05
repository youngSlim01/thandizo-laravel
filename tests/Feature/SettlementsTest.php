<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MoneyScenario;
use Tests\Concerns\SellsInThePast;
use Tests\TestCase;

/**
 * Equivalente PHP de supabase/tests/0013_agrodealer_settlements_tests.sql e
 * do circuito oficial → gestor MEAL → Finanças (20261008114600).
 */
class SettlementsTest extends TestCase
{
    use MoneyScenario, RefreshDatabase, SellsInThePast;

    private User $officer;

    private User $manager;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]);
        $this->manager = User::factory()->role(UserRole::MealManager)->create();
        $this->finance = User::factory()->role(UserRole::FinanceOfficer)->create();
        $voucher = $this->householdWithCard()['voucher'];
        $this->saleOn('2026-10-03 10:00', $voucher, 2);
        $this->saleOn('2026-10-20 23:30', $voucher, 1);
        $this->saleOn('2026-11-01 00:30', $voucher, 1);
    }

    private function create(User $user, string $from = '2026-10-01', string $to = '2026-10-31'): TestResponse
    {
        return $this->rpc($user, 'create_agrodealer_settlement', ['agrodealer_id' => $this->agrodealer->id, 'period_start' => $from, 'period_end' => $to]);
    }

    public function test_a_settlement_takes_the_confirmed_sales_of_the_period_in_mozambique_time(): void
    {
        $id = $this->create($this->officer)->assertOk()->json();

        $batch = DB::table('settlement_batches')->where('id', $id)->first();
        $this->assertSame([2, '3000.00', '3000.00', 'RECONCILED'], [$batch->transaction_count, $batch->voucher_amount, $batch->payable_amount, $batch->status]);
        $this->create($this->officer)->assertJson(['code' => 'SETTLEMENT_ALREADY_EXISTS']);
        $this->create($this->officer, '2026-11-01', '2099-01-01')->assertJson(['code' => 'SETTLEMENT_PERIOD_NOT_CLOSED']);
        $this->create($this->officer, '2026-09-01', '2026-09-30')->assertJson(['code' => 'NO_SETTLEMENT_TRANSACTIONS']);
    }

    public function test_officer_then_manager_then_finance(): void
    {
        $id = $this->create($this->officer)->json();

        $this->rpc($this->officer, 'submit_agrodealer_settlement', ['settlement_id' => $id])->assertExactJson(['PENDING_MANAGER']);
        $this->rpc($this->officer, 'manager_approve_settlement', ['settlement_id' => $id])->assertJson(['code' => 'SETTLEMENT_APPROVAL_NOT_ALLOWED']);
        $this->rpc($this->finance, 'finance_validate_settlement', ['settlement_id' => $id])->assertJson(['code' => 'SETTLEMENT_NOT_READY_FOR_VALIDATION']);
        $this->rpc($this->manager, 'manager_approve_settlement', ['settlement_id' => $id])->assertOk();
        $this->rpc($this->finance, 'finance_validate_settlement', ['settlement_id' => $id])->assertOk();

        $this->assertSame('VALIDATED', DB::table('settlement_batches')->where('id', $id)->value('status'));
        $this->rpc($this->finance, 'get_settlement_transactions', ['settlement_id' => $id])->assertJsonCount(2);
    }

    public function test_a_manager_sends_straight_to_finance(): void
    {
        $id = $this->create($this->manager)->json();

        $this->rpc($this->manager, 'submit_agrodealer_settlement', ['settlement_id' => $id])->assertExactJson(['SUBMITTED_TO_FINANCE']);
    }

    public function test_a_returned_settlement_frees_its_sales_for_a_new_one(): void
    {
        $id = $this->create($this->manager)->json();
        $this->rpc($this->manager, 'submit_agrodealer_settlement', ['settlement_id' => $id]);

        $this->rpc($this->finance, 'finance_return_settlement', ['settlement_id' => $id, 'reason' => ''])->assertJson(['code' => 'FINANCE_REJECTION_REASON_REQUIRED']);
        $this->rpc($this->finance, 'finance_return_settlement', ['settlement_id' => $id, 'reason' => 'falta factura'])->assertOk();

        $this->assertSame(0, DB::table('settlement_items')->where('settlement_id', $id)->where('active', true)->count());
        $this->create($this->manager)->assertOk();
    }

    public function test_an_officer_of_another_province_does_not_see_the_agrodealer(): void
    {
        $elsewhere = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => Province::factory()->create()->id]);

        $this->create($elsewhere)->assertJson(['code' => 'AGRODEALER_NOT_FOUND']);
        $this->rpc($elsewhere, 'list_settlements')->assertJsonCount(0);
        $this->rpc($this->operator, 'list_settlements')->assertJson(['code' => 'SETTLEMENT_NOT_ALLOWED']);
    }
}
