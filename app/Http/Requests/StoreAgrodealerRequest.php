<?php

namespace App\Http\Requests;

use App\Models\Agrodealer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgrodealerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Agrodealer::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'district_id' => ['required', 'uuid', Rule::exists('districts', 'id')->where('programme_active', true)],
            'address' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Indique o nome do comerciante.',
            'district_id.required' => 'Escolha um distrito do programa.',
            'district_id.uuid' => 'Escolha um distrito do programa.',
            'district_id.exists' => 'Escolha um distrito do programa.',
            '*.max' => 'O texto é demasiado longo.',
        ];
    }
}
