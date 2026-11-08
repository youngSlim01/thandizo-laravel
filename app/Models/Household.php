<?php

namespace App\Models;

use App\Enums\RecordStatus;
use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agregado familiar (beneficiário), identificado pelo CODIGO AF da lista MEAL
 * (1234AF000001), que nunca muda. Num escalão que não exige CODIGO AF, o
 * agregado sem código recebe um gerado pelo sistema (TZ-MUT-000001).
 */
#[Fillable([
    'household_code', 'household_code_generated', 'representative_name', 'representative_first_name', 'representative_last_name',
    'representative_sex', 'representative_birth_date', 'representative_relationship',
    'province_id', 'district_id', 'administrative_post_id', 'locality_id', 'community_id', 'voucher_tier_id',
    'status', 'status_reason', 'status_changed_at', 'status_changed_by', 'import_batch_id',
])]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'representative_birth_date' => 'date:Y-m-d',
            'status' => RecordStatus::class,
            'status_changed_at' => 'datetime',
            'household_code_generated' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Community, $this>
     */
    public function community(): BelongsTo
    {
        return $this->belongsTo(Community::class);
    }

    /**
     * Escalão do voucher: o valor que o agregado recebe.
     *
     * @return BelongsTo<VoucherTier, $this>
     */
    public function voucherTier(): BelongsTo
    {
        return $this->belongsTo(VoucherTier::class);
    }

    /**
     * @return BelongsTo<HouseholdImportBatch, $this>
     */
    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(HouseholdImportBatch::class, 'import_batch_id');
    }
}
