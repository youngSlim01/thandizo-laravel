<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\PasswordService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Primeiro acesso (ou depois de uma reposição): a palavra-passe recebida é
 * temporária e a pessoa tem de escolher outra antes de continuar.
 */
class FirstPasswordController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if (! $request->user()->must_change_password) {
            return redirect('/');
        }

        return Inertia::render('auth/first-password');
    }

    public function update(Request $request, PasswordService $passwords): RedirectResponse
    {
        $data = $request->validate(PasswordRules::newPassword(), PasswordRules::messages());
        $user = $request->user();

        if ($passwords->isCurrentPassword($user, $data['password'])) {
            return back()->withErrors(['password' => PasswordRules::SAME_PASSWORD]);
        }

        $passwords->change($user, $data['password'], 'first_login');

        return redirect('/')->with('success', 'Palavra-passe definida. Bem-vindo(a).');
    }
}
