<?php

namespace App\Http\Requests;

use App\Enums\RecordStatus;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends StoreProductRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('product'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['required', 'string', 'max:255', Rule::unique('products', 'name')->ignore($this->route('product'))],
            'status' => ['required', Rule::enum(RecordStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...parent::messages(),
            'status.required' => 'Situação inválida.',
            'status.enum' => 'Situação inválida.',
        ];
    }
}
