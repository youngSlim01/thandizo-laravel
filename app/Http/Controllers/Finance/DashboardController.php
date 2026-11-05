<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel das Finanças de um mês: tendência dos últimos meses e as
 * reconciliações por agrodealer.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): Response
    {
        $data = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);

        return Inertia::render('finance/dashboard', [
            'dashboard' => fn () => $reports->financeDashboard($request->user(), $data['month'] ?? null),
        ]);
    }
}
