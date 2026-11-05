<?php

namespace App\Http\Controllers\Rpc;

use App\Exceptions\DomainRuleException;
use App\Models\Agrodealer;
use App\Services\CatalogueService;
use App\Services\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POS (nível 9): consulta do voucher, catálogo, pré-visualização,
 * confirmação, recibo, vendas recentes e anulação. As permissões finas
 * (operador, agrodealer, POS) são verificadas no PosService.
 */
class PosRpcController extends RpcController
{
    public function __construct(private PosService $pos) {}

    public function lookupVoucherForPos(Request $request): JsonResponse
    {
        return response()->json($this->pos->lookup($this->user($request), $request->input('voucher_code')));
    }

    public function getAgrodealerCatalogue(Request $request, CatalogueService $catalogues): JsonResponse
    {
        $agrodealer = Agrodealer::query()->find($request->input('agrodealer_id')) ?? throw new DomainRuleException('INVALID_AGRODEALER');
        $this->requireRole($this->user($request)->can('viewCatalogue', $agrodealer), 'CATALOGUE_NOT_ALLOWED');

        return response()->json($catalogues->catalogueFor($agrodealer->load('district')));
    }

    public function previewVoucherRedemption(Request $request): JsonResponse
    {
        return response()->json($this->pos->preview(
            $this->user($request), $request->input('voucher_code'), $request->input('agrodealer_id'), $request->input('pos_id'),
            $request->input('operator_id'), $this->items($request), $request->input('discount_type'), $request->input('discount_value'),
        ));
    }

    public function confirmPosRedemption(Request $request): JsonResponse
    {
        return response()->json($this->pos->confirm(
            $this->user($request), $request->input('idempotency_key'), $request->input('voucher_code'), $request->input('pos_id'),
            $this->items($request), $request->input('discount_type'), $request->input('discount_value'),
            $request->input('expected_total'), $request->input('expected_voucher_amount'),
        ));
    }

    public function getTransactionReceipt(Request $request): JsonResponse
    {
        return response()->json($this->pos->receipt($this->user($request), $request->input('transaction_id')));
    }

    public function listMyRecentTransactions(Request $request): JsonResponse
    {
        return response()->json($this->pos->recent($this->user($request), $request->input('limit')));
    }

    public function voidVoucherTransaction(Request $request): JsonResponse
    {
        return response()->json($this->pos->void(
            $this->user($request), $request->input('transaction_id'), $request->input('operator_id'), $request->input('pos_id'), $request->input('reason'),
        ));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function items(Request $request): ?array
    {
        $items = $request->input('items');

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : null;
    }
}
