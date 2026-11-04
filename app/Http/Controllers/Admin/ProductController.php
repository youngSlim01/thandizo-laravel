<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProductRequest;
use App\Http\Requests\UpdateProductRequest;
use App\Models\Product;
use App\Services\ProductService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request, ProductService $products): Response
    {
        Gate::authorize('viewAny', Product::class);

        return Inertia::render('admin/products', [
            'products' => $products->list()->map(fn (Product $product): array => [
                'id' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'unit' => $product->unit,
                'category' => $product->category,
                'status' => $product->status->value,
            ]),
            'canManage' => $request->user()->can('create', Product::class),
        ]);
    }

    public function store(StoreProductRequest $request, ProductService $products): RedirectResponse
    {
        $product = $products->create($request->user(), $request->validated());

        return to_route('admin.products.index')
            ->with('success', "Produto {$product->name} ({$product->code}) criado. Ainda não tem preço: não se vende até ter preço no distrito.");
    }

    public function update(UpdateProductRequest $request, Product $product, ProductService $products): RedirectResponse
    {
        $products->update($request->user(), $product, $request->validated());

        return to_route('admin.products.index')
            ->with('success', "Produto {$product->name} atualizado.");
    }
}
