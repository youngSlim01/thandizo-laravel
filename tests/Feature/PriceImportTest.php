<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Equivalente PHP de import_product_prices (20261008114300) e de
 * supabase/tests/0021_import_product_prices_tests.sql.
 */
class PriceImportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private District $mutarara;

    private District $guro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->role(UserRole::MealManager)->create();
        $this->mutarara = District::factory()->inProgramme()->create(['name' => 'Mutarara']);
        $this->guro = District::factory()->inProgramme()->create(['name' => 'Guro']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function import(array $rows): TestResponse
    {
        return $this->actingAs($this->manager)->post('/admin/products/import', ['file_name' => 'precos.xlsx', 'rows' => $rows]);
    }

    private function price(Product $product, District $district): ?string
    {
        return app(PriceService::class)->currentPrice($product->id, $district->id);
    }

    public function test_existing_products_get_the_new_prices_and_the_import_is_audited(): void
    {
        $seed = Product::factory()->create(['code' => 'PRD-0001']);
        app(PriceService::class)->setPrice($this->manager, $seed, $this->guro, 800);

        $this->import([['code' => 'prd-0001', 'prices' => [$this->mutarara->id => 1000, $this->guro->id => 800]]])
            ->assertRedirect('/admin/products')
            ->assertSessionHas('success', 'precos.xlsx: 1 preço(s) atualizados, 1 sem alteração. Já em vigor.');

        $this->assertSame('1000.00', $this->price($seed, $this->mutarara));
        $audit = AuditLog::query()->where('action', 'PRICES_IMPORTED')->sole();
        $this->assertEquals(['file_name' => 'precos.xlsx', 'rows' => 1, 'products_created' => 0, 'prices_set' => 1, 'prices_unchanged' => 1], $audit->metadata);
    }

    public function test_empty_code_creates_a_new_product_with_a_generated_code(): void
    {
        $this->import([['code' => '', 'name' => 'Produto novo', 'unit' => 'kg', 'category' => 'Sementes', 'prices' => [$this->guro->id => '1500.5']]])
            ->assertSessionHas('success', 'precos.xlsx: 1 preço(s) atualizados, 1 produto(s) novo(s). Já em vigor.');

        $product = Product::query()->where('name', 'Produto novo')->sole();
        $this->assertMatchesRegularExpression('/^PRD-\d{4}$/', $product->code);
        $this->assertSame('1500.50', $this->price($product, $this->guro));
    }

    public function test_unknown_code_is_refused_and_nothing_is_saved(): void
    {
        $seed = Product::factory()->create(['code' => 'PRD-0001']);

        $this->import([
            ['code' => 'PRD-0001', 'prices' => [$this->mutarara->id => 1000]],
            ['code' => 'PRD-9999', 'prices' => []],
        ])->assertSessionHasErrors(['rows' => 'Linha 2: o código PRD-9999 não existe. Para um produto novo deixe o CODIGO vazio.']);

        $this->assertNull($this->price($seed, $this->mutarara));
        $this->assertSame(0, PriceList::query()->count());
    }

    public function test_a_new_product_cannot_take_the_name_of_another(): void
    {
        Product::factory()->create(['name' => 'Semente teste']);

        $this->import([['code' => '', 'name' => 'SEMENTE TESTE', 'unit' => 'kg']])
            ->assertSessionHasErrors(['rows' => 'Linha 1: já existe um produto chamado SEMENTE TESTE. Use o CODIGO dele.']);
    }

    public function test_a_new_product_needs_name_and_unit(): void
    {
        $this->import([['code' => '', 'name' => 'Sem unidade', 'unit' => '']])
            ->assertSessionHasErrors(['rows' => 'Linha 1: produto novo sem CODIGO; indique PRODUTO e UNIDADE.']);
    }

    public function test_inactive_product_is_refused(): void
    {
        Product::factory()->inactive()->create(['code' => 'PRD-0003']);

        $this->import([['code' => 'PRD-0003', 'prices' => [$this->guro->id => 100]]])
            ->assertSessionHasErrors(['rows' => 'Linha 1: o produto PRD-0003 está inativo.']);
    }

    public function test_district_outside_the_programme_and_bad_prices_are_refused(): void
    {
        Product::factory()->create(['code' => 'PRD-0001']);
        $outside = District::factory()->create();

        $this->import([['code' => 'PRD-0001', 'prices' => [$outside->id => 100]]])
            ->assertSessionHasErrors(['rows' => 'Linha 1: distrito fora do programa.']);
        $this->import([['code' => 'PRD-0001', 'prices' => [$this->guro->id => 0]]])
            ->assertSessionHasErrors(['rows' => 'Linha 1: o preço tem de ser maior que zero.']);
    }

    public function test_empty_file_and_officer_are_refused(): void
    {
        $this->import([])->assertSessionHasErrors(['rows' => 'O ficheiro não tem linhas de produtos.']);

        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create())
            ->post('/admin/products/import', ['rows' => [['code' => 'PRD-0001']]])
            ->assertForbidden();
    }
}
