<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Uma importação da lista MEAL (ficheiro, quem, quantos agregados).
 */
#[Fillable(['file_name', 'imported_by', 'row_count', 'created_households', 'existing_households'])]
class HouseholdImportBatch extends Model
{
    use HasUuids;

    public const UPDATED_AT = null;
}
