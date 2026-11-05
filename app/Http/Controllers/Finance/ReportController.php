<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\CycleService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Relatórios de fim de ciclo para as Finanças (só leitura).
 */
class ReportController extends Controller
{
    public function __invoke(Request $request, CycleService $cycles): Response
    {
        return Inertia::render('finance/reports', ['cycles' => $cycles->list($request->user())]);
    }
}
