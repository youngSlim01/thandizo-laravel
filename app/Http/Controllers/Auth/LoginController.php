<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('auth/login');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
        ], ['login.required' => 'Indique o nome de utilizador ou o e-mail.']);

        // Com "@" é o email; sem "@", o nome de utilizador (que nunca o tem).
        $login = Str::lower(trim($data['login']));
        $field = str_contains($login, '@') ? 'email' : 'username';

        // No máximo 5 tentativas por minuto para o mesmo utilizador e IP.
        $throttleKey = $login.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            throw ValidationException::withMessages([
                'login' => "Demasiadas tentativas. Tente novamente dentro de {$seconds} segundos.",
            ]);
        }

        if (! Auth::attempt([$field => $login, 'password' => $data['password']], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey);
            throw ValidationException::withMessages([
                'login' => 'Utilizador ou palavra-passe incorretos.',
            ]);
        }

        // A palavra-passe está certa, mas um utilizador desativado não entra.
        if (! $request->user()->isActive()) {
            Auth::guard('web')->logout();
            throw ValidationException::withMessages([
                'login' => 'O seu utilizador não está ativo no programa.',
            ]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();
        $request->user()->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended('/');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }
}
