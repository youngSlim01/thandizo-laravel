<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\MoneyScenario;
use Tests\TestCase;

/**
 * Equivalente PHP de supabase/tests/0025_programme_cycles_tests.sql.
 */
class CyclesTest extends TestCase
{
    use MoneyScenario, RefreshDatabase;

    private function openCycleId(): string
    {
        return DB::table('programme_cycles')->where('status', 'OPEN')->value('id');
    }

    public function test_only_one_cycle_is_open_at_a_time(): void
    {
        $this->assertSame('CIC-01', DB::table('programme_cycles')->value('code'));
        $this->rpc($this->admin, 'open_programme_cycle', ['name' => 'Outro'])->assertJson(['code' => 'CYCLE_ALREADY_OPEN']);
    }

    public function test_closing_expires_every_balance_and_records_it_per_household(): void
    {
        ['household' => $household, 'voucher' => $voucher] = $this->householdWithCard();
        $cycle = $this->openCycleId();

        $this->rpc($this->admin, 'close_programme_cycle', ['cycle_id' => $cycle, 'commit' => false])
            ->assertJson(['committed' => false, 'households_with_balance' => 1, 'unused_amount' => 6000]);
        $this->rpc($this->admin, 'close_programme_cycle', ['cycle_id' => $cycle, 'commit' => true])
            ->assertJson(['committed' => true, 'households_with_balance' => 1]);

        $this->assertEquals(0, DB::table('vouchers')->where('id', $voucher->id)->value('current_balance'));
        $expiry = DB::table('voucher_ledger')->where('entry_type', 'CYCLE_EXPIRY')->sole();
        $this->assertSame(['-6000.00', '0.00'], [$expiry->amount, $expiry->balance_after]);
        $this->assertSame('6000.00', DB::table('cycle_households')->where('household_id', $household->id)->value('unused_amount'));
        $this->assertSame('CLOSED', DB::table('programme_cycles')->where('id', $cycle)->value('status'));
    }

    public function test_the_next_cycle_recharges_the_same_card_and_explains_who_is_left_out(): void
    {
        ['household' => $withCard, 'voucher' => $voucher] = $this->householdWithCard();
        $noCard = $this->household();
        $this->rpc($this->admin, 'close_programme_cycle', ['cycle_id' => $this->openCycleId(), 'commit' => true]);
        $cycle = $this->rpc($this->admin, 'open_programme_cycle', ['name' => 'Ciclo 2'])->assertJson(['code' => 'CIC-02'])->json('cycle_id');

        $preview = $this->rpc($this->admin, 'allocate_cycle', [
            'cycle_id' => $cycle, 'household_codes' => [$withCard->household_code, $noCard->household_code, '0000AF000000'],
        ])->assertJson(['committed' => false, 'to_allocate' => 1, 'total_amount' => 6000])->json();
        $this->assertEquals(['NO_DELIVERED_CARD' => 1, 'UNKNOWN_HOUSEHOLD' => 1], $preview['left_out']);

        $this->rpc($this->admin, 'allocate_cycle', ['cycle_id' => $cycle, 'household_ids' => [$withCard->id], 'commit' => true])
            ->assertJson(['committed' => true, 'allocated' => 1]);
        $this->assertSame('6000.00', DB::table('vouchers')->where('id', $voucher->id)->value('current_balance'));
        $this->assertSame('CYCLE_ALLOCATION', DB::table('voucher_ledger')->orderByDesc('created_at')->orderByDesc('id')->value('entry_type'));

        $this->rpc($this->admin, 'allocate_cycle', ['cycle_id' => $cycle, 'household_ids' => [$withCard->id]])
            ->assertJson(['to_allocate' => 0, 'left_out' => ['ALREADY_IN_CYCLE' => 1]]);
    }

    public function test_a_cycle_does_not_close_within_15_minutes_of_a_sale(): void
    {
        $this->householdWithCard();
        DB::table('transactions')->insert([
            'id' => (string) Str::uuid(), 'transaction_code' => 'TX-1', 'voucher_id' => DB::table('vouchers')->value('id'),
            'household_id' => DB::table('households')->value('id'), 'agrodealer_id' => $this->agrodealer->id, 'subtotal' => 100,
            'total_amount' => 100, 'voucher_amount' => 100, 'status' => 'CONFIRMED', 'idempotency_key' => 'k1', 'confirmed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->rpc($this->admin, 'close_programme_cycle', ['cycle_id' => $this->openCycleId(), 'commit' => true])->assertJson(['code' => 'CYCLE_RECENT_SALES']);
    }
}
