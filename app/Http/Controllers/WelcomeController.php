<?php

namespace App\Http\Controllers;

use App\Services\NavigationService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;

class WelcomeController extends Controller
{
    public function __invoke(Request $request, NavigationService $navigation): Response|RedirectResponse
    {
        if ($request->user()) {
            return app(HomeController::class)($request, $navigation);
        }

        return Inertia::render('landing');
    }
}
