<?php

namespace Tests\Concerns;

use App\Models\User;
use App\Services\PosService;
use Carbon\CarbonImmutable;

/**
 * Vendas feitas no POS em datas passadas (as reconciliações só aceitam
 * períodos já terminados).
 */
trait SellsInThePast
{
    protected function saleOn(string $localDateTime, object $voucher, float $quantity = 1, ?User $operator = null): array
    {
        $this->travelTo(CarbonImmutable::parse($localDateTime, 'Africa/Maputo'));
        $operator ??= $this->operator;
        $pos = app(PosService::class);
        $items = [['product_id' => $this->product->id, 'quantity' => $quantity]];
        $preview = $pos->preview($operator, $voucher->voucher_code, $this->agrodealer->id, $this->pos->id, $operator->id, $items, null, 0);
        $sale = $pos->confirm($operator, uniqid('k', true), $voucher->voucher_code, $this->pos->id, $items, null, 0, $preview['total_amount'], $preview['voucher_amount']);
        $this->travelBack();

        return $sale;
    }
}
