<?php

namespace App\Http\Controllers\Auth;

/**
 * Regras e mensagens comuns para quem escolhe a sua palavra-passe.
 */
final class PasswordRules
{
    public const SAME_PASSWORD = 'A nova palavra-passe tem de ser diferente da atual.';

    /**
     * @return array<string, array<int, string>>
     */
    public static function newPassword(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'password.required' => 'A palavra-passe tem de ter pelo menos 8 caracteres.',
            'password.min' => 'A palavra-passe tem de ter pelo menos 8 caracteres.',
            'password.max' => 'O texto é demasiado longo.',
            'password.confirmed' => 'As duas palavras-passe não são iguais.',
            'current_password.required' => 'Indique a palavra-passe atual.',
            'current_password.current_password' => 'A palavra-passe atual não está correta.',
        ];
    }
}
