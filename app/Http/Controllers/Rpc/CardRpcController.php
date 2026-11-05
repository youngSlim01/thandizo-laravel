<?php

namespace App\Http\Controllers\Rpc;

use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cartões (nível 7). Ler, gerar, imprimir e distribuir: back office MEAL
 * (o Oficial MEAL na sua província). Cancelar lotes e substituir cartões:
 * gestor MEAL e administrador.
 */
class CardRpcController extends RpcController
{
    public function __construct(private CardService $cards) {}

    private function requireCardRole(Request $request): void
    {
        $this->requireRole($this->user($request)->can('meal-back-office'), 'CARD_BATCH_NOT_ALLOWED');
    }

    public function listCardCommunities(Request $request): JsonResponse
    {
        $this->requireCardRole($request);

        return response()->json($this->cards->communities($this->user($request)));
    }

    public function generateCardBatch(Request $request): JsonResponse
    {
        $this->requireCardRole($request);

        return response()->json($this->cards->generateBatch($this->user($request), $request->input('community_id')));
    }

    public function listCardBatches(Request $request): JsonResponse
    {
        $this->requireCardRole($request);

        return response()->json($this->cards->batches($this->user($request)));
    }

    public function getCardBatchCards(Request $request): JsonResponse
    {
        $this->requireCardRole($request);

        return response()->json($this->cards->batchCards($this->user($request), $request->input('batch_id')));
    }

    public function markCardBatchPrinted(Request $request): JsonResponse
    {
        $this->requireCardRole($request);

        return response()->json($this->cards->markPrinted($this->user($request), $request->input('batch_id')));
    }

    public function distributeCards(Request $request): JsonResponse
    {
        $this->requireCardRole($request);
        $substitutes = $request->input('substitutes');

        return response()->json($this->cards->distribute(
            $this->user($request), $request->input('batch_id'), $this->list($request, 'voucher_ids'), is_array($substitutes) ? $substitutes : [],
        ));
    }

    public function cancelCardBatch(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->requireRole($user->isActive() && $user->role->canManageHouseholds(), 'CARD_BATCH_CANCEL_NOT_ALLOWED');

        return response()->json($this->cards->cancelBatch($user, $request->input('batch_id'), $request->input('reason')));
    }

    public function findHouseholdCards(Request $request): JsonResponse
    {
        $this->requireCardRole($request);

        return response()->json($this->cards->findHouseholdCards($this->user($request), $request->input('query')));
    }

    public function replaceVoucher(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->requireRole($user->isActive() && $user->role->canManageHouseholds(), 'VOUCHER_REPLACE_NOT_ALLOWED');

        return response()->json($this->cards->replace($user, $request->input('voucher_code'), $request->input('reason')));
    }
}
