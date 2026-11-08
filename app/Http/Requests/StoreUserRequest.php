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
     * O nome de utilizador guarda-se em minúsculas, sem espaços nas pontas;
     * vazio fica sem nome de utilizador.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('username')) {
            $username = mb_strtolower(trim((string) $this->input('username')));
            $this->merge(['username' => $username === '' ? null : $username]);
        }
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
            // Nome de utilizador opcional para entrar sem o email; nunca tem "@".
            'username' => ['nullable', 'string', 'min:3', 'max:30', 'regex:/^[a-z0-9][a-z0-9._-]*$/',
                Rule::unique('users', 'username')->ignore($this->route('user'))],
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
                    $validator->errors()->add('agrodealer_id', 'O comerciante escolhido não está ativo.');
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
            'username.min' => 'O nome de utilizador tem de ter entre 3 e 30 caracteres.',
            'username.max' => 'O nome de utilizador tem de ter entre 3 e 30 caracteres.',
            'username.regex' => 'O nome de utilizador só pode ter letras minúsculas sem acentos, números, ponto, hífen e sublinhado, e começa por letra ou número.',
            'username.unique' => 'Já existe uma conta com este nome de utilizador.',
            'password.required' => 'A palavra-passe tem de ter pelo menos 8 caracteres.',
            'password.min' => 'A palavra-passe tem de ter pelo menos 8 caracteres.',
            'full_name.required' => 'Indique o nome completo.',
            'role.required' => 'Escolha o papel e o estado.',
            'role.enum' => 'Escolha o papel e o estado.',
            'agrodealer_id.required' => 'Este papel precisa de um comerciante.',
            'agrodealer_id.uuid' => 'Este papel precisa de um comerciante.',
            'agrodealer_id.exists' => 'Comerciante não encontrado.',
            'agrodealer_id.prohibited' => 'Papéis centrais (MEAL, finanças, administrador) não podem ficar ligados a um comerciante.',
            'province_id.required' => 'Um oficial MEAL tem de ter uma província.',
            'province_id.uuid' => 'Um oficial MEAL tem de ter uma província.',
            'province_id.exists' => 'Província não encontrada.',
            'province_id.prohibited' => 'Só o oficial MEAL tem província.',
            '*.max' => 'O texto é demasiado longo.',
        ];
    }
}
