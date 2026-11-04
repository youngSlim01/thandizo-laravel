<?php

namespace App\Models;

use Database\Factories\CommunityFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Comunidade (bairro). Tem sempre o distrito; as criadas desde o registo de
 * agregados (20261007195344) têm também a localidade. As antigas sem
 * localidade continuam válidas.
 */
#[Fillable(['district_id', 'locality_id', 'name', 'code'])]
#[Hidden(['legacy_district_id'])]
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
