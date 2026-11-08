<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Controllers\Pos\PosController;
use App\Services\NavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * POS: início do operador. Finanças: a sua área. Back office: a primeira
     * secção do menu (como o antigo /admin). Sem nenhuma área (por exemplo um
     * operador sem agrodealer) vê a página "sem área".
     */
    public function __invoke(Request $request, NavigationService $navigation): Response|RedirectResponse
    {
        $user = $request->user();
        // O administrador do sistema entra no painel global.\n        if ($user->role === UserRole::SystemAdmin) {\n            return redirect('/system/dashboard');\n        }\n\n        // Quem vende abre o início do POS; as Finanças a sua área.
        if ($user->can('pos')) {
            return app(PosController::class)->home($request);
        }
        if ($user->role === UserRole::FinanceOfficer && $user->can('finance')) {
            $request->session()->reflash();

            return redirect('/finance');
        }
        $sections = $navigation->sectionsFor($user);
        if ($sections !== []) {
            $request->session()->reflash();

            return redirect($sections[0]['href']);
        }

        return Inertia::render('home');
    }
}
