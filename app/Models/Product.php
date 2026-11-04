<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
}
