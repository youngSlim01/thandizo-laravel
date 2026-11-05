<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Database\Factories\AgrodealerPosFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ponto de venda (aparelho) de um agrodealer. Código POS-<agrodealer>-NN,
 * gerado ao criar; um POS nunca muda de agrodealer.
 */
#[Fillable(['agrodealer_id', 'pos_code', 'device_label', 'status'])]
class AgrodealerPos extends Model
{
    /** @use HasFactory<AgrodealerPosFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'agrodealer_pos';

    protected function casts(): array
    {
        return [
            'status' => RecordStatus::class,
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Agrodealer, $this>
     */
    public function agrodealer(): BelongsTo
    {
        return $this->belongsTo(Agrodealer::class);
    }
}
