<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use App\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reconciliações do MEAL: o oficial fecha um período de vendas de um
 * agrodealer e envia-o ao gestor MEAL, que aprova (segue para as Finanças)
 * ou devolve. Devolver liberta as vendas para uma nova reconciliação.
 */
class SettlementController extends Controller
{
    public function __construct(private SettlementService $settlements) {}

    public function index(Request $request, ReportService $reports): Response
    {
        $data = $request->validate(['open' => ['nullable', 'uuid']]);

        return Inertia::render('admin/settlements', [
            'settlements' => $this->settlements->list($request->user()),
            'agrodealers' => $reports->agrodealerOptions($request->user(), true),
            'canApprove' => Gate::allows('meal-manager'),
            'openId' => $data['open'] ?? null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'agrodealer_id' => ['required', 'string'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date'],
        ], ['agrodealer_id.required' => 'Seleccione um comerciante.']);
        $id = $this->settlements->create($request->user(), $data['agrodealer_id'], $data['period_start'], $data['period_end']);

        return redirect()->route('admin.settlements.index', ['open' => $id])->with('success', 'Liquidação criada.');
    }

    /**
     * Vendas incluídas (também usado pelas Finanças).
     */
    public function transactions(Request $request, string $settlement): JsonResponse
    {
        return response()->json($this->settlements->transactions($request->user(), $settlement));
    }

    public function submit(Request $request, string $settlement): RedirectResponse
    {
        $next = $this->settlements->submit($request->user(), $settlement);

        return back()->with('success', $next === 'PENDING_MANAGER' ? 'Enviada ao gestor MEAL para aprovação.' : 'Enviada para Finance.');
    }

    public function approve(Request $request, string $settlement): RedirectResponse
    {
        $this->settlements->managerApprove($request->user(), $settlement);

        return back()->with('success', 'Aprovada e enviada para Finance.');
    }

    public function return(Request $request, string $settlement): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Indique o motivo da devolução.']);
        $this->settlements->managerReturn($request->user(), $settlement, $data['reason']);

        return back()->with('success', 'Devolvida. As vendas ficam livres para uma nova reconciliação.');
    }
}
