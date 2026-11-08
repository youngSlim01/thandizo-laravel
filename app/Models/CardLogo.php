<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Logótipo do cliente impresso nos cartões. A posição (1 a 3) dá a ordem,
 * da esquerda para a direita; o conteúdo está em base64. Ver CardLogoService.
 */
#[Fillable(['position', 'original_name', 'mime_type', 'content', 'uploaded_by'])]
#[Hidden(['content'])]
class CardLogo extends Model
{
    use HasUuids;

    /**
     * Endereço da imagem; muda quando o logótipo muda, para o browser não
     * usar uma versão antiga em cache.
     */
    public function url(): string
    {
        return route('brand.logo', ['logo' => $this->id, 'v' => $this->updated_at?->timestamp]);
    }
}
