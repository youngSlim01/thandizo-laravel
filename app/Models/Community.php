<?php

namespace App\Models;

use Database\Factories\CommunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comunidade (bairro), sempre dentro de uma localidade. Guarda também o
 * distrito, para filtrar sem subir a cadeia toda.
 */
#[Fillable(['district_id', 'locality_id', 'name', 'code'])]
class Community extends Model
{
    /** @use HasFactory<CommunityFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<District, $this>
     */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /**
     * @return BelongsTo<Locality, $this>
     */
    public function locality(): BelongsTo
    {
        return $this->belongsTo(Locality::class);
    }
}
