<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CycleDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel MEAL de um ciclo (o aberto por omissão; o Oficial MEAL só vê a
 * sua província).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, CycleDashboardService $dashboard): Response
    {
        $data = $request->validate(['cycle' => ['nullable', 'uuid']]);

        return Inertia::render('admin/dashboard', [
            'dashboard' => fn () => $dashboard->forViewer($request->user(), $data['cycle'] ?? null),
        ]);
    }
}
