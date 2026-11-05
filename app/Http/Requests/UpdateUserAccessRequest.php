<?php

namespace App\Http\Requests;

use App\Enums\RecordStatus;
use Illuminate\Validation\Rule;

class UpdateUserAccessRequest extends StoreUserRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('user'));
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...$this->accessRules(),
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
            'status.required' => 'Escolha o papel e o estado.',
            'status.enum' => 'Escolha o papel e o estado.',
        ];
    }
}
