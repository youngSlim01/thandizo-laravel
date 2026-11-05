<?php

namespace App\Http\Requests;

use App\Enums\RecordStatus;
use Illuminate\Validation\Rule;

class UpdateAgrodealerRequest extends StoreAgrodealerRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('agrodealer'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
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
