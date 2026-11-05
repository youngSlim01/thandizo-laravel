<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Preços por distrito.
 *
 * Substitui set_product_price e private.district_price
 * (20261008113800_district_prices_and_catalogues.sql). Regras do projecto:
 * cada distrito tem o seu preço; mudar tem efeito imediato; o preço antigo
 * fica no histórico; um distrito sem preço não vende o produto.
 */
class PriceService
{
    /**
     * Preço em vigor de um produto num distrito, ou null.
     */
    public function currentPrice(string $productId, string $districtId): ?string
    {
        return PriceList::query()
            ->current()
            ->where('product_id', $productId)
            ->where('district_id', $districtId)
            ->orderByDesc('effective_from')
            ->value('unit_price');
    }

    /**
     * Preços em vigor nos distritos do programa, por produto:
     * [product_id => [district_id => '1200.00']].
     *
     * @return array<string, array<string, string>>
     */
    public function currentProgrammePrices(): array
    {
        $prices = [];
        PriceList::query()
            ->current()
            ->whereIn('district_id', District::query()->inProgramme()->select('id'))
            ->orderBy('effective_from')
            ->get(['product_id', 'district_id', 'unit_price'])
            ->each(function (PriceList $price) use (&$prices): void {
                $prices[$price->product_id][$price->district_id] = $price->unit_price;
            });

        return $prices;
    }

    /**
     * Define (ou retira, com null) o preço de um produto num distrito.
     *
     * @return array{changed: bool, old_price: string|null, unit_price: string|null}
     *
     * @throws InvalidArgumentException quando o distrito não é do programa ou o preço não é positivo
     */
    public function setPrice(User $actor, Product $product, District $district, string|float|null $unitPrice): array
    {
        if (! $district->programme_active) {
            throw new InvalidArgumentException('Escolha um distrito do programa.');
        }
        if ($unitPrice !== null && (float) $unitPrice <= 0) {
            throw new InvalidArgumentException('O preço tem de ser maior que zero.');
        }
        $newPrice = $unitPrice === null ? null : number_format(round((float) $unitPrice, 2), 2, '.', '');

        return DB::transaction(function () use ($actor, $product, $district, $newPrice): array {
            // Uma mudança de cada vez por produto (antigo pg_advisory_xact_lock).
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $oldPrice = $this->currentPrice($product->id, $district->id);
            if ($oldPrice === $newPrice) {
                return ['changed' => false, 'old_price' => $oldPrice, 'unit_price' => $oldPrice];
            }

            $now = now();
            $open = PriceList::query()
                ->where('product_id', $product->id)
                ->where('district_id', $district->id)
                ->where('status', RecordStatus::Active)
                ->whereNull('effective_to');
            // Fecha agora o preço aberto. Um preço aberto neste mesmo segundo
            // fica inactivo (o fim tem de ser depois do início).
            (clone $open)->where('effective_from', '<', $now)->update(['effective_to' => $now]);
            (clone $open)->update(['status' => RecordStatus::Inactive]);

            if ($newPrice !== null) {
                PriceList::query()->create([
                    'product_id' => $product->id,
                    'district_id' => $district->id,
                    'unit_price' => $newPrice,
                    'effective_from' => $now,
                    'status' => RecordStatus::Active,
                    'created_by' => $actor->id,
                ]);
            }

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'PRICE_SET',
                'entity_type' => 'PRODUCT',
                'entity_id' => $product->id,
                'metadata' => ['district_id' => $district->id, 'old_price' => $oldPrice, 'new_price' => $newPrice],
            ]);

            return ['changed' => true, 'old_price' => $oldPrice, 'unit_price' => $newPrice];
        });
    }
}
