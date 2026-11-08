<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\CardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cartões e distribuição. Ver, gerar, imprimir e distribuir: back office
 * MEAL (o Oficial MEAL só na sua província). Cancelar lotes e substituir
 * cartões: gestor MEAL e administrador.
 */
class CardController extends Controller
{
    public function __construct(private CardService $cards) {}

    public function index(Request $request): Response
    {
        return Inertia::render('admin/cards', [
            'communities' => $this->cards->communities($request->user()),
            'batches' => $this->cards->batches($request->user()),
            'canManage' => Gate::allows('meal-manager'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['community_id' => ['required', 'string']]);
        $batch = $this->cards->generateBatch($request->user(), $data['community_id']);

        return back()->with('success', "{$batch['batch_code']} gerado: {$batch['card_count']} cartões. Descarregue o PDF para imprimir.");
    }

    /**
     * Cartões do lote, para os ver, imprimir em PDF ou distribuir.
     */
    public function cards(Request $request, string $batch): JsonResponse
    {
        return response()->json($this->cards->batchCards($request->user(), $batch));
    }

    public function printed(Request $request, string $batch): RedirectResponse
    {
        $this->cards->markPrinted($request->user(), $batch);

        return back()->with('success', 'Lote marcado como impresso.');
    }

    public function distribute(Request $request, string $batch): RedirectResponse
    {
        $data = $request->validate([
            'voucher_ids' => ['required', 'array'],
            'voucher_ids.*' => ['string'],
            'substitutes' => ['nullable', 'array'],
            'substitutes.*.voucher_id' => ['required', 'string'],
            'substitutes.*.name' => ['nullable', 'string', 'max:150'],
            'substitutes.*.relationship' => ['nullable', 'string', 'max:30'],
        ], ['voucher_ids.required' => 'Marque pelo menos um cartão.']);
        $result = $this->cards->distribute($request->user(), $batch, array_values($data['voucher_ids']), array_values($data['substitutes'] ?? []));

        $activated = $result['activated'];
        $total = number_format($result['total_amount'], 0, ',', ' ');
        $message = $activated === 1 ? "1 cartão ativado com {$total} MT." : "{$activated} cartões ativados, no total de {$total} MT.";
        $message .= $result['remaining'] > 0 ? " Faltam entregar {$result['remaining']}." : ' Todos os cartões do lote foram entregues.';

        return back()->with('success', $message);
    }

    public function cancel(Request $request, string $batch): RedirectResponse
    {
        Gate::authorize('meal-manager');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Indique o motivo do cancelamento.']);
        $result = $this->cards->cancelBatch($request->user(), $batch, $data['reason']);

        return back()->with('success', $result['status'] === 'DISTRIBUTED'
            ? "Lote fechado. {$result['cancelled_cards']} cartões não entregues foram cancelados; esses agregados podem receber cartão num lote novo."
            : 'Lote cancelado. Os agregados podem receber cartão num lote novo.');
    }

    /**
     * Cartões de um agregado, pelo código do voucher ou o CODIGO AF.
     */
    public function search(Request $request): JsonResponse
    {
        return response()->json($this->cards->findHouseholdCards($request->user(), $request->string('q')->toString()));
    }

    /**
     * Cartão perdido: cancela-o e emite um novo com o saldo que restava.
     */
    public function replace(Request $request, string $voucherCode): JsonResponse
    {
        Gate::authorize('meal-manager');
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Indique o motivo da substituição.']);

        return response()->json($this->cards->replace($request->user(), $voucherCode, $data['reason']));
    }
}
