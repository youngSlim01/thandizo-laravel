<?php

namespace App\Http\Controllers;

use App\Services\NavigationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    /**
     * Back office: abre a primeira secção do menu da pessoa (como o antigo
     * /admin). Quem ainda não tem secções migradas (POS, Finanças) vê a
     * página inicial provisória.
     */
    public function __invoke(Request $request, NavigationService $navigation): Response|RedirectResponse
    {
        $sections = $navigation->sectionsFor($request->user());
        if ($sections !== []) {
            $request->session()->reflash();

            return redirect($sections[0]['href']);
        }

        return Inertia::render('home');
    }
}
