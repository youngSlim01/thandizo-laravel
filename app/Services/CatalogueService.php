<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\Agrodealer;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Catálogo de cada agrodealer: os produtos que vende. O preço vem sempre do
 * distrito do agrodealer; não há preço por agrodealer.
 *
 * Substitui get_agrodealer_catalogue e set_agrodealer_product (20261008113800,
 * 20261008114600).
 */
class CatalogueService
{
    public function __construct(private PriceService $prices) {}

    /**
     * Produtos activos, com a indicação de estarem no catálogo e o preço no
     * distrito do agrodealer.
     *
     * @return array{agrodealer_id: string, name: string, district: string|null, products: list<array{product_id: string, code: string, name: string, unit: string, category: string|null, in_catalogue: bool, unit_price: string|null}>}
     */
    public function catalogueFor(Agrodealer $agrodealer): array
    {
        $inCatalogue = $agrodealer->catalogue()->pluck('products.id')->flip();
        $districtPrices = [];
        if ($agrodealer->district_id !== null) {
            foreach ($this->prices->currentProgrammePrices() as $productId => $byDistrict) {
                $districtPrices[$productId] = $byDistrict[$agrodealer->district_id] ?? null;
            }
        }

        $products = Product::query()
            ->where('status', RecordStatus::Active)
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product): array => [
                'product_id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'unit' => $product->unit,
                'category' => $product->category,
                'in_catalogue' => $inCatalogue->has($product->id),
                'unit_price' => $districtPrices[$product->id] ?? null,
            ])
            ->all();

        return [
            'agrodealer_id' => $agrodealer->id,
            'name' => $agrodealer->name,
            'district' => $agrodealer->district?->name,
            'products' => $products,
        ];
    }

    /**
     * Junta ou retira um produto do catálogo. Só se juntam produtos activos.
     *
     * @return bool verdadeiro quando o catálogo mudou
     */
    public function setProduct(User $actor, Agrodealer $agrodealer, Product $product, bool $inCatalogue): bool
    {
        if ($inCatalogue && $product->status !== RecordStatus::Active) {
            throw new InvalidArgumentException('Produto não encontrado ou inativo.');
        }

        return DB::transaction(function () use ($actor, $agrodealer, $product, $inCatalogue): bool {
            if ($inCatalogue) {
                $changed = DB::table('agrodealer_products')->insertOrIgnore([
                    'agrodealer_id' => $agrodealer->id,
                    'product_id' => $product->id,
                    'created_at' => now(),
                    'created_by' => $actor->id,
                ]) > 0;
            } else {
                $changed = $agrodealer->catalogue()->detach($product->id) > 0;
            }

            if ($changed) {
                AuditLog::query()->create([
                    'actor_id' => $actor->id,
                    'action' => $inCatalogue ? 'CATALOGUE_PRODUCT_ADDED' : 'CATALOGUE_PRODUCT_REMOVED',
                    'entity_type' => 'AGRODEALER',
                    'entity_id' => $agrodealer->id,
                    'metadata' => ['product_id' => $product->id],
                ]);
            }

            return $changed;
        });
    }
}
