<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Um preço de um produto num distrito, válido de effective_from até
 * effective_to (aberto quando effective_to é null). Nunca se apaga.
 */
#[Fillable(['product_id', 'district_id', 'unit_price', 'effective_from', 'effective_to', 'status', 'created_by'])]
#[Hidden(['open_price_key'])]
class PriceList extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'effective_from' => 'datetime',
            'effective_to' => 'datetime',
            'status' => RecordStatus::class,
        ];
    }

    /**
     * Preços em vigor neste momento (antigo private.district_price).
     *
     * @param  Builder<PriceList>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->where('status', RecordStatus::Active)
            ->where('effective_from', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('effective_to')->orWhere('effective_to', '>', now()));
    }
}
