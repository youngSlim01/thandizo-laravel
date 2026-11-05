<?php

namespace App\Http\Requests;

use App\Enums\RecordStatus;
use App\Enums\UserRole;
use App\Models\Agrodealer;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            ...$this->accessRules(),
        ];
    }

    /**
     * Regras de nome, papel, agrodealer e província (antigo admin_set_app_user).
     *
     * @return array<string, array<int, mixed>>
     */
    protected function accessRules(): array
    {
        $role = UserRole::tryFrom((string) $this->input('role'));

        return [
            'full_name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'agrodealer_id' => $role?->isSeller()
                ? ['required', 'uuid', Rule::exists('agrodealers', 'id')]
                : ['prohibited'],
            'province_id' => $role?->needsProvince()
                ? ['required', 'uuid', Rule::exists('provinces', 'id')]
                : ['prohibited'],
        ];
    }

    /**
     * Uma conta activa de agrodealer tem de ficar num agrodealer activo.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $role = UserRole::tryFrom((string) $this->input('role'));
                $active = $this->input('status', RecordStatus::Active->value) === RecordStatus::Active->value;
                if (! $role?->isSeller() || ! $active || $validator->errors()->has('agrodealer_id')) {
                    return;
                }
                $agrodealerActive = Agrodealer::query()
                    ->whereKey($this->input('agrodealer_id'))
                    ->where('status', RecordStatus::Active)
                    ->exists();
                if (! $agrodealerActive) {
                    $validator->errors()->add('agrodealer_id', 'O agrodealer escolhido não está ativo.');
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email inválido.',
            'email.email' => 'Email inválido.',
            'email.unique' => 'Já existe uma conta com este email.',
            'password.required' => 'A palavra-passe tem de ter pelo menos 8 caracteres.',
            'password.min' => 'A palavra-passe tem de ter pelo menos 8 caracteres.',
            'full_name.required' => 'Indique o nome completo.',
            'role.required' => 'Escolha o papel e o estado.',
            'role.enum' => 'Escolha o papel e o estado.',
            'agrodealer_id.required' => 'Este papel precisa de um agrodealer.',
            'agrodealer_id.uuid' => 'Este papel precisa de um agrodealer.',
            'agrodealer_id.exists' => 'Agrodealer não encontrado.',
            'agrodealer_id.prohibited' => 'Papéis centrais (MEAL, finanças, administrador) não podem ficar ligados a um agrodealer.',
            'province_id.required' => 'Um oficial MEAL tem de ter uma província.',
            'province_id.uuid' => 'Um oficial MEAL tem de ter uma província.',
            'province_id.exists' => 'Província não encontrada.',
            'province_id.prohibited' => 'Só o oficial MEAL tem província.',
            '*.max' => 'O texto é demasiado longo.',
        ];
    }
}
