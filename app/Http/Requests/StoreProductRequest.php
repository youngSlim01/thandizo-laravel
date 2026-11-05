<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('products', 'name')],
            'unit' => ['required', 'string', 'max:50'],
            'category' => ['nullable', 'string', 'max:255'],
            // Só ao criar: preço inicial por distrito (district_id => preço).
            'prices' => ['nullable', 'array'],
            'prices.*' => ['nullable', 'numeric', 'gt:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Indique o nome e a unidade do produto.',
            'unit.required' => 'Indique o nome e a unidade do produto.',
            'name.unique' => 'Já existe um produto com este nome.',
            '*.max' => 'O texto é demasiado longo.',
            'prices.*.numeric' => 'Preço inválido: tem de ser maior que zero.',
            'prices.*.gt' => 'Preço inválido: tem de ser maior que zero.',
        ];
    }
}
