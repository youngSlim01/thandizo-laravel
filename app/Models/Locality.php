<?php

namespace App\Models;

use Database\Factories\LocalityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['administrative_post_id', 'name', 'code'])]
class Locality extends Model
{
    /** @use HasFactory<LocalityFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<AdministrativePost, $this>
     */
    public function administrativePost(): BelongsTo
    {
        return $this->belongsTo(AdministrativePost::class);
    }

    /**
     * @return HasMany<Community, $this>
     */
    public function communities(): HasMany
    {
        return $this->hasMany(Community::class);
    }
}
