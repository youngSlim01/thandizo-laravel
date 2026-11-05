<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Services\SettlementService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finanças: conferem as reconciliações aprovadas pelo gestor MEAL e
 * validam-nas ou devolvem-nas ao MEAL.
 */
class SettlementController extends Controller
{
    public function __construct(private SettlementService $settlements) {}

    public function index(Request $request): Response
    {
        $data = $request->validate(['settlement' => ['nullable', 'uuid']]);

        return Inertia::render('finance/settlements', [
            'settlements' => $this->settlements->list($request->user()),
            'openId' => $data['settlement'] ?? null,
        ]);
    }

    public function validateSettlement(Request $request, string $settlement): RedirectResponse
    {
        $this->settlements->financeValidate($request->user(), $settlement);

        return back()->with('success', 'Reconciliação validada.');
    }

    public function return(Request $request, string $settlement): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], ['reason.required' => 'Indique o motivo da devolução.']);
        $this->settlements->financeReturn($request->user(), $settlement, $data['reason']);

        return back()->with('success', 'Reconciliação devolvida ao MEAL.');
    }
}
