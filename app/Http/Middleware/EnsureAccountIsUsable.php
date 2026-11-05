<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Em cada pedido de quem tem sessão aberta:
 * - uma conta desactivada entretanto é posta fora (a sessão fecha);
 * - uma conta com palavra-passe temporária só pode escolher a nova.
 */
class EnsureAccountIsUsable
{
    /**
     * Rotas que uma conta com palavra-passe temporária pode usar.
     *
     * @var list<string>
     */
    private const FIRST_PASSWORD_ROUTES = ['password.first', 'password.first.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        if (! $user->isActive()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->withErrors(['email' => 'O seu utilizador não está ativo no programa.']);
        }

        if ($user->must_change_password && ! $request->routeIs(...self::FIRST_PASSWORD_ROUTES)) {
            return redirect()->route('password.first');
        }

        return $next($request);
    }
}
