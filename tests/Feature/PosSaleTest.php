<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\User;
use App\Services\PriceService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\MoneyScenario;
use Tests\TestCase;

/**
 * Equivalente PHP dos testes SQL 0003 (regras de venda), 0005 (anulação),
 * 0006 (confirmação no POS) e 0010 (sem compra sem saldo).
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
            'voucher_code' => $this->card()->voucher_code, 'agrodealer_id' => $this->agrodealer->id, 'pos_id' => $this->pos->id,
            'operator_id' => $this->operator->id, 'items' => [['product_id' => $this->product->id, 'quantity' => $quantity]],
            'discount_type' => null, 'discount_value' => 0, ...$overrides,
        ];
    }

    /**
     * Pré-visualiza e confirma, como o ecrã do POS.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function sell(float $quantity, array $overrides = [], string $key = 'key-1'): TestResponse
    {
        $preview = $this->rpc($this->operator, 'preview_voucher_redemption', $this->sale($quantity, $overrides))->assertOk()->json();

        return $this->rpc($this->operator, 'confirm_pos_redemption', [
            ...$this->sale($quantity, $overrides), 'idempotency_key' => $key,
            'expected_total' => $preview['total_amount'], 'expected_voucher_amount' => $preview['voucher_amount'],
        ]);
    }

    private function balance(): string
    {
        return DB::table('vouchers')->where('id', $this->card()->id)->value('current_balance');
    }

    public function test_a_sale_with_discount_charges_the_voucher_writes_the_ledger_and_returns_the_receipt(): void
    {
        $this->rpc($this->operator, 'preview_voucher_redemption', $this->sale(3, ['discount_type' => 'PERCENTAGE', 'discount_value' => 10]))
            ->assertJson(['subtotal' => 3000, 'discount_amount' => 300, 'total_amount' => 2700, 'voucher_amount' => 2700, 'copayment_amount' => 0, 'balance_after' => 3300]);

        $result = $this->sell(3, ['discount_type' => 'PERCENTAGE', 'discount_value' => 10])->assertOk()
            ->assertJson(['status' => 'CONFIRMED', 'total_amount' => 2700, 'receipt' => ['voucher_balance_after' => '3300.00', 'items' => [['product_name' => 'Semente', 'quantity' => '3.000', 'unit_price' => '1000.00']]]])
            ->json();

        $this->assertSame('3300.00', $this->balance());
        $entry = DB::table('voucher_ledger')->where('transaction_id', $result['transaction_id'])->sole();
        $this->assertSame(['REDEMPTION', '-2700.00', '3300.00'], [$entry->entry_type, $entry->amount, $entry->balance_after]);
    }

    public function test_the_household_pays_the_rest_up_to_20_percent_of_the_total(): void
    {
        // 7 000 MT com 6 000 de saldo: 1 000 de copagamento (14 %).
        $this->sell(7)->assertJson(['voucher_amount' => 6000, 'copayment_amount' => 1000]);
        $this->assertSame('0.00', $this->balance());
        $this->rpc($this->operator, 'preview_voucher_redemption', $this->sale(1))->assertJson(['code' => 'VOUCHER_BALANCE_EMPTY']);
    }

    public function test_a_copayment_above_20_percent_is_refused(): void
    {
        // 8 000 MT: 2 000 de copagamento (25 %).
        $this->rpc($this->operator, 'preview_voucher_redemption', $this->sale(8))
            ->assertUnprocessable()->assertJson(['code' => 'COPAYMENT_LIMIT_EXCEEDED']);
        $this->assertSame(0, DB::table('transactions')->count());
    }

    public function test_a_price_change_after_the_preview_is_caught_and_nothing_is_saved(): void
    {
        $preview = $this->rpc($this->operator, 'preview_voucher_redemption', $this->sale(2))->json();
        $this->travel(1)->minutes();
        app(PriceService::class)->setPrice($this->admin, $this->product, $this->district, 1100);

        $this->rpc($this->operator, 'confirm_pos_redemption', [
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
        $this->rpc($this->operator, 'preview_voucher_redemption', $this->sale(1))->assertJson(['code' => 'PRODUCT_NOT_IN_CATALOGUE']);

        $this->agrodealer->catalogue()->attach($this->product->id);
        $this->travel(1)->minutes();
        app(PriceService::class)->setPrice($this->admin, $this->product, $this->district, null);
        $this->rpc($this->operator, 'preview_voucher_redemption', $this->sale(1))->assertJson(['code' => 'PRODUCT_NO_DISTRICT_PRICE']);

        DB::table('households')->update(['status' => 'INACTIVE']);
        $this->rpc($this->operator, 'preview_voucher_redemption', $this->sale(1))->assertJson(['code' => 'HOUSEHOLD_INACTIVE']);
    }

    public function test_only_sellers_of_the_agrodealer_confirm(): void
    {
        $this->card();
        $this->rpc($this->admin, 'confirm_pos_redemption', [...$this->sale(1), 'idempotency_key' => 'k', 'expected_total' => 1000, 'expected_voucher_amount' => 1000])
            ->assertJson(['code' => 'ROLE_NOT_ALLOWED']);
        $other = User::factory()->role(UserRole::AgrodealerOperator)->create(['agrodealer_id' => Agrodealer::factory()->create()->id]);
        $this->rpc($other, 'confirm_pos_redemption', [...$this->sale(1), 'operator_id' => $other->id, 'idempotency_key' => 'k', 'expected_total' => 1000, 'expected_voucher_amount' => 1000])
            ->assertJson(['code' => 'POS_NOT_ALLOWED']);
    }

    public function test_a_sale_is_voided_within_15_minutes_by_its_operator_and_the_balance_comes_back(): void
    {
        $sale = $this->sell(2)->json();
        $this->rpc($this->operator, 'list_my_recent_transactions')->assertJson([['transaction_id' => $sale['transaction_id'], 'can_void' => true]]);

        $this->rpc($this->operator, 'void_voucher_transaction', ['transaction_id' => $sale['transaction_id'], 'operator_id' => $this->operator->id, 'pos_id' => $this->pos->id, 'reason' => ''])
            ->assertJson(['code' => 'VOID_REASON_REQUIRED']);
        $this->rpc($this->operator, 'void_voucher_transaction', ['transaction_id' => $sale['transaction_id'], 'operator_id' => $this->operator->id, 'pos_id' => $this->pos->id, 'reason' => 'produto errado'])
            ->assertOk()->assertJson(['transaction_status' => 'REVERSED', 'balance_after' => 6000]);

        $this->assertSame('6000.00', $this->balance());
        $this->assertSame('REVERSAL', DB::table('voucher_ledger')->orderByDesc('created_at')->orderByDesc('id')->value('entry_type'));
        $this->rpc($this->operator, 'get_transaction_receipt', ['transaction_id' => $sale['transaction_id']])->assertJson(['voided' => true, 'void_reason' => 'produto errado']);
    }

    public function test_after_15_minutes_a_sale_can_no_longer_be_voided(): void
    {
        $sale = $this->sell(1)->json();
        $this->travel(16)->minutes();

        $this->rpc($this->operator, 'void_voucher_transaction', ['transaction_id' => $sale['transaction_id'], 'operator_id' => $this->operator->id, 'pos_id' => $this->pos->id, 'reason' => 'tarde'])
            ->assertJson(['code' => 'VOID_WINDOW_EXPIRED']);
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

        $this->rpc($other, 'get_transaction_receipt', ['transaction_id' => $sale['transaction_id']])->assertJson(['code' => 'RECEIPT_NOT_ALLOWED']);
        $this->rpc($this->admin, 'get_transaction_receipt', ['transaction_id' => $sale['transaction_id']])->assertOk();
    }
}
