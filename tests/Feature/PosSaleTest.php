<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\User;
use App\Services\PosService;
use App\Services\PriceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MoneyScenario;
use Tests\TestCase;

/**
 * Equivalente PHP dos testes SQL 0003 (regras de venda), 0005 (anulação),
 * 0006 (confirmação no POS) e 0010 (sem compra sem saldo), pelas rotas do
 * POS. O operador do cenário tem um só POS, que fica escolhido sozinho.
 */
class PosSaleTest extends TestCase
{
    use MoneyScenario, RefreshDatabase;

    private object $voucher;

    private function card(): object
    {
        return $this->voucher ??= $this->householdWithCard()['voucher'];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sale(float $quantity, array $overrides = []): array
    {
        return [
            'voucher_code' => $this->card()->voucher_code, 'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
            'discount_type' => null, 'discount_value' => 0, ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function preview(float $quantity, array $overrides = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->operator)->postJson('/pos/preview', $this->sale($quantity, $overrides));
    }

    /**
     * Pré-visualiza e confirma, como o ecrã do POS.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sell(float $quantity, array $overrides = [], string $key = 'key-1'): TestResponse
    {
        $preview = $this->preview($quantity, $overrides)->assertOk()->json();

        return $this->actingAs($this->operator)->postJson('/pos/sales', [
            ...$this->sale($quantity, $overrides), 'idempotency_key' => $key,
            'expected_total' => $preview['total_amount'], 'expected_voucher_amount' => $preview['voucher_amount'],
        ]);
    }

    private function balance(): string
    {
        return DB::table('vouchers')->where('id', $this->card()->id)->value('current_balance');
    }

    public function test_the_voucher_lookup_shows_the_household_and_the_balance(): void
    {
        $this->actingAs($this->operator)->postJson('/pos/lookup', ['voucher_code' => strtolower($this->card()->voucher_code)])
            ->assertOk()->assertJson(['voucher_status' => 'ACTIVE', 'current_balance' => '6000.00']);
        $this->actingAs($this->operator)->postJson('/pos/lookup', ['voucher_code' => 'VCH-XXX-000000-0'])
            ->assertUnprocessable()->assertJson(['message' => 'Voucher não encontrado. Verifique o código no cartão.']);
    }

    public function test_a_sale_with_discount_charges_the_voucher_writes_the_ledger_and_returns_the_receipt(): void
    {
        $this->preview(3, ['discount_type' => 'PERCENTAGE', 'discount_value' => 10])
            ->assertJson(['subtotal' => 3000, 'discount_amount' => 300, 'total_amount' => 2700, 'voucher_amount' => 2700, 'copayment_amount' => 0, 'balance_after' => 3300]);

        $result = $this->sell(3, ['discount_type' => 'PERCENTAGE', 'discount_value' => 10])->assertOk()
            ->assertJson(['status' => 'CONFIRMED', 'total_amount' => 2700, 'receipt' => ['voucher_balance_after' => '3300.00', 'items' => [['product_name' => 'Semente', 'quantity' => '3.000', 'unit_price' => '1000.00']]]])
            ->json();

        $this->assertSame('3300.00', $this->balance());
        $entry = DB::table('voucher_ledger')->where('transaction_id', $result['transaction_id'])->sole();
        $this->assertSame(['REDEMPTION', '-2700.00', '3300.00'], [$entry->entry_type, $entry->amount, $entry->balance_after]);
        $this->assertSame($this->pos->id, DB::table('transactions')->value('pos_id'));
    }

    public function test_the_household_pays_the_rest_up_to_20_percent_of_the_total(): void
    {
        // 7 000 MT com 6 000 de saldo: 1 000 de copagamento (14 %).
        $this->sell(7)->assertJson(['voucher_amount' => 6000, 'copayment_amount' => 1000]);
        $this->assertSame('0.00', $this->balance());
        $this->preview(1)->assertJson(['code' => 'VOUCHER_BALANCE_EMPTY']);
    }

    public function test_a_copayment_above_20_percent_is_refused(): void
    {
        // 8 000 MT: 2 000 de copagamento (25 %).
        $this->preview(8)->assertUnprocessable()->assertJson(['code' => 'COPAYMENT_LIMIT_EXCEEDED']);
        $this->assertSame(0, DB::table('transactions')->count());
    }

    public function test_a_price_change_after_the_preview_is_caught_and_nothing_is_saved(): void
    {
        $preview = $this->preview(2)->json();
        $this->travel(1)->minutes();
        app(PriceService::class)->setPrice($this->admin, $this->product, $this->district, 1100, 'Erro de digitação');

        $this->actingAs($this->operator)->postJson('/pos/sales', [
            ...$this->sale(2), 'idempotency_key' => 'key-1', 'expected_total' => $preview['total_amount'], 'expected_voucher_amount' => $preview['voucher_amount'],
        ])->assertUnprocessable()->assertJson(['code' => 'PREVIEW_OUTDATED']);

        $this->assertSame(0, DB::table('transactions')->count());
        $this->assertSame('6000.00', $this->balance());
    }

    public function test_the_same_idempotency_key_never_makes_two_sales(): void
    {
        $first = $this->sell(1)->json('transaction_id');
        $this->sell(1)->assertJson(['duplicate' => true, 'transaction_id' => $first]);
        $this->sell(2)->assertJson(['code' => 'IDEMPOTENCY_KEY_MISMATCH']);

        $this->assertSame(1, DB::table('transactions')->count());
        $this->assertSame('5000.00', $this->balance());
    }

    public function test_catalogue_price_and_household_rules(): void
    {
        $this->card();
        $this->agrodealer->catalogue()->detach($this->product->id);
        $this->preview(1)->assertJson(['code' => 'PRODUCT_NOT_IN_CATALOGUE']);

        $this->agrodealer->catalogue()->attach($this->product->id);
        $this->travel(1)->minutes();
        app(PriceService::class)->setPrice($this->admin, $this->product, $this->district, null, 'Produto esgotado');
        $this->preview(1)->assertJson(['code' => 'PRODUCT_NO_DISTRICT_PRICE']);

        DB::table('households')->update(['status' => 'INACTIVE']);
        $this->preview(1)->assertJson(['code' => 'HOUSEHOLD_INACTIVE']);
    }

    public function test_only_sellers_with_a_pos_of_their_agrodealer_sell(): void
    {
        $this->card();
        $this->preview(1, [], $this->admin)->assertForbidden();
        $other = User::factory()->role(UserRole::AgrodealerOperator)->create(['agrodealer_id' => Agrodealer::factory()->create()->id]);
        $this->preview(1, [], $other)->assertJson(['code' => 'POS_NOT_ALLOWED']);
    }

    public function test_an_operator_with_several_pos_chooses_one_of_the_agrodealer(): void
    {
        $second = $this->agrodealer->pos()->create(['pos_code' => 'POS-MUT-001-02', 'status' => 'ACTIVE']);
        $elsewhere = Agrodealer::factory()->create()->pos()->create(['pos_code' => 'POS-X-01', 'status' => 'ACTIVE']);
        $page = fn () => $this->actingAs($this->operator)->get('/sales');

        $page()->assertInertia(fn (Assert $p) => $p->where('auth.user.pos', null)->has('auth.user.pos_list', 2));
        $this->actingAs($this->operator)->post('/pos/terminal', ['pos_id' => $elsewhere->id])->assertSessionHasErrors('pos_id');
        $this->actingAs($this->operator)->post('/pos/terminal', ['pos_id' => $second->id])->assertRedirect();
        $page()->assertInertia(fn (Assert $p) => $p->where('auth.user.pos.code', 'POS-MUT-001-02'));
    }

    public function test_a_sale_is_voided_within_15_minutes_by_its_operator_and_the_balance_comes_back(): void
    {
        $sale = $this->sell(2)->json();
        $this->actingAs($this->operator)->get('/sales')->assertInertia(fn (Assert $page) => $page
            ->component('pos/sales')->where('sales.0.transaction_id', $sale['transaction_id'])->where('sales.0.can_void', true));
        $url = "/sales/{$sale['transaction_id']}/void";

        $this->actingAs($this->operator)->post($url, ['reason' => ''])->assertSessionHasErrors(['reason' => 'Indique o motivo da anulação.']);
        $this->actingAs($this->operator)->post($url, ['reason' => 'produto errado'])
            ->assertSessionHas('success', "Venda {$sale['transaction_code']} anulada. O saldo do voucher foi reposto.");

        $this->assertSame('6000.00', $this->balance());
        $this->assertSame('REVERSED', DB::table('transactions')->value('status'));
        $this->assertSame('REVERSAL', DB::table('voucher_ledger')->orderByDesc('created_at')->orderByDesc('id')->value('entry_type'));
        $this->actingAs($this->operator)->get("/receipt/{$sale['transaction_id']}")
            ->assertInertia(fn (Assert $page) => $page->component('pos/receipt')->where('receipt.voided', true)->where('receipt.void_reason', 'produto errado'));
    }

    public function test_after_15_minutes_a_sale_can_no_longer_be_voided(): void
    {
        $sale = $this->sell(1)->json();
        $this->travel(16)->minutes();

        $this->actingAs($this->operator)->post("/sales/{$sale['transaction_id']}/void", ['reason' => 'tarde'])
            ->assertSessionHasErrors(['rule' => 'Já passaram mais de 15 minutos. Esta venda não pode ser anulada no POS.']);
    }

    public function test_sales_and_their_items_are_never_deleted(): void
    {
        $sale = $this->sell(1)->json();

        foreach ([
            fn () => DB::table('transactions')->where('id', $sale['transaction_id'])->delete(),
            fn () => DB::table('transaction_items')->where('transaction_id', $sale['transaction_id'])->update(['quantity' => 5]),
        ] as $change) {
            try {
                $change();
                $this->fail('O histórico de vendas devia recusar a alteração.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('TRANSACTION_HISTORY_IMMUTABLE', $exception->getMessage());
            }
        }
    }

    public function test_the_receipt_is_only_for_the_agrodealer_and_the_central_roles(): void
    {
        $sale = $this->sell(1)->json();
        $other = User::factory()->role(UserRole::AgrodealerOperator)->create(['agrodealer_id' => Agrodealer::factory()->create()->id]);
        $other->agrodealer->pos()->create(['pos_code' => 'POS-OUT-01', 'status' => 'ACTIVE']);

        $this->actingAs($other)->get("/receipt/{$sale['transaction_id']}")->assertForbidden();
        $this->assertSame($sale['transaction_code'], app(PosService::class)->receipt($this->admin, $sale['transaction_id'])['transaction_code']);
    }

    public function test_the_products_step_lists_only_the_catalogue_with_a_district_price(): void
    {
        $this->actingAs($this->operator)->get('/pos/products')->assertInertia(fn (Assert $page) => $page
            ->component('pos/products')->has('products', 1)->where('products.0.name', 'Semente')->where('products.0.listPrice', 1000));
    }
}
