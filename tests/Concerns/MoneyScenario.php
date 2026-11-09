<?php

namespace Tests\Concerns;

use App\Enums\UserRole;
use App\Exceptions\DomainRuleException;
use App\Models\AdministrativePost;
use App\Models\Agrodealer;
use App\Models\AgrodealerPos;
use App\Models\Community;
use App\Models\District;
use App\Models\Household;
use App\Models\Locality;
use App\Models\Product;
use App\Models\User;
use App\Services\CardService;
use App\Services\CycleService;
use App\Services\PriceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cenário de dinheiro para os testes dos níveis 7 a 11: um distrito do
 * programa com um produto a 1 000 MT, um agrodealer com POS e o produto no
 * catálogo, o seu operador, um bairro com agregados e um ciclo aberto.
 *
 * O Laravel chama setUpMoneyScenario() sozinho (setUp<NomeDoTrait>).
 */
trait MoneyScenario
{
    protected User $admin;

    protected User $operator;

    protected District $district;

    protected Community $community;

    protected Agrodealer $agrodealer;

    protected AgrodealerPos $pos;

    protected Product $product;

    protected function setUpMoneyScenario(): void
    {
        $this->admin = User::factory()->role(UserRole::SystemAdmin)->create();
        $this->district = District::factory()->inProgramme()->create(['name' => 'Mutarara']);
        $locality = Locality::factory()->create(['administrative_post_id' => AdministrativePost::factory()->create(['district_id' => $this->district->id])->id]);
        $this->community = Community::factory()->create(['locality_id' => $locality->id, 'district_id' => $this->district->id]);
        $this->agrodealer = Agrodealer::factory()->create([
            'code' => 'MER-MUT-001', 'district_id' => $this->district->id, 'province_id' => $this->district->province_id,
        ]);
        $this->pos = $this->agrodealer->pos()->create(['pos_code' => 'POS-MUT-001-01', 'status' => 'ACTIVE']);
        $this->operator = User::factory()->role(UserRole::AgrodealerOperator)->create(['agrodealer_id' => $this->agrodealer->id]);
        $this->product = Product::factory()->create(['name' => 'Semente', 'unit' => 'kg']);
        // Preço e ciclo desde o início do ano, para as vendas em datas passadas.
        $this->travelTo(now()->startOfYear());
        app(CycleService::class)->open($this->admin, 'Ciclo 1', null, null);
        app(PriceService::class)->setPrice($this->admin, $this->product, $this->district, 1000);
        $this->travelBack();
        // Feira do distrito durante todo o ano: os testes vendem em várias datas.
        $this->fairAllYear();
        $this->agrodealer->catalogue()->attach($this->product->id);
    }

    /**
     * Feira do distrito no ciclo aberto de 1 de Janeiro a 31 de Dezembro.
     */
    protected function fairAllYear(): void
    {
        DB::table('cycle_districts')->updateOrInsert(
            ['cycle_id' => DB::table('programme_cycles')->where('status', 'OPEN')->value('id'), 'district_id' => $this->district->id],
            ['id' => (string) Str::uuid7(), 'fair_starts_on' => now()->startOfYear()->toDateString(), 'fair_ends_on' => now()->endOfYear()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    protected function household(array $overrides = []): Household
    {
        return Household::factory()->inCommunity($this->community)->create($overrides);
    }

    /**
     * Agregado com um cartão entregue (ACTIVE, 6 000 MT), pelo fluxo normal:
     * lote, impressão e distribuição.
     *
     * @return array{household: Household, voucher: object}
     */
    protected function householdWithCard(array $overrides = []): array
    {
        $household = $this->household($overrides);
        $cards = app(CardService::class);
        $batch = $cards->generateBatch($this->admin, $this->community->id);
        $cards->markPrinted($this->admin, $batch['batch_id']);
        $voucherId = DB::table('vouchers')->where('household_id', $household->id)->where('status', 'PENDING')->value('id');
        $cards->distribute($this->admin, $batch['batch_id'], [$voucherId], []);

        return ['household' => $household, 'voucher' => DB::table('vouchers')->where('id', $voucherId)->first()];
    }

    /**
     * A acção tem de ser recusada pela regra com este código.
     */
    protected function assertRuleFails(string $code, callable $action): void
    {
        try {
            $action();
        } catch (DomainRuleException $exception) {
            $this->assertSame($code, $exception->errorCode);

            return;
        }
        $this->fail("A regra {$code} devia recusar a acção.");
    }
}
