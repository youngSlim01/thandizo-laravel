<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImportPricesRequest;
use App\Services\PriceImportService;
use Illuminate\Http\RedirectResponse;

class PriceImportController extends Controller
{
    public function store(ImportPricesRequest $request, PriceImportService $import): RedirectResponse
    {
        $fileName = $request->validated('file_name');
        $result = $import->import($request->user(), $request->validated('rows'), $fileName, $request->validated('reason'));

        $message = "{$fileName}: {$result['prices_set']} preço(s) atualizados"
            .($result['products_created'] ? ", {$result['products_created']} produto(s) novo(s)" : '')
            .($result['prices_unchanged'] ? ", {$result['prices_unchanged']} sem alteração" : '')
            .'. Já em vigor.';

        return to_route('admin.products.index')->with('success', $message);
    }
}
