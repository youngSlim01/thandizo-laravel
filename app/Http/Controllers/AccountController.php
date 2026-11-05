<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Auth\PasswordRules;
use App\Services\PasswordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * O meu perfil: dados da conta e alteração da palavra-passe.
 */
class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user()->load(['agrodealer:id,name', 'province:id,name']);

        return Inertia::render('account', [
            'account' => [
                'full_name' => $user->full_name,
                'email' => $user->email,
                'role_label' => $user->role->label(),
                'agrodealer' => $user->agrodealer?->name,
                'province' => $user->province?->name,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Alterar a própria palavra-passe pede a actual: quem encontrar a sessão
     * aberta noutro aparelho não consegue ficar com a conta.
     */
    public function updatePassword(Request $request, PasswordService $passwords): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password'],
            ...PasswordRules::newPassword(),
        ], PasswordRules::messages());

        if ($passwords->isCurrentPassword($request->user(), $data['password'])) {
            return back()->withErrors(['password' => PasswordRules::SAME_PASSWORD]);
        }

        $passwords->change($request->user(), $data['password'], 'account');

        return back()->with('success', 'A sua palavra-passe foi alterada.');
    }
}
