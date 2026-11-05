<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Province;
use App\Models\User;
use App\Services\CardService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\MoneyScenario;
use Tests\TestCase;

/**
 * Equivalente PHP dos testes SQL 0004 (ledger protegido), 0011 (lotes de
 * cartões), 0018 (distribuição cartão a cartão) e 0019 (substituto).
 */
class CardsTest extends TestCase
{
    use MoneyScenario, RefreshDatabase;

    public function test_a_batch_creates_a_pending_card_without_money_for_each_household_without_card(): void
    {
        $this->household();
        $this->household();
        $this->household(['status' => 'INACTIVE']);

        $batch = $this->rpc($this->admin, 'generate_card_batch', ['community_id' => $this->community->id])
            ->assertOk()->assertJson(['card_count' => 2])->json();

        $this->assertMatchesRegularExpression('/^LOTE-MUT-\d{4}$/', $batch['batch_code']);
        $cards = DB::table('vouchers')->where('card_batch_id', $batch['batch_id'])->get();
        $this->assertCount(2, $cards);
        foreach ($cards as $card) {
            $this->assertSame('PENDING', $card->status);
            $this->assertEquals(0, $card->current_balance);
            $this->assertMatchesRegularExpression('/^VCH-MUT-(\d{6})-(\d)$/', $card->voucher_code);
            [$serial, $check] = explode('-', substr($card->voucher_code, 8));
            $this->assertSame((int) $check, CardService::luhnCheckDigit($serial));
        }
        $this->assertSame(0, DB::table('voucher_ledger')->count());

        $this->rpc($this->admin, 'generate_card_batch', ['community_id' => $this->community->id])
            ->assertUnprocessable()->assertJson(['code' => 'NO_HOUSEHOLDS_WITHOUT_CARD']);
    }

    public function test_distribution_activates_only_the_delivered_cards_with_6000_and_puts_them_in_the_cycle(): void
    {
        $this->household();
        $this->household();
        $batch = $this->rpc($this->admin, 'generate_card_batch', ['community_id' => $this->community->id])->json();
        [$first, $second] = DB::table('vouchers')->where('card_batch_id', $batch['batch_id'])->orderBy('voucher_code')->pluck('id')->all();

        $this->rpc($this->admin, 'distribute_cards', ['batch_id' => $batch['batch_id'], 'voucher_ids' => [$first]])
            ->assertJson(['code' => 'CARD_BATCH_NOT_PRINTED']);
        $this->rpc($this->admin, 'mark_card_batch_printed', ['batch_id' => $batch['batch_id']])->assertJson(['status' => 'PRINTED']);

        $this->rpc($this->admin, 'distribute_cards', [
            'batch_id' => $batch['batch_id'], 'voucher_ids' => [$first],
            'substitutes' => [['voucher_id' => $first, 'name' => 'Rosa', 'relationship' => 'SPOUSE']],
        ])->assertOk()->assertJson(['activated' => 1, 'remaining' => 1, 'status' => 'IN_DISTRIBUTION']);

        $card = DB::table('vouchers')->where('id', $first)->first();
        $this->assertSame(['ACTIVE', '6000.00', 'Rosa'], [$card->status, $card->current_balance, $card->received_by_name]);
        $entry = DB::table('voucher_ledger')->where('voucher_id', $first)->sole();
        $this->assertSame(['INITIAL_ALLOCATION', '6000.00', '6000.00'], [$entry->entry_type, $entry->amount, $entry->balance_after]);
        $this->assertSame('DELIVERY', DB::table('cycle_households')->where('voucher_id', $first)->value('source'));
        $this->assertSame('PENDING', DB::table('vouchers')->where('id', $second)->value('status'));

        $this->rpc($this->admin, 'distribute_cards', ['batch_id' => $batch['batch_id'], 'voucher_ids' => [$second]])
            ->assertJson(['status' => 'DISTRIBUTED', 'remaining' => 0]);
    }

    public function test_distribution_rules(): void
    {
        $household = $this->household();
        $batch = $this->rpc($this->admin, 'generate_card_batch', ['community_id' => $this->community->id])->json();
        $this->rpc($this->admin, 'mark_card_batch_printed', ['batch_id' => $batch['batch_id']]);
        $card = DB::table('vouchers')->where('household_id', $household->id)->value('id');

        $this->rpc($this->admin, 'distribute_cards', ['batch_id' => $batch['batch_id'], 'voucher_ids' => []])->assertJson(['code' => 'CARDS_REQUIRED']);
        $this->rpc($this->admin, 'distribute_cards', [
            'batch_id' => $batch['batch_id'], 'voucher_ids' => [$card], 'substitutes' => [['voucher_id' => $card, 'name' => 'Rosa']],
        ])->assertJson(['code' => 'CARD_SUBSTITUTE_INCOMPLETE']);

        DB::table('households')->where('id', $household->id)->update(['status' => 'INACTIVE']);
        $this->rpc($this->admin, 'distribute_cards', ['batch_id' => $batch['batch_id'], 'voucher_ids' => [$card]])->assertJson(['code' => 'CARD_HOUSEHOLD_INACTIVE']);

        DB::table('households')->where('id', $household->id)->update(['status' => 'ACTIVE']);
        DB::table('programme_cycles')->update(['status' => 'CLOSED', 'closed_at' => now()]);
        $this->rpc($this->admin, 'distribute_cards', ['batch_id' => $batch['batch_id'], 'voucher_ids' => [$card]])->assertJson(['code' => 'NO_OPEN_CYCLE']);
        $this->assertSame('PENDING', DB::table('vouchers')->where('id', $card)->value('status'));
    }

    public function test_cancelling_before_delivery_frees_the_households_and_only_managers_cancel(): void
    {
        $this->household();
        $batch = $this->rpc($this->admin, 'generate_card_batch', ['community_id' => $this->community->id])->json();
        $officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]);

        $this->rpc($officer, 'cancel_card_batch', ['batch_id' => $batch['batch_id'], 'reason' => 'erro'])->assertJson(['code' => 'CARD_BATCH_CANCEL_NOT_ALLOWED']);
        $this->rpc($this->admin, 'cancel_card_batch', ['batch_id' => $batch['batch_id'], 'reason' => ' '])->assertJson(['code' => 'CANCEL_REASON_REQUIRED']);
        $this->rpc($this->admin, 'cancel_card_batch', ['batch_id' => $batch['batch_id'], 'reason' => 'nomes errados'])
            ->assertJson(['status' => 'CANCELLED', 'cancelled_cards' => 1]);

        $this->rpc($this->admin, 'generate_card_batch', ['community_id' => $this->community->id])->assertJson(['card_count' => 1]);
    }

    public function test_a_lost_card_is_replaced_and_its_balance_moves_to_the_new_card(): void
    {
        ['household' => $household, 'voucher' => $old] = $this->householdWithCard();

        $result = $this->rpc($this->admin, 'replace_voucher', ['voucher_code' => strtolower($old->voucher_code), 'reason' => 'perdido'])
            ->assertOk()->assertJson(['old_voucher_code' => $old->voucher_code, 'transferred' => '6000.00'])->json();

        $this->assertSame(['REPLACED', '0.00'], [DB::table('vouchers')->where('id', $old->id)->value('status'), DB::table('vouchers')->where('id', $old->id)->value('current_balance')]);
        $new = DB::table('vouchers')->where('voucher_code', $result['new_voucher_code'])->first();
        $this->assertSame(['ACTIVE', '6000.00', $household->id], [$new->status, $new->current_balance, $new->household_id]);
        $this->assertSame(2, DB::table('voucher_ledger')->where('entry_type', 'REPLACEMENT_TRANSFER')->count());
        $this->rpc($this->admin, 'replace_voucher', ['voucher_code' => $old->voucher_code, 'reason' => 'outra'])->assertJson(['code' => 'VOUCHER_NOT_ACTIVE']);
    }

    public function test_the_ledger_can_never_be_changed_or_deleted(): void
    {
        $this->householdWithCard();
        $entry = DB::table('voucher_ledger')->first();

        foreach ([
            fn () => DB::table('voucher_ledger')->where('id', $entry->id)->update(['amount' => 1]),
            fn () => DB::table('voucher_ledger')->where('id', $entry->id)->delete(),
        ] as $change) {
            try {
                $change();
                $this->fail('O ledger devia recusar a alteração.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('LEDGER_IMMUTABLE', $exception->getMessage());
            }
        }
    }

    public function test_a_meal_officer_only_sees_batches_of_the_province(): void
    {
        $this->household();
        $batch = $this->rpc($this->admin, 'generate_card_batch', ['community_id' => $this->community->id])->json();
        $elsewhere = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => Province::factory()->create()->id]);

        $this->rpc($elsewhere, 'list_card_batches')->assertJsonCount(0);
        $this->rpc($elsewhere, 'get_card_batch_cards', ['batch_id' => $batch['batch_id']])->assertJson(['code' => 'CARD_BATCH_NOT_FOUND']);
        $this->rpc(User::factory()->role(UserRole::AgrodealerOperator)->create(), 'list_card_batches')->assertJson(['code' => 'CARD_BATCH_NOT_ALLOWED']);
    }
}
