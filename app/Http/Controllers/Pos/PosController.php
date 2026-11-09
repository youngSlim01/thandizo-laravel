<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Models\AgrodealerPos;
use App\Services\CatalogueService;
use App\Services\CyclePlanService;
use App\Services\MerchantAccountService;
use App\Services\PosService;
use App\Services\PosTerminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS do agrodealer: escolher o terminal, vender em 6 passos, ver as vendas
 * das últimas 24 horas, anular dentro de 15 minutos e reimprimir recibos.
 *
 * O browser nunca decide dinheiro: guarda o voucher, o carrinho e o
 * desconto pedido, pede a pré-visualização ao servidor e confirma com os
 * valores mostrados ao agregado (PREVIEW_OUTDATED se algo mudou).
 */
class PosController extends Controller
{
    public function __construct(private PosService $pos, private PosTerminal $terminal, private CyclePlanService $plans) {}

    public function home(Request $request): Response
    {
        $district = DB::table('agrodealers as a')->leftJoin('districts as d', 'd.id', '=', 'a.district_id')
            ->where('a.id', $request->user()->agrodealer_id)->first(['a.district_id', 'd.name']);

        return Inertia::render('pos/home', [
            'sales' => $this->pos->recent($request->user(), 50),
            // Só se vende nos dias da feira do distrito: o início avisa antes.
            'fair' => ['district' => $district?->name, ...($this->plans->fairFor($district?->district_id) ?? ['status' => 'none'])],
        ]);
    }

    public function chooseTerminal(Request $request): RedirectResponse
    {
        $data = $request->validate(['pos_id' => ['required', 'string']]);
        if (! $this->terminal->choose($request->user(), $request->session(), $data['pos_id'])) {
            throw ValidationException::withMessages(['pos_id' => 'Este POS não está ativo ou não pertence à sua loja.']);
        }

        return back();
    }

    public function forgetTerminal(Request $request): RedirectResponse
    {
        $this->terminal->forget($request->session());

        return back();
    }

    /**
     * Passos da venda que só vivem no browser (voucher, carrinho, desconto,
     * pagamento, confirmação).
     */
    public function step(string $step): Response
    {
        return Inertia::render("pos/{$step}");
    }

    /**
     * Produtos do catálogo com preço no distrito do agrodealer.
     */
    public function products(Request $request, CatalogueService $catalogues): Response
    {
        $catalogue = $catalogues->catalogueFor($request->user()->agrodealer);

        return Inertia::render('pos/products', [
            'products' => collect($catalogue['products'])
                ->filter(fn (array $p): bool => $p['in_catalogue'] && $p['unit_price'] !== null)
                ->map(fn (array $p): array => ['id' => $p['product_id'], 'code' => $p['code'], 'name' => $p['name'], 'unit' => $p['unit'], 'listPrice' => (float) $p['unit_price']])
                ->values()->all(),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $result = $this->pos->lookup($request->user(), $request->string('voucher_code')->toString());
        if (! $result['found']) {
            throw new DomainRuleException('VOUCHER_NOT_FOUND');
        }

        return response()->json($result);
    }

    public function preview(Request $request): JsonResponse
    {
        $user = $request->user();
        $sale = $this->sale($request);

        return response()->json($this->pos->preview(
            $user, $sale['voucher_code'], $user->agrodealer_id, $this->currentPos($request)?->id, $user->id,
            $sale['items'], $sale['discount_type'], $sale['discount_value'],
        ));
    }

    public function confirm(Request $request): JsonResponse
    {
        $sale = $this->sale($request);
        $expected = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:100'],
            'expected_total' => ['required', 'numeric'],
            'expected_voucher_amount' => ['required', 'numeric'],
        ]);

        return response()->json($this->pos->confirm(
            $request->user(), $expected['idempotency_key'], $sale['voucher_code'], $this->currentPos($request)?->id,
            $sale['items'], $sale['discount_type'], $sale['discount_value'], $expected['expected_total'], $expected['expected_voucher_amount'],
        ));
    }

    /**
     * O operador vê as suas vendas das últimas 24 horas; o gestor do
     * comerciante (o dono) vê as de toda a loja no ciclo.
     */
    public function sales(Request $request, MerchantAccountService $account): Response
    {
        $user = $request->user();
        if (MerchantAccountService::isOwner($user)) {
            $shop = $account->sales($user, PosService::VOID_WINDOW_MINUTES);

            return Inertia::render('pos/sales', ['sales' => $shop['sales'], 'scope' => 'shop', 'cycle' => $shop['cycle']]);
        }

        return Inertia::render('pos/sales', ['sales' => $this->pos->recent($user, 50), 'scope' => 'mine', 'cycle' => null]);
    }

    /**
     * Quanto se deve à loja em cada fase do pagamento (só o gestor do comerciante).
     */
    public function payments(Request $request, MerchantAccountService $account): Response
    {
        abort_unless(MerchantAccountService::isOwner($request->user()), 403);

        return Inertia::render('pos/payments', $account->payments($request->user()));
    }

    public function void(Request $request, string $transaction): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Indique o motivo da anulação.']);
        $user = $request->user();
        $result = $this->pos->void($user, $transaction, $user->id, $this->currentPos($request)?->id, $data['reason']);

        $refund = (float) ($result['copayment_to_refund'] ?? 0);
        $message = "Venda {$result['transaction_code']} anulada. O saldo do voucher foi reposto.";
        if ($refund > 0) {
            $message .= ' Devolva '.number_format($refund, $refund == floor($refund) ? 0 : 2, ',', ' ').' MT em dinheiro ao agregado.';
        }

        return back()->with('success', $message);
    }

    public function receipt(Request $request, string $transaction): Response
    {
        return Inertia::render('pos/receipt', ['receipt' => $this->pos->receipt($request->user(), $transaction)]);
    }

    public function catalogue(Request $request): Response
    {
        return Inertia::render('pos/catalogue', ['canManage' => $request->user()->can('manageCatalogue', $request->user()->agrodealer)]);
    }

    public function profile(): Response
    {
        return Inertia::render('pos/profile');
    }

    private function currentPos(Request $request): ?AgrodealerPos
    {
        return $this->terminal->current($request->user(), $request->session());
    }

    /**
     * @return array{voucher_code: string, items: list<array<string, mixed>>, discount_type: string|null, discount_value: mixed}
     */
    private function sale(Request $request): array
    {
        $data = $request->validate([
            'voucher_code' => ['required', 'string', 'max:40'],
            'items' => ['required', 'array'],
            'items.*.product_id' => ['required', 'string'],
            'items.*.quantity' => ['required', 'numeric'],
            'discount_type' => ['nullable', 'in:PERCENTAGE,FIXED_AMOUNT'],
            'discount_value' => ['nullable', 'numeric'],
        ], ['items.required' => 'O carrinho está vazio.']);

        return [
            'voucher_code' => $data['voucher_code'],
            'items' => array_values($data['items']),
            'discount_type' => $data['discount_type'] ?? null,
            'discount_value' => $data['discount_value'] ?? 0,
        ];
    }
}
