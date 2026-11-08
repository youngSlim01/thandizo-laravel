<?php

namespace App\Http\Controllers;

use App\Models\CardLogo;
use Illuminate\Http\Response;

/**
 * Imagem de um logótipo do cliente, para o cartão e o ecrã de configuração.
 * O endereço muda com o logótipo (CardLogo::url), por isso pode ficar em cache.
 */
class BrandLogoController extends Controller
{
    public function __invoke(CardLogo $logo): Response
    {
        $content = base64_decode(CardLogo::query()->whereKey($logo->id)->value('content'));

        return response($content, 200, [
            'Content-Type' => $logo->mime_type,
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
