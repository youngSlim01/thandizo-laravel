<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Province;
use App\Models\User;
use App\Services\CardService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MoneyScenario;
use Tests\TestCase;

/**
 * Equivalente PHP dos testes SQL 0004 (ledger protegido), 0011 (lotes de
 * cartões), 0018 (distribuição cartão a cartão) e 0019 (substituto), mais o
 * acesso à página.
 */
class CardsTest extends TestCase
{
    use MoneyScenario, RefreshDatabase;

    private function cards(): CardService
    {
        return app(CardService::class);
    }

    /**
     * @return array{batch_id: string, batch_code: string, card_count: int}
     */
    private function generate(): array
    {
        return $this->cards()->generateBatch($this->admin, $this->community->id);
    }

    public function test_a_batch_creates_a_pending_card_without_money_for_each_household_without_card(): void
    {
        $this->household();
        $this->household();
        $this->household(['status' => 'INACTIVE']);

        $this->actingAs($this->admin)->post('/admin/cards/batches', ['community_id' => $this->community->id])
            ->assertRedirect()->assertSessionHas('success');
        $batch = DB::table('card_batches')->sole();

        $this->assertMatchesRegularExpression('/^LOTE-MUT-\d{4}$/', $batch->batch_code);
        $cards = DB::table('vouchers')->where('card_batch_id', $batch->id)->get();
        $this->assertCount(2, $cards);
        foreach ($cards as $card) {
            $this->assertSame('PENDING', $card->status);
            $this->assertEquals(0, $card->current_balance);
            $this->assertMatchesRegularExpression('/^VCH-MUT-(\d{6})-(\d)$/', $card->voucher_code);
            [$serial, $check] = explode('-', substr($card->voucher_code, 8));
            $this->assertSame((int) $check, CardService::luhnCheckDigit($serial));
        }
        $this->assertSame(0, DB::table('voucher_ledger')->count());

        $this->actingAs($this->admin)->post('/admin/cards/batches', ['community_id' => $this->community->id])
            ->assertSessionHasErrors(['rule' => 'Todos os agregados deste bairro já têm cartão.']);
    }

    public function test_distribution_activates_only_the_delivered_cards_with_6000_and_puts_them_in_the_cycle(): void
    {
        $this->household();
        $this->household();
        $batch = $this->generate();
        [$first, $second] = DB::table('vouchers')->where('card_batch_id', $batch['batch_id'])->orderBy('voucher_code')->pluck('id')->all();

        $this->assertRuleFails('CARD_BATCH_NOT_PRINTED', fn () => $this->cards()->distribute($this->admin, $batch['batch_id'], [$first], []));
        $this->actingAs($this->admin)->put("/admin/cards/batches/{$batch['batch_id']}/printed")->assertSessionHas('success');
        $this->assertSame('PRINTED', DB::table('card_batches')->value('status'));

        $this->actingAs($this->admin)->post("/admin/cards/batches/{$batch['batch_id']}/distribution", [
            'voucher_ids' => [$first],
            'substitutes' => [['voucher_id' => $first, 'name' => 'Rosa', 'relationship' => 'CONJUGE']],
        ])->assertSessionHas('success', '1 cartão ativado com 6 000 MT. Faltam entregar 1.');
        $this->assertSame('IN_DISTRIBUTION', DB::table('card_batches')->value('status'));

        $card = DB::table('vouchers')->where('id', $first)->first();
        $this->assertSame(['ACTIVE', '6000.00', 'Rosa'], [$card->status, $card->current_balance, $card->received_by_name]);
        $entry = DB::table('voucher_ledger')->where('voucher_id', $first)->sole();
        $this->assertSame(['INITIAL_ALLOCATION', '6000.00', '6000.00'], [$entry->entry_type, $entry->amount, $entry->balance_after]);
        $this->assertSame('DELIVERY', DB::table('cycle_households')->where('voucher_id', $first)->value('source'));
        $this->assertSame('PENDING', DB::table('vouchers')->where('id', $second)->value('status'));

        $result = $this->cards()->distribute($this->admin, $batch['batch_id'], [$second], []);
        $this->assertSame(['DISTRIBUTED', 0], [$result['status'], $result['remaining']]);
    }

    public function test_distribution_rules(): void
    {
        $household = $this->household();
        $batch = $this->generate();
        $this->cards()->markPrinted($this->admin, $batch['batch_id']);
        $card = DB::table('vouchers')->where('household_id', $household->id)->value('id');
        $distribute = fn (array $substitutes = []) => $this->cards()->distribute($this->admin, $batch['batch_id'], [$card], $substitutes);

        $this->assertRuleFails('CARDS_REQUIRED', fn () => $this->cards()->distribute($this->admin, $batch['batch_id'], [], []));
        $this->assertRuleFails('CARD_SUBSTITUTE_INCOMPLETE', fn () => $distribute([['voucher_id' => $card, 'name' => 'Rosa']]));

        DB::table('households')->where('id', $household->id)->update(['status' => 'INACTIVE']);
        $this->assertRuleFails('CARD_HOUSEHOLD_INACTIVE', $distribute);

        DB::table('households')->where('id', $household->id)->update(['status' => 'ACTIVE']);
        DB::table('programme_cycles')->update(['status' => 'CLOSED', 'closed_at' => now()]);
        $this->assertRuleFails('NO_OPEN_CYCLE', $distribute);
        $this->assertSame('PENDING', DB::table('vouchers')->where('id', $card)->value('status'));
    }

    public function test_cancelling_before_delivery_frees_the_households_and_only_managers_cancel(): void
    {
        $this->household();
        $batch = $this->generate();
        $officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]);
        $url = "/admin/cards/batches/{$batch['batch_id']}/cancellation";

        $this->actingAs($officer)->post($url, ['reason' => 'erro'])->assertForbidden();
        $this->actingAs($this->admin)->post($url, ['reason' => ' '])->assertSessionHasErrors(['reason' => 'Indique o motivo do cancelamento.']);
        $this->actingAs($this->admin)->post($url, ['reason' => 'nomes errados'])->assertSessionHas('success');
        $this->assertSame('CANCELLED', DB::table('card_batches')->value('status'));
        $this->assertSame('CANCELLED', DB::table('vouchers')->value('status'));

        $this->assertSame(1, $this->generate()['card_count']);
    }

    public function test_a_lost_card_is_replaced_and_its_balance_moves_to_the_new_card(): void
    {
        ['household' => $household, 'voucher' => $old] = $this->householdWithCard();
        $officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]);
        $url = '/admin/cards/'.strtolower($old->voucher_code).'/replacement';

        $this->actingAs($officer)->postJson($url, ['reason' => 'perdido'])->assertForbidden();
        $result = $this->actingAs($this->admin)->postJson($url, ['reason' => 'perdido'])
            ->assertOk()->assertJson(['old_voucher_code' => $old->voucher_code, 'transferred' => '6000.00'])->json();

        $this->assertSame(['REPLACED', '0.00'], [DB::table('vouchers')->where('id', $old->id)->value('status'), DB::table('vouchers')->where('id', $old->id)->value('current_balance')]);
        $new = DB::table('vouchers')->where('voucher_code', $result['new_voucher_code'])->first();
        $this->assertSame(['ACTIVE', '6000.00', $household->id], [$new->status, $new->current_balance, $new->household_id]);
        $this->assertSame(2, DB::table('voucher_ledger')->where('entry_type', 'REPLACEMENT_TRANSFER')->count());
        $this->actingAs($this->admin)->postJson($url, ['reason' => 'outra'])
            ->assertUnprocessable()->assertJson(['code' => 'VOUCHER_NOT_ACTIVE', 'message' => 'Este voucher não está ativo.']);
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

    public function test_page_lists_communities_and_batches_and_a_meal_officer_sees_only_the_province(): void
    {
        $this->household();
        $batch = $this->generate();
        $elsewhere = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => Province::factory()->create()->id]);

        $this->actingAs($this->admin)->get('/admin/cards')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('admin/cards')->has('communities', 1)->has('batches', 1)->where('canManage', true));
        $this->actingAs($elsewhere)->get('/admin/cards')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('communities', 0)->has('batches', 0)->where('canManage', false));
        $this->actingAs($elsewhere)->getJson("/admin/cards/batches/{$batch['batch_id']}/cards")
            ->assertUnprocessable()->assertJson(['code' => 'CARD_BATCH_NOT_FOUND']);
        $this->actingAs(User::factory()->role(UserRole::AgrodealerOperator)->create())->get('/admin/cards')->assertForbidden();
    }
}
