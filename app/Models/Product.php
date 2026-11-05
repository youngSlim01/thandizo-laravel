<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Insumo agrícola vendido nos agrodealers. O código (PRD-NNNN) é gerado ao
 * criar e nunca muda; ver ProductService.
 */
#[Fillable(['code', 'name', 'category', 'unit', 'status'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
        ];
    }

    /**
     * @return HasMany<PriceList, $this>
     */
    public function prices(): HasMany
    {
        return $this->hasMany(PriceList::class);
    }

    /**
     * Agrodealers que têm o produto no catálogo.
     *
     * @return BelongsToMany<Agrodealer, $this>
     */
    public function agrodealers(): BelongsToMany
    {
        return $this->belongsToMany(Agrodealer::class, 'agrodealer_products');
    }
}
