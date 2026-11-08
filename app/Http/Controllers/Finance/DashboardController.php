<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\FinanceDashboardService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Painel das Finanças: o estado actual das reconciliações e do que se deve
 * a cada comerciante (FinanceDashboardService).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, FinanceDashboardService $dashboard): Response
    {
        return Inertia::render('finance/dashboard', [
            'dashboard' => $dashboard->current($request->user()),
        ]);
    }
}
