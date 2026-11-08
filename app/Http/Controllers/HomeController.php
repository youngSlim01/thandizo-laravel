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
    public function __invoke(Request $request, NavigationService $navigation): Response|RedirectResponse
    {
        $user = $request->user();
        if ($user->role === UserRole::SystemAdmin) {
            return redirect('/system/dashboard');
        }
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
