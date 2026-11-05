<?php

namespace Tests\Feature;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Equivalente PHP de save_product / next_product_code (20261008114300) e dos
 * casos de produto de supabase/tests/0020 e 0021.
 */
class ProductTest extends TestCase
{
    use RefreshDatabase;

    private function manager(): User
    {
        return User::factory()->role(UserRole::MealManager)->create();
    }

    public function test_guest_is_sent_to_login(): void
    {
        $this->get('/admin/products')->assertRedirect('/login');
    }

    public function test_finance_cannot_open_the_page(): void
    {
        $this->actingAs(User::factory()->role(UserRole::FinanceOfficer)->create())
            ->get('/admin/products')
            ->assertForbidden();
    }

    public function test_list_shows_active_products_first_then_by_name(): void
    {
        Product::factory()->inactive()->create(['name' => 'Adubo antigo']);
        Product::factory()->create(['name' => 'Semente de milho']);
        Product::factory()->create(['name' => 'Enxada']);

        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create())
            ->get('/admin/products')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/products')
                ->where('products.0.name', 'Enxada')
                ->where('products.1.name', 'Semente de milho')
                ->where('products.2.name', 'Adubo antigo')
                ->where('products.2.status', 'INACTIVE')
                ->where('canManage', false));
    }

    public function test_meal_officer_cannot_create(): void
    {
        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create())
            ->post('/admin/products', ['name' => 'Enxada', 'unit' => 'un'])
            ->assertForbidden();

        $this->assertDatabaseCount('products', 0);
    }

    public function test_first_product_gets_prd_0001_and_is_audited(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->post('/admin/products', ['name' => ' Semente de milho ', 'unit' => 'kg', 'category' => ''])
            ->assertRedirect('/admin/products')
            ->assertSessionHas('success', 'Produto Semente de milho (PRD-0001) criado sem preços: não se vende até ter preço no distrito.');

        $product = Product::query()->sole();
        $this->assertSame('PRD-0001', $product->code);
        $this->assertSame('Semente de milho', $product->name);
        $this->assertNull($product->category);
        $this->assertSame(RecordStatus::Active, $product->status);

        $audit = AuditLog::query()->sole();
        $this->assertSame($manager->id, $audit->actor_id);
        $this->assertSame('PRODUCT_CREATED', $audit->action);
        $this->assertSame($product->id, $audit->entity_id);
        $this->assertEquals(['code' => 'PRD-0001', 'name' => 'Semente de milho', 'unit' => 'kg', 'status' => 'ACTIVE'], $audit->metadata);
    }

    public function test_next_code_follows_the_highest_prd_number(): void
    {
        Product::factory()->create(['code' => 'PRD-0007']);
        Product::factory()->create(['code' => 'PRD-0002']);
        Product::factory()->create(['code' => 'T-SEED']);

        $this->actingAs($this->manager())
            ->post('/admin/products', ['name' => 'Enxada', 'unit' => 'un']);

        $this->assertSame('PRD-0008', Product::query()->where('name', 'Enxada')->value('code'));
    }

    public function test_name_and_unit_are_required(): void
    {
        $this->actingAs($this->manager())
            ->post('/admin/products', ['name' => '  ', 'unit' => ''])
            ->assertSessionHasErrors([
                'name' => 'Indique o nome e a unidade do produto.',
                'unit' => 'Indique o nome e a unidade do produto.',
            ]);

        $this->assertDatabaseCount('products', 0);
    }

    public function test_name_cannot_repeat_ignoring_case_and_accents(): void
    {
        Product::factory()->create(['name' => 'Semente de milho']);

        $this->actingAs($this->manager())
            ->post('/admin/products', ['name' => 'SEMENTE DE MÍLHO', 'unit' => 'kg'])
            ->assertSessionHasErrors(['name' => 'Já existe um produto com este nome.']);

        $this->assertDatabaseCount('products', 1);
    }

    public function test_update_changes_fields_keeps_code_and_is_audited(): void
    {
        $product = Product::factory()->create(['code' => 'PRD-0003', 'name' => 'Enxada', 'unit' => 'un']);

        $this->actingAs($this->manager())
            ->put("/admin/products/{$product->id}", [
                'name' => 'Enxada grande', 'unit' => 'unidade', 'category' => 'Ferramentas', 'status' => 'INACTIVE', 'code' => 'PRD-9999',
            ])
            ->assertRedirect('/admin/products')
            ->assertSessionHas('success', 'Produto Enxada grande atualizado.');

        $product->refresh();
        $this->assertSame('PRD-0003', $product->code);
        $this->assertSame('Enxada grande', $product->name);
        $this->assertSame('unidade', $product->unit);
        $this->assertSame('Ferramentas', $product->category);
        $this->assertSame(RecordStatus::Inactive, $product->status);
        $this->assertSame('PRODUCT_UPDATED', AuditLog::query()->sole()->action);
    }

    public function test_update_may_keep_its_own_name(): void
    {
        $product = Product::factory()->create(['name' => 'Enxada']);

        $this->actingAs($this->manager())
            ->put("/admin/products/{$product->id}", ['name' => 'enxada', 'unit' => 'un', 'status' => 'ACTIVE'])
            ->assertSessionHasNoErrors();
    }

    public function test_update_cannot_take_another_products_name(): void
    {
        Product::factory()->create(['name' => 'Enxada']);
        $product = Product::factory()->create(['name' => 'Catana']);

        $this->actingAs($this->manager())
            ->put("/admin/products/{$product->id}", ['name' => 'Enxada', 'unit' => 'un', 'status' => 'ACTIVE'])
            ->assertSessionHasErrors(['name' => 'Já existe um produto com este nome.']);

        $this->assertSame('Catana', $product->fresh()->name);
    }

    public function test_update_refuses_an_unknown_status(): void
    {
        $product = Product::factory()->create();

        $this->actingAs($this->manager())
            ->put("/admin/products/{$product->id}", ['name' => 'Enxada', 'unit' => 'un', 'status' => 'DELETED'])
            ->assertSessionHasErrors(['status' => 'Situação inválida.']);
    }

    public function test_meal_officer_cannot_update(): void
    {
        $product = Product::factory()->create(['name' => 'Enxada']);

        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create())
            ->put("/admin/products/{$product->id}", ['name' => 'Outro', 'unit' => 'un', 'status' => 'ACTIVE'])
            ->assertForbidden();

        $this->assertSame('Enxada', $product->fresh()->name);
    }
}
