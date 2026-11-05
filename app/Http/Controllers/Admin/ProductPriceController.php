<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\Product;
use App\Services\PriceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ProductPriceController extends Controller
{
    /**
     * Define ou retira (unit_price vazio) o preço de um produto num distrito.
     */
    public function update(Request $request, Product $product, District $district, PriceService $prices): RedirectResponse
    {
        Gate::authorize('update', $product);
        $data = $request->validate(
            ['unit_price' => ['nullable', 'numeric']],
            ['unit_price.numeric' => 'O preço tem de ser maior que zero.'],
        );

        try {
            $result = $prices->setPrice($request->user(), $product, $district, $data['unit_price'] ?? null);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['unit_price' => $exception->getMessage()]);
        }

        $message = $result['unit_price'] === null
            ? "{$product->name} deixou de ter preço em {$district->name}: não se vende lá."
            : "{$product->name} em {$district->name}: ".self::mt($result['unit_price'])." / {$product->unit}, já em vigor.";

        return back()->with('success', $message);
    }

    /**
     * Como formatMT no frontend (pt-MZ): 1500 → "1500 MT", 1500.5 →
     * "1500,50 MT", 15000 → "15 000 MT" (milhares separados só a partir de 10 000).
     */
    public static function mt(string $amount): string
    {
        $value = (float) $amount;
        $decimals = fmod($value, 1.0) === 0.0 ? 0 : 2;
        $thousands = abs($value) >= 10000 ? ' ' : '';

        return number_format($value, $decimals, ',', $thousands).' MT';
    }
}
