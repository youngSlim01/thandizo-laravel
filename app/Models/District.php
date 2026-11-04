<?php

namespace App\Models;

use Database\Factories\DistrictFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['province_id', 'name', 'code', 'programme_active'])]
class District extends Model
{
    /** @use HasFactory<DistrictFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'programme_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Province, $this>
     */
    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    /**
     * Distritos onde o programa actua (preços, agrodealers, vendas).
     *
     * @param  Builder<District>  $query
     */
    #[Scope]
    protected function inProgramme(Builder $query): void
    {
        $query->where('programme_active', true);
    }
}
