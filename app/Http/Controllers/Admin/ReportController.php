<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Relatório de transações por agrodealer (PDF). Cada impressão fica
 * registada: o gestor MEAL vê todas, o oficial as da sua província.
 */
class ReportController extends Controller
{
    public function __construct(private ReportService $reports) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/reports', [
            'agrodealers' => $this->reports->agrodealerOptions($request->user(), false),
            'prints' => $this->prints($request),
            'seesAllPrints' => Gate::allows('meal-manager'),
        ]);
    }

    /**
     * Dados do relatório para o PDF; regista a impressão.
     */
    public function agrodealer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agrodealer_id' => ['required', 'string'],
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        return response()->json($this->reports->agrodealerReport($request->user(), $data['agrodealer_id'], $data['from'], $data['to']));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function prints(Request $request): array
    {
        return array_map(fn (array $print): array => [
            ...$print,
            'printed_by_role' => UserRole::tryFrom((string) $print['printed_by_role'])?->label(),
        ], $this->reports->reportPrints($request->user(), 200));
    }
}
