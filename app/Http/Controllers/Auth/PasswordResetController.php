<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PasswordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Recuperação da palavra-passe por email ("Esqueceu a palavra-passe?").
 */
class PasswordResetController extends Controller
{
    /**
     * Envia o link. A resposta é sempre a mesma, exista ou não a conta, para
     * não revelar que emails estão registados.
     */
    public function sendLink(Request $request): RedirectResponse
    {
        $request->validate(
            ['email' => ['required', 'email']],
            ['email.required' => 'Introduza o seu email para recuperar a palavra-passe.', 'email.email' => 'Email inválido.'],
        );

        Password::sendResetLink($request->only('email'));

        return back()->with('success', 'Se o email estiver registado, enviámos um link de recuperação.');
    }

    public function edit(Request $request, string $token): Response
    {
        return Inertia::render('auth/reset-password', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request, PasswordService $passwords): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            ...PasswordRules::newPassword(),
        ], PasswordRules::messages());

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            fn (User $user, string $password) => $passwords->change($user, $password, 'email_reset'),
        );

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'O link de recuperação é inválido ou já expirou. Peça outro.']);
        }

        return redirect()->route('login')->with('success', 'Palavra-passe alterada. Entre com a nova.');
    }
}
