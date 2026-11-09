<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class ImportPricesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Product::class);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file_name' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'max:500'],
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*.code' => ['nullable', 'string', 'max:20'],
            'rows.*.name' => ['nullable', 'string', 'max:255'],
            'rows.*.unit' => ['nullable', 'string', 'max:50'],
            'rows.*.category' => ['nullable', 'string', 'max:255'],
            'rows.*.prices' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rows.required' => 'O ficheiro não tem linhas de produtos.',
            'rows.min' => 'O ficheiro não tem linhas de produtos.',
            'rows.max' => 'O ficheiro tem demasiadas linhas (máximo 2000).',
            'rows.*.*.max' => 'Um dos textos é demasiado longo.',
        ];
    }
}
