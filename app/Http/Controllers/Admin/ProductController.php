<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\District;
use App\Models\Product;
use App\Services\CyclePlanService;
use App\Services\PriceImportService;
use App\Services\PriceService;
use App\Services\ProductService;
use App\Services\ProgrammeDistrictService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    /**
     * Produtos e preços de um ciclo (por omissão o aberto; os fechados só
     * para consulta).
     */
    public function index(Request $request, ProductService $products, PriceService $prices, ProgrammeDistrictService $districts, CyclePlanService $plans): Response
    {
        Gate::authorize('viewAny', Product::class);
        $cycles = DB::table('programme_cycles')->orderByRaw("status = 'OPEN' desc")->orderByDesc('opened_at')->get(['id', 'code', 'name', 'status']);
        $cycle = $cycles->firstWhere('id', $request->query('cycle')) ?? $cycles->first();
        $isOpen = $cycle?->status === 'OPEN';
        $currentPrices = $cycle === null ? [] : $prices->currentProgrammePrices($cycle->id);
        $previous = $isOpen ? $prices->previousCycle() : null;

        return Inertia::render('admin/products', [
            'cycles' => $cycles,
            'cycle' => $cycle,
            'previousCycle' => $previous,
            'districts' => $districts->list()->map(fn (District $district): array => [
                'id' => $district->id,
                'name' => $district->name,
                'province' => $district->province->name,
                'fair' => $isOpen ? ($plans->fairFor($district->id)['status'] ?? 'none') : 'none',
            ]),
            'products' => $products->list()->loadCount('agrodealers')->map(fn (Product $product): array => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'unit' => $product->unit,
                'category' => $product->category,
                'status' => $product->status->value,
                'prices' => $currentPrices[$product->id] ?? (object) [],
                'agrodealers' => $product->agrodealers_count,
            ]),
            'canManage' => $request->user()->can('create', Product::class),
        ]);
    }

    /**
     * Produto e preços iniciais de uma só vez: gravam-se os dois ou nenhum
     * (como no sistema antigo, é uma importação de uma linha).
     */
    public function store(StoreProductRequest $request, PriceImportService $import): RedirectResponse
    {
        $prices = array_filter($request->validated('prices') ?? [], fn ($price) => $price !== null);
        $result = $import->import($request->user(), [[
            'code' => '',
            'name' => $request->validated('name'),
            'unit' => $request->validated('unit'),
            'category' => $request->validated('category'),
            'prices' => $prices,
        ]], null);

        $name = trim($request->validated('name'));
        $count = count($prices);
        $message = "Produto {$name} ({$result['last_code']}) criado"
            .($count ? " com preço em {$count} distrito(s)." : ' sem preços: não se vende até ter preço no distrito.');

        return to_route('admin.products.index')->with('success', $message);
    }

    public function update(UpdateProductRequest $request, Product $product, ProductService $products): RedirectResponse
    {
        $products->update($request->user(), $product, $request->validated());

        return to_route('admin.products.index')
            ->with('success', "Produto {$product->name} atualizado.");
    }
}
