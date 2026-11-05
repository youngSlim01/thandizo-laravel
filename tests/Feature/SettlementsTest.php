<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
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
        return $this->actingAs($user)->post('/admin/settlements', ['agrodealer_id' => $this->agrodealer->id, 'period_start' => $from, 'period_end' => $to]);
    }

    private function settlementId(): string
    {
        return DB::table('settlement_batches')->orderByDesc('created_at')->orderByDesc('id')->value('id');
    }

    private function statusOf(string $id): string
    {
        return DB::table('settlement_batches')->where('id', $id)->value('status');
    }

    public function test_a_settlement_takes_the_confirmed_sales_of_the_period_in_mozambique_time(): void
    {
        $this->create($this->officer)->assertSessionHas('success');
        $id = $this->settlementId();
        $this->actingAs($this->officer)->get('/admin/settlements?open='.$id)
            ->assertInertia(fn (Assert $page) => $page->component('admin/settlements')->where('openId', $id)->has('settlements', 1));

        $batch = DB::table('settlement_batches')->where('id', $id)->first();
        $this->assertSame([2, '3000.00', '3000.00', 'RECONCILED'], [$batch->transaction_count, $batch->voucher_amount, $batch->payable_amount, $batch->status]);
        $this->create($this->officer)->assertSessionHasErrors(['rule' => 'Já existe uma liquidação para este agrodealer e período.']);
        $this->create($this->officer, '2026-11-01', '2099-01-01')->assertSessionHasErrors(['rule' => 'Só se liquidam dias fechados: a data final tem de ser anterior a hoje (hora de Moçambique).']);
        $this->create($this->officer, '2026-09-01', '2026-09-30')->assertSessionHasErrors(['rule' => 'Não existem transações confirmadas ainda não liquidadas neste período.']);
    }

    public function test_officer_then_manager_then_finance(): void
    {
        $this->create($this->officer);
        $id = $this->settlementId();

        $this->actingAs($this->officer)->post("/admin/settlements/{$id}/submission")->assertSessionHas('success', 'Enviada ao gestor MEAL para aprovação.');
        $this->assertSame('PENDING_MANAGER', $this->statusOf($id));
        $this->actingAs($this->officer)->post("/admin/settlements/{$id}/approval")
            ->assertSessionHasErrors(['rule' => 'Só o gestor MEAL ou um administrador pode aprovar ou devolver reconciliações.']);
        $this->actingAs($this->finance)->post("/finance/settlements/{$id}/validation")
            ->assertSessionHasErrors(['rule' => 'Esta reconciliação ainda não foi submetida à Finance ou já foi processada.']);
        $this->actingAs($this->manager)->post("/admin/settlements/{$id}/approval")->assertSessionHas('success');
        $this->actingAs($this->finance)->post("/finance/settlements/{$id}/validation")->assertSessionHas('success');

        $this->assertSame('VALIDATED', $this->statusOf($id));
        $this->actingAs($this->finance)->getJson("/settlements/{$id}/transactions")->assertJsonCount(2);
        $this->actingAs($this->finance)->get('/finance/settlements')
            ->assertInertia(fn (Assert $page) => $page->component('finance/settlements')->where('settlements.0.agrodealer', $this->agrodealer->name));
    }

    public function test_a_manager_sends_straight_to_finance(): void
    {
        $this->create($this->manager);
        $id = $this->settlementId();

        $this->actingAs($this->manager)->post("/admin/settlements/{$id}/submission")->assertSessionHas('success', 'Enviada para Finance.');
        $this->assertSame('SUBMITTED_TO_FINANCE', $this->statusOf($id));
    }

    public function test_a_returned_settlement_frees_its_sales_for_a_new_one(): void
    {
        $this->create($this->manager);
        $id = $this->settlementId();
        $this->actingAs($this->manager)->post("/admin/settlements/{$id}/submission");

        $this->actingAs($this->finance)->post("/finance/settlements/{$id}/return", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->finance)->post("/finance/settlements/{$id}/return", ['reason' => 'falta factura'])->assertSessionHas('success');

        $this->assertSame(0, DB::table('settlement_items')->where('settlement_id', $id)->where('active', true)->count());
        $this->create($this->manager)->assertSessionHas('success');
    }

    public function test_an_officer_of_another_province_does_not_see_the_agrodealer(): void
    {
        $elsewhere = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => Province::factory()->create()->id]);
        $this->create($this->manager);

        $this->create($elsewhere)->assertSessionHasErrors(['rule' => 'Agrodealer não encontrado.']);
        $this->actingAs($elsewhere)->get('/admin/settlements')->assertInertia(fn (Assert $page) => $page->has('settlements', 0)->has('agrodealers', 0));
        $this->actingAs($this->operator)->get('/admin/settlements')->assertForbidden();
        $this->actingAs($this->finance)->post('/admin/settlements', [])->assertForbidden();
    }
}
