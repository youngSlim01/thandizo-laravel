<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\CycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\MoneyScenario;
use Tests\TestCase;

/**
 * Equivalente PHP de supabase/tests/0025_programme_cycles_tests.sql, mais o
 * acesso à página.
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
        $this->actingAs($this->admin)->post('/admin/cycles', ['name' => 'Outro'])
            ->assertSessionHasErrors(['rule' => 'Já há um ciclo aberto. Feche-o antes de abrir outro.']);
    }

    public function test_closing_expires_every_balance_and_records_it_per_household(): void
    {
        ['household' => $household, 'voucher' => $voucher] = $this->householdWithCard();
        $cycle = $this->openCycleId();

        $this->actingAs($this->admin)->getJson("/admin/cycles/{$cycle}/closing")
            ->assertJson(['committed' => false, 'households_with_balance' => 1, 'unused_amount' => 6000]);
        $this->actingAs($this->admin)->post("/admin/cycles/{$cycle}/closing")->assertSessionHas('success');

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
        $cycles = app(CycleService::class);
        $cycles->close($this->admin, $this->openCycleId(), true);
        $this->actingAs($this->admin)->post('/admin/cycles', ['name' => 'Ciclo 2'])->assertSessionHas('success');
        $cycle = $this->openCycleId();
        $this->assertSame('CIC-02', DB::table('programme_cycles')->where('id', $cycle)->value('code'));

        $preview = $this->actingAs($this->admin)->postJson("/admin/cycles/{$cycle}/allocation/preview", [
            'household_codes' => [$withCard->household_code, $noCard->household_code, '0000AF000000'],
        ])->assertJson(['committed' => false, 'to_allocate' => 1, 'total_amount' => 6000])->json();
        $this->assertEquals(['NO_DELIVERED_CARD' => 1, 'UNKNOWN_HOUSEHOLD' => 1], $preview['left_out']);

        $this->actingAs($this->admin)->post("/admin/cycles/{$cycle}/allocation", ['household_ids' => [$withCard->id]])
            ->assertSessionHas('success', '1 agregado(s) recarregado(s), no total de 6 000 MT, no mesmo cartão.');
        $this->assertSame('6000.00', DB::table('vouchers')->where('id', $voucher->id)->value('current_balance'));
        $this->assertSame('CYCLE_ALLOCATION', DB::table('voucher_ledger')->orderByDesc('created_at')->orderByDesc('id')->value('entry_type'));

        $again = $cycles->allocate($this->admin, $cycle, [$withCard->id], null, false);
        $this->assertSame([0, ['ALREADY_IN_CYCLE' => 1]], [$again['to_allocate'], (array) $again['left_out']]);
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

        $this->assertRuleFails('CYCLE_RECENT_SALES', fn () => app(CycleService::class)->close($this->admin, $this->openCycleId(), true));
    }

    public function test_officers_see_the_cycles_and_only_managers_change_them(): void
    {
        $officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]);
        $cycle = $this->openCycleId();

        $this->actingAs($officer)->get('/admin/cycles')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('admin/cycles')->has('cycles', 1)->where('canManage', false));
        $this->actingAs($officer)->getJson("/cycles/{$cycle}/summary")->assertOk();
        $this->actingAs($officer)->post("/admin/cycles/{$cycle}/closing")->assertForbidden();
        $this->actingAs($officer)->post('/admin/cycles', ['name' => 'Outro'])->assertForbidden();
    }

    public function test_the_manager_renames_the_cycle_and_sets_its_scope_and_dates(): void
    {
        $cycle = $this->openCycleId();
        DB::table('cycle_districts')->delete();
        $officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->district->province_id]);
        $edit = ['name' => 'AA - Janela 1', 'description' => 'Acções antecipadas à seca, Out–Dez', 'starts_on' => '2026-10-01', 'ends_on' => '2026-12-31'];

        $this->actingAs($officer)->put("/admin/cycles/{$cycle}", $edit)->assertForbidden();
        $this->actingAs($this->admin)->put("/admin/cycles/{$cycle}", $edit)->assertSessionHas('success', 'Dados do ciclo guardados.');
        $saved = DB::table('programme_cycles')->where('id', $cycle)->first();
        $this->assertSame(['CIC-01', 'AA - Janela 1', 'Acções antecipadas à seca, Out–Dez', '2026-12-31'], [$saved->code, $saved->name, $saved->description, $saved->ends_on]);
        $this->assertSame('AA - Janela 1', DB::table('audit_logs')->where('action', 'CYCLE_UPDATED')->value('metadata->changes->name->new'));

        $this->actingAs($this->admin)->put("/admin/cycles/{$cycle}", [...$edit, 'name' => ' '])
            ->assertSessionHasErrors(['rule' => 'Indique o nome do ciclo (ex.: AA - Janela 1).']);
        $this->actingAs($this->admin)->put("/admin/cycles/{$cycle}", [...$edit, 'ends_on' => '2026-09-01'])->assertSessionHasErrors('rule');

        app(CycleService::class)->close($this->admin, $cycle, true);
        $this->actingAs($this->admin)->put("/admin/cycles/{$cycle}", [...$edit, 'ends_on' => '2027-01-31'])
            ->assertSessionHasErrors(['rule' => 'As datas de um ciclo fechado não mudam. Pode mudar o nome e a descrição.']);
        $closedEnd = DB::table('programme_cycles')->where('id', $cycle)->value('ends_on');
        $this->actingAs($this->admin)->put("/admin/cycles/{$cycle}", [...$edit, 'name' => 'AA - Janela 1 (fechada)', 'ends_on' => $closedEnd])->assertSessionHas('success');
        $this->assertSame('AA - Janela 1 (fechada)', DB::table('programme_cycles')->where('id', $cycle)->value('name'));
    }
}
