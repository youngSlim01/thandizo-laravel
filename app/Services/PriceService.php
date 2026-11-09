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
 * Preços por distrito, em cada ciclo.
 *
 * Substitui set_product_price e private.district_price
 * (20261008113800_district_prices_and_catalogues.sql). Regras do projecto:
 * - cada distrito tem o seu preço e cada ciclo a sua lista (9/11/2026): o
 *   ciclo novo começa vazio e o POS usa os preços do ciclo aberto;
 * - mudar tem efeito imediato e o preço antigo fica no histórico;
 * - um distrito sem preço não vende o produto;
 * - com a feira do distrito a decorrer, mudar ou retirar um preço pede motivo
 *   (lançar um preço que faltava não pede: ninguém pagou outro valor).
 */
class PriceService
{
    public function __construct(private CyclePlanService $plans) {}

    /**
     * O ciclo aberto, onde se lançam os preços, ou null.
     */
    public function openCycleId(): ?string
    {
        return DB::table('programme_cycles')->where('status', 'OPEN')->value('id');
    }

    /**
     * Preço em vigor de um produto num distrito, no ciclo aberto, ou null.
     */
    public function currentPrice(string $productId, string $districtId): ?string
    {
        $cycleId = $this->openCycleId();

        return $cycleId === null ? null : PriceList::query()
            ->current()
            ->where('cycle_id', $cycleId)
            ->where('product_id', $productId)
            ->where('district_id', $districtId)
            ->orderByDesc('effective_from')
            ->value('unit_price');
    }

    /**
     * Preços de um ciclo (por omissão o aberto) nos distritos do programa,
     * por produto: [product_id => [district_id => '1200.00']]. Num ciclo
     * fechado são os últimos preços que teve.
     *
     * @return array<string, array<string, string>>
     */
    public function currentProgrammePrices(?string $cycleId = null): array
    {
        $cycleId ??= $this->openCycleId();
        if ($cycleId === null) {
            return [];
        }

        $prices = [];
        PriceList::query()
            ->current()
            ->where('cycle_id', $cycleId)
            ->whereIn('district_id', District::query()->inProgramme()->select('id'))
            ->orderBy('effective_from')
            ->get(['product_id', 'district_id', 'unit_price'])
            ->each(function (PriceList $price) use (&$prices): void {
                $prices[$price->product_id][$price->district_id] = $price->unit_price;
            });

        return $prices;
    }

    /**
     * Define (ou retira, com null) o preço de um produto num distrito, no
     * ciclo aberto.
     *
     * @return array{changed: bool, old_price: string|null, unit_price: string|null}
     *
     * @throws InvalidArgumentException quando não há ciclo aberto, o distrito não é do programa,
     *                                  o preço não é positivo ou falta o motivo com a feira a decorrer
     */
    public function setPrice(User $actor, Product $product, District $district, string|float|null $unitPrice, ?string $reason = null): array
    {
        if (! $district->programme_active) {
            throw new InvalidArgumentException('Escolha um distrito do programa.');
        }
        if ($unitPrice !== null && (float) $unitPrice <= 0) {
            throw new InvalidArgumentException('O preço tem de ser maior que zero.');
        }
        $newPrice = $unitPrice === null ? null : number_format(round((float) $unitPrice, 2), 2, '.', '');
        $reason = trim((string) $reason);

        return DB::transaction(function () use ($actor, $product, $district, $newPrice, $reason): array {
            $cycleId = DB::table('programme_cycles')->where('status', 'OPEN')->lockForUpdate()->value('id')
                ?? throw new InvalidArgumentException('Abra o ciclo antes de lançar preços: cada ciclo tem os seus preços.');
            // Uma mudança de cada vez por produto (antigo pg_advisory_xact_lock).
            Product::query()->whereKey($product->id)->lockForUpdate()->first();

            $oldPrice = $this->currentPrice($product->id, $district->id);
            if ($oldPrice === $newPrice) {
                return ['changed' => false, 'old_price' => $oldPrice, 'unit_price' => $oldPrice];
            }
            if ($oldPrice !== null && $reason === '' && ($this->plans->fairFor($district->id)['status'] ?? null) === 'ongoing') {
                throw new InvalidArgumentException("A feira de {$district->name} está a decorrer: indique o motivo da mudança de preço.");
            }

            $now = now();
            $open = PriceList::query()
                ->where('cycle_id', $cycleId)
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
                    'cycle_id' => $cycleId,
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
                'metadata' => ['cycle_id' => $cycleId, 'district_id' => $district->id, 'old_price' => $oldPrice, 'new_price' => $newPrice, 'reason' => $reason ?: null],
            ]);

            return ['changed' => true, 'old_price' => $oldPrice, 'unit_price' => $newPrice];
        });
    }

    /**
     * O ciclo fechado mais recente, de onde se copiam os preços.
     *
     * @return object{id: string, code: string, name: string}|null
     */
    public function previousCycle(): ?object
    {
        return DB::table('programme_cycles')->where('status', 'CLOSED')
            ->orderByDesc('closed_at')->orderByDesc('opened_at')->first(['id', 'code', 'name']);
    }

    /**
     * Copia para o ciclo aberto os preços do ciclo anterior, só onde o ciclo
     * aberto ainda não tem preço (nunca substitui um preço já lançado).
     * Produtos inactivos e distritos fora do programa ficam de fora.
     *
     * @return array{copied: int, from: string}
     *
     * @throws InvalidArgumentException sem ciclo aberto, sem ciclo anterior ou sem preços para copiar
     */
    public function copyFromPreviousCycle(User $actor): array
    {
        return DB::transaction(function () use ($actor): array {
            $cycleId = DB::table('programme_cycles')->where('status', 'OPEN')->lockForUpdate()->value('id')
                ?? throw new InvalidArgumentException('Abra o ciclo antes de lançar preços: cada ciclo tem os seus preços.');
            $previous = $this->previousCycle() ?? throw new InvalidArgumentException('Não há um ciclo anterior de onde copiar.');

            $existing = PriceList::query()->current()->where('cycle_id', $cycleId)
                ->get(['product_id', 'district_id'])->map(fn (PriceList $p): string => "{$p->product_id}:{$p->district_id}")->flip();
            $now = now();
            $copied = 0;
            PriceList::query()->current()->where('cycle_id', $previous->id)
                ->whereIn('district_id', District::query()->inProgramme()->select('id'))
                ->whereIn('product_id', Product::query()->where('status', RecordStatus::Active)->select('id'))
                ->get(['product_id', 'district_id', 'unit_price'])
                ->each(function (PriceList $price) use ($existing, $cycleId, $actor, $now, &$copied): void {
                    if ($existing->has("{$price->product_id}:{$price->district_id}")) {
                        return;
                    }
                    PriceList::query()->create([
                        'cycle_id' => $cycleId, 'product_id' => $price->product_id, 'district_id' => $price->district_id,
                        'unit_price' => $price->unit_price, 'effective_from' => $now, 'status' => RecordStatus::Active, 'created_by' => $actor->id,
                    ]);
                    $copied++;
                });
            if ($copied === 0) {
                throw new InvalidArgumentException("Nada a copiar: o ciclo {$previous->code} não tem preços que faltem neste ciclo.");
            }

            AuditLog::query()->create([
                'actor_id' => $actor->id, 'action' => 'PRICES_COPIED', 'entity_type' => 'CYCLE', 'entity_id' => $cycleId,
                'metadata' => ['from_cycle_id' => $previous->id, 'from_code' => $previous->code, 'copied' => $copied],
            ]);

            return ['copied' => $copied, 'from' => $previous->code];
        });
    }

    /**
     * Quantos produtos activos têm preço em cada distrito, no ciclo dado:
     * [district_id => n].
     *
     * @return array<string, int>
     */
    public function pricedProductsByDistrict(string $cycleId): array
    {
        return PriceList::query()->current()->where('cycle_id', $cycleId)
            ->whereIn('product_id', Product::query()->where('status', RecordStatus::Active)->select('id'))
            ->selectRaw('district_id, count(*) as n')->groupBy('district_id')
            ->pluck('n', 'district_id')->map(fn ($n): int => (int) $n)->all();
    }
}
