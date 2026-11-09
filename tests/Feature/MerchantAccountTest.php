<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MoneyScenario;
use Tests\TestCase;

/**
 * O gestor do comerciante é o dono da loja: vê as vendas de toda a loja no
 * ciclo e quanto lhe devem; só anula as vendas que ele próprio fez.
 */
class MerchantAccountTest extends TestCase
{
    use MoneyScenario, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->role(UserRole::AgrodealerManager)->create(['agrodealer_id' => $this->agrodealer->id, 'full_name' => 'Dono da Loja']);
    }

    private function sellAsOperator(): void
    {
        $voucher = $this->householdWithCard()['voucher'];
        $sale = ['voucher_code' => $voucher->voucher_code, 'items' => [['product_id' => $this->product->id, 'quantity' => 2]], 'discount_type' => null, 'discount_value' => 0];
        $preview = $this->actingAs($this->operator)->postJson('/pos/preview', $sale)->assertOk()->json();
        $this->actingAs($this->operator)->postJson('/pos/sales', [
            ...$sale, 'idempotency_key' => 'key-1', 'expected_total' => $preview['total_amount'], 'expected_voucher_amount' => $preview['voucher_amount'],
        ])->assertOk();
    }

    public function test_the_owner_sees_every_sale_of_the_shop_but_voids_only_his_own(): void
    {
        $this->sellAsOperator();

        $this->actingAs($this->owner)->get('/sales')->assertInertia(fn (Assert $page) => $page
            ->where('scope', 'shop')
            ->has('sales', 1)
            ->where('sales.0.operator_name', $this->operator->full_name)
            ->where('sales.0.mine', false)
            ->where('sales.0.can_void', false));

        $this->actingAs($this->operator)->get('/sales')->assertInertia(fn (Assert $page) => $page->where('scope', 'mine')->has('sales', 1));
    }

    public function test_the_owner_sees_what_is_owed_by_stage_and_nobody_else_opens_the_page(): void
    {
        $this->sellAsOperator();

        $this->actingAs($this->owner)->get('/payments')->assertInertia(fn (Assert $page) => $page
            ->component('pos/payments')
            ->where('stages.not_reconciled', 2000)
            ->where('stages.validated', 0)
            ->has('settlements', 0));

        $this->actingAs($this->operator)->get('/payments')->assertForbidden();
        $this->actingAs($this->admin)->get('/payments')->assertForbidden();
    }
}
