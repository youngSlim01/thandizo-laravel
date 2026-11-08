<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Cliente desta instalação (organização, programa e a sua frase), mostrado
 * junto do nome THANDIZO, e as regras da instalação (prazo de validação das
 * reconciliações). Há no máximo uma linha; ver ClientProfileService.
 */
#[Fillable(['name', 'programme', 'tagline', 'settlement_deadline_days', 'updated_by'])]
class ClientProfile extends Model
{
    use HasUuids;
}
