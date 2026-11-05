<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agrodealer;
use App\Models\Product;
use App\Services\CatalogueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Catálogo de um agrodealer. Responde em JSON: o editor abre dentro do
 * cartão do agrodealer e actualiza-se sem recarregar a página.
 */
class AgrodealerCatalogueController extends Controller
{
    public function show(Agrodealer $agrodealer, CatalogueService $catalogues): JsonResponse
    {
        Gate::authorize('viewCatalogue', $agrodealer);

        return response()->json($catalogues->catalogueFor($agrodealer->load('district')));
    }

    public function update(Request $request, Agrodealer $agrodealer, Product $product, CatalogueService $catalogues): JsonResponse
    {
        Gate::authorize('manageCatalogue', $agrodealer);
        $inCatalogue = $request->validate(['in_catalogue' => ['required', 'boolean']])['in_catalogue'];

        try {
            $changed = $catalogues->setProduct($request->user(), $agrodealer, $product, (bool) $inCatalogue);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['product' => $exception->getMessage()]);
        }

        return response()->json(['changed' => $changed]);
    }
}
