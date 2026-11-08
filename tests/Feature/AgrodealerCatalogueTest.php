<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Equivalente PHP de get_agrodealer_catalogue e set_agrodealer_product
 * (20261008113800, 20261008114600) e dos casos de catálogo de
 * supabase/tests/0020 e 0027.
 */
class AgrodealerCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Agrodealer $agrodealer;

    private Product $seed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->role(UserRole::MealManager)->create();
        $this->agrodealer = Agrodealer::factory()->create();
        $this->seed = Product::factory()->create(['name' => 'Semente']);
    }

    private function url(?Product $product = null): string
    {
        return "/admin/comerciantes/{$this->agrodealer->id}/catalogue".($product ? "/{$product->id}" : '');
    }

    public function test_catalogue_lists_active_products_with_the_district_price(): void
    {
        app(PriceService::class)->setPrice($this->manager, $this->seed, $this->agrodealer->district, 1000);
        Product::factory()->create(['name' => 'Adubo']);
        Product::factory()->inactive()->create(['name' => 'Antigo']);

        $this->actingAs($this->manager)->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(2, 'products')
            ->assertJsonPath('products.0.name', 'Adubo')
            ->assertJsonPath('products.0.unit_price', null)
            ->assertJsonPath('products.1.name', 'Semente')
            ->assertJsonPath('products.1.unit_price', '1000.00')
            ->assertJsonPath('products.1.in_catalogue', false);
    }

    public function test_adding_and_removing_a_product_is_audited_once(): void
    {
        $this->actingAs($this->manager)->putJson($this->url($this->seed), ['in_catalogue' => true])->assertJson(['changed' => true]);
        $this->actingAs($this->manager)->putJson($this->url($this->seed), ['in_catalogue' => true])->assertJson(['changed' => false]);
        $this->assertTrue($this->agrodealer->catalogue()->whereKey($this->seed->id)->exists());

        $this->actingAs($this->manager)->putJson($this->url($this->seed), ['in_catalogue' => false])->assertJson(['changed' => true]);
        $this->assertFalse($this->agrodealer->catalogue()->exists());

        $this->assertSame(['CATALOGUE_PRODUCT_ADDED', 'CATALOGUE_PRODUCT_REMOVED'], AuditLog::query()->orderBy('created_at')->orderBy('action')->pluck('action')->all());
    }

    public function test_an_inactive_product_cannot_be_added(): void
    {
        $old = Product::factory()->inactive()->create();

        $this->actingAs($this->manager)->putJson($this->url($old), ['in_catalogue' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['product' => 'Produto não encontrado ou inativo.']);
    }

    public function test_the_agrodealer_manager_manages_only_their_own_catalogue(): void
    {
        $own = User::factory()->role(UserRole::AgrodealerManager)->create(['agrodealer_id' => $this->agrodealer->id]);
        $other = User::factory()->role(UserRole::AgrodealerManager)->create(['agrodealer_id' => Agrodealer::factory()->create()->id]);
        $operator = User::factory()->role(UserRole::AgrodealerOperator)->create(['agrodealer_id' => $this->agrodealer->id]);

        $this->actingAs($own)->putJson($this->url($this->seed), ['in_catalogue' => true])->assertOk();
        $this->actingAs($other)->putJson($this->url($this->seed), ['in_catalogue' => false])->assertForbidden();
        $this->actingAs($operator)->putJson($this->url($this->seed), ['in_catalogue' => false])->assertForbidden();
        $this->actingAs($operator)->getJson($this->url())->assertOk();
        $this->actingAs($other)->getJson($this->url())->assertForbidden();
    }

    public function test_meal_officer_reads_only_catalogues_of_the_province_and_cannot_change_them(): void
    {
        $inProvince = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $this->agrodealer->province_id]);
        $elsewhere = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => Agrodealer::factory()->create()->province_id]);

        $this->actingAs($inProvince)->getJson($this->url())->assertOk();
        $this->actingAs($inProvince)->putJson($this->url($this->seed), ['in_catalogue' => true])->assertForbidden();
        $this->actingAs($elsewhere)->getJson($this->url())->assertForbidden();
    }

    public function test_agrodealer_list_counts_the_catalogue(): void
    {
        $this->agrodealer->catalogue()->attach($this->seed->id);

        $this->actingAs($this->manager)->get('/admin/comerciantes')
            ->assertInertia(fn (Assert $page) => $page->where('agrodealers.0.catalogue_count', 1));
    }
}
