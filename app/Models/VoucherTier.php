<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Escalão do voucher: o valor que cada agregado do escalão recebe na
 * activação do cartão e em cada recarga. Ver VoucherTierService.
 */
#[Fillable(['name', 'amount', 'description', 'is_default', 'status', 'created_by'])]
class VoucherTier extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'is_default' => 'boolean',
            'status' => RecordStatus::class,
        ];
    }

    /**
     * @return HasMany<Household, $this>
     */
    public function households(): HasMany
    {
        return $this->hasMany(Household::class);
    }
}
