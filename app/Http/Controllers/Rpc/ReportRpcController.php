<?php

namespace App\Http\Controllers\Rpc;

use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Transacções, relatórios e painéis (nível 11).
 */
class ReportRpcController extends RpcController
{
    public function __construct(private ReportService $reports) {}

    public function listAgrodealerOptions(Request $request): JsonResponse
    {
        return response()->json($this->reports->agrodealerOptions($this->user($request), $request->boolean('active_only')));
    }

    public function searchTransactions(Request $request): JsonResponse
    {
        $filters = $request->input('filters');

        return response()->json($this->reports->searchTransactions(
            $this->user($request), is_array($filters) ? $filters : [], $request->input('limit'), $request->input('offset'), $request->boolean('with_items'),
        ));
    }

    public function agrodealerTransactionsReport(Request $request): JsonResponse
    {
        return response()->json($this->reports->agrodealerReport($this->user($request), $request->input('agrodealer_id'), $request->input('from'), $request->input('to')));
    }

    public function listReportPrints(Request $request): JsonResponse
    {
        return response()->json($this->reports->reportPrints($this->user($request), $request->input('limit')));
    }

    public function mealDashboardMonth(Request $request): JsonResponse
    {
        return response()->json($this->reports->mealDashboard($this->user($request), $request->input('month')));
    }

    public function financeDashboardMonth(Request $request): JsonResponse
    {
        return response()->json($this->reports->financeDashboard($this->user($request), $request->input('month')));
    }
}
