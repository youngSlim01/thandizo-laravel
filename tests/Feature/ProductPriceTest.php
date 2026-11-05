<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use App\Services\PriceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Equivalente PHP de set_product_price / district_price (20261008113800) e dos
 * casos de preço de supabase/tests/0020_district_prices_and_catalogues_tests.sql.
 */
class ProductPriceTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private Product $product;

    private District $mutarara;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->role(UserRole::MealManager)->create();
        $this->product = Product::factory()->create(['name' => 'Semente', 'unit' => 'kg']);
        $this->mutarara = District::factory()->inProgramme()->create(['name' => 'Mutarara']);
    }

    private function setPrice(?float $price): TestResponse
    {
        return $this->actingAs($this->manager)
            ->from('/admin/products')
            ->put("/admin/products/{$this->product->id}/prices/{$this->mutarara->id}", ['unit_price' => $price]);
    }

    private function current(): ?string
    {
        return app(PriceService::class)->currentPrice($this->product->id, $this->mutarara->id);
    }

    public function test_setting_a_price_opens_it_now_and_is_audited(): void
    {
        $this->setPrice(1000)
            ->assertRedirect('/admin/products')
            ->assertSessionHas('success', 'Semente em Mutarara: 1000 MT / kg, já em vigor.');

        $this->assertSame('1000.00', $this->current());
        $audit = AuditLog::query()->sole();
        $this->assertSame('PRICE_SET', $audit->action);
        $this->assertEquals(['district_id' => $this->mutarara->id, 'old_price' => null, 'new_price' => '1000.00'], $audit->metadata);
    }

    public function test_changing_a_price_closes_the_old_one_and_keeps_the_history(): void
    {
        $this->setPrice(1000);
        $this->travel(5)->minutes();
        $this->setPrice(1100.5);

        $this->assertSame('1100.50', $this->current());
        $history = PriceList::query()->orderBy('effective_from')->get();
        $this->assertCount(2, $history);
        $this->assertNotNull($history[0]->effective_to);
        $this->assertSame('1000.00', $history[0]->unit_price);
        $this->assertNull($history[1]->effective_to);
    }

    public function test_two_changes_in_the_same_second_leave_one_open_price(): void
    {
        $this->freezeSecond();
        $this->setPrice(1000);
        $this->setPrice(1200);

        $this->assertSame('1200.00', $this->current());
        $this->assertSame(1, PriceList::query()->where('status', RecordStatus::Active)->whereNull('effective_to')->count());
    }

    public function test_same_price_changes_nothing(): void
    {
        $this->setPrice(1000);
        $this->setPrice(1000);

        $this->assertSame(1, PriceList::query()->count());
        $this->assertSame(1, AuditLog::query()->count());
    }

    public function test_removing_the_price_stops_the_sale_there(): void
    {
        $this->setPrice(1000);
        $this->travel(1)->minutes();

        $this->setPrice(null)->assertSessionHas('success', 'Semente deixou de ter preço em Mutarara: não se vende lá.');

        $this->assertNull($this->current());
        $this->assertSame(1, PriceList::query()->count());
    }

    public function test_price_must_be_positive_and_in_a_programme_district(): void
    {
        $this->setPrice(0)->assertSessionHasErrors(['unit_price' => 'O preço tem de ser maior que zero.']);

        $outside = District::factory()->create();
        $this->actingAs($this->manager)
            ->put("/admin/products/{$this->product->id}/prices/{$outside->id}", ['unit_price' => 900])
            ->assertSessionHasErrors(['unit_price' => 'Escolha um distrito do programa.']);

        $this->assertSame(0, PriceList::query()->count());
    }

    public function test_meal_officer_cannot_set_prices(): void
    {
        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create())
            ->put("/admin/products/{$this->product->id}/prices/{$this->mutarara->id}", ['unit_price' => 1000])
            ->assertForbidden();
    }

    public function test_product_list_shows_the_price_of_each_programme_district(): void
    {
        $this->setPrice(1000);

        $this->actingAs($this->manager)
            ->get('/admin/products')
            ->assertInertia(fn (Assert $page) => $page
                ->where("products.0.prices.{$this->mutarara->id}", '1000.00')
                ->where('products.0.agrodealers', 0));
    }

    public function test_new_product_with_prices_is_created_with_them(): void
    {
        $response = $this->actingAs($this->manager)
            ->post('/admin/products', ['name' => 'Enxada', 'unit' => 'un', 'prices' => [$this->mutarara->id => 350]]);

        $enxada = Product::query()->where('name', 'Enxada')->sole();
        $response->assertSessionHas('success', "Produto Enxada ({$enxada->code}) criado com preço em 1 distrito(s).");
        $this->assertSame('350.00', app(PriceService::class)->currentPrice($enxada->id, $this->mutarara->id));
    }

    public function test_new_product_with_a_bad_price_is_not_created(): void
    {
        $this->actingAs($this->manager)
            ->post('/admin/products', ['name' => 'Enxada', 'unit' => 'un', 'prices' => [$this->mutarara->id => -5]])
            ->assertSessionHasErrors();

        $this->assertFalse(Product::query()->where('name', 'Enxada')->exists());
    }
}
