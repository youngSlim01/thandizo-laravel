<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ResetUserPasswordRequest extends FormRequest
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
            'password' => ['required', 'string', 'min:8', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'A palavra-passe tem de ter pelo menos 8 caracteres.',
            'password.min' => 'A palavra-passe tem de ter pelo menos 8 caracteres.',
            'password.max' => 'O texto é demasiado longo.',
        ];
    }
}
