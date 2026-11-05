<?php

namespace App\Http\Controllers\Rpc;

use App\Services\SettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reconciliações (nível 10). As permissões (MEAL, gestor, Finanças) são
 * verificadas no SettlementService com os códigos antigos.
 */
class SettlementRpcController extends RpcController
{
    public function __construct(private SettlementService $settlements) {}

    public function listSettlements(Request $request): JsonResponse
    {
        return response()->json($this->settlements->list($this->user($request)));
    }

    public function financeListSettlements(Request $request): JsonResponse
    {
        return response()->json($this->settlements->financeList($this->user($request)));
    }

    public function getSettlementTransactions(Request $request): JsonResponse
    {
        return response()->json($this->settlements->transactions($this->user($request), $request->input('settlement_id')));
    }

    public function createAgrodealerSettlement(Request $request): JsonResponse
    {
        return response()->json($this->settlements->create(
            $this->user($request), $request->input('agrodealer_id'), $request->input('period_start'), $request->input('period_end'),
        ));
    }

    public function submitAgrodealerSettlement(Request $request): JsonResponse
    {
        return response()->json($this->settlements->submit($this->user($request), $request->input('settlement_id')));
    }

    public function managerApproveSettlement(Request $request): JsonResponse
    {
        $this->settlements->managerApprove($this->user($request), $request->input('settlement_id'));

        return response()->json(null);
    }

    public function managerReturnSettlement(Request $request): JsonResponse
    {
        $this->settlements->managerReturn($this->user($request), $request->input('settlement_id'), $request->input('reason'));

        return response()->json(null);
    }

    public function financeValidateSettlement(Request $request): JsonResponse
    {
        $this->settlements->financeValidate($this->user($request), $request->input('settlement_id'));

        return response()->json(null);
    }

    public function financeReturnSettlement(Request $request): JsonResponse
    {
        $this->settlements->financeReturn($this->user($request), $request->input('settlement_id'), $request->input('reason'));

        return response()->json(null);
    }
}
