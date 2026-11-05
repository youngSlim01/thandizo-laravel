<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel MEAL de um mês (o actual por omissão; o Oficial MEAL só vê a
 * sua província).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): Response
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return Inertia::render('admin/dashboard', [
            'dashboard' => fn () => $reports->mealDashboard($request->user(), $data['month'] ?? null),
        ]);
    }
}
