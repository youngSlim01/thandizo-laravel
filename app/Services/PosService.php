<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\DomainRuleException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Vendas no POS: consulta do voucher, pré-visualização, confirmação, recibo,
 * vendas recentes e anulação imediata.
 *
 * Substitui lookup_voucher_for_pos, preview_voucher_redemption,
 * confirm_pos_redemption, redeem_voucher_v2 (+ duplicate_result),
 * pos_unit_price, get_transaction_receipt, list_my_recent_transactions e
 * void_voucher_transaction.
 *
 * Regras de dinheiro (docs/BUSINESS_RULES.md do sistema antigo):
 * - o preço vem do distrito do agrodealer, só para produtos do seu catálogo;
 * - o desconto (percentagem < 100 ou valor fixo) aplica-se antes da divisão;
 * - o voucher paga até ao saldo; o resto é copagamento, no máximo 20 % do total;
 * - o browser nunca decide valores: a confirmação recalcula tudo e recusa
 *   (PREVIEW_OUTDATED) se mudou desde a pré-visualização;
 * - a mesma chave de idempotência nunca cria duas vendas;
 * - uma venda anula-se só nos 15 minutos seguintes, pelo mesmo operador.
 */
class PosService
{
    public const VOID_WINDOW_MINUTES = 15;

    public const MAX_COPAYMENT_SHARE = 0.20;

    public function __construct(
        private LedgerService $ledger,
        private ProvinceScope $scope,
        private HouseholdSearchService $households,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function lookup(User $user, ?string $voucherCode): array
    {
        if (! $user->isActive()) {
            throw new DomainRuleException('APP_USER_NOT_ACTIVE');
        }
        if (trim((string) $voucherCode) === '') {
            throw new DomainRuleException('VOUCHER_CODE_REQUIRED');
        }
        $voucher = DB::table('vouchers')->where('voucher_code', mb_strtoupper(trim($voucherCode)))->first();
        $notFound = ['success' => false, 'found' => false, 'message' => 'VOUCHER_NOT_FOUND'];
        if ($voucher === null) {
            return $notFound;
        }
        $household = DB::table('households')->where('id', $voucher->household_id)->first();
        if ($user->role === UserRole::MealOfficer && ! $this->households->isVisible($user, $household->id)) {
            return $notFound;
        }

        return [
            'success' => true, 'found' => true, 'voucher_code' => $voucher->voucher_code, 'voucher_status' => $voucher->status,
            'household_code' => $household->household_code, 'household_status' => $household->status,
            'representative_name' => $household->representative_name, 'representative_sex' => $household->representative_sex,
            'current_balance' => $voucher->current_balance, 'initial_value' => $voucher->initial_value,
            'programme_period_end' => $voucher->programme_period_end,
        ];
    }

    /**
     * Valores da venda sem gravar nada.
     *
     * @param  list<array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    public function preview(User $user, ?string $voucherCode, ?string $agrodealerId, ?string $posId, ?string $operatorId, ?array $items, ?string $discountType, mixed $discountValue): array
    {
        if (! $user->isActive()) {
            throw new DomainRuleException('APP_USER_NOT_ACTIVE');
        }
        if ($operatorId === null || $operatorId !== $user->id) {
            throw new DomainRuleException('OPERATOR_NOT_ALLOWED');
        }
        if ($user->role->isSeller() && $user->agrodealer_id !== $agrodealerId) {
            throw new DomainRuleException('AGRODEALER_NOT_ALLOWED');
        }
        $this->requireAgrodealerAndPos($agrodealerId, $posId);
        $voucher = $this->sellableVoucher($voucherCode, lock: false);
        $quote = $this->quote($voucher, (string) $agrodealerId, $items, $discountType, $discountValue);

        return [
            'success' => true, 'preview' => true, 'voucher_code' => $voucher->voucher_code, 'voucher_status' => $voucher->status,
            'agrodealer_id' => $agrodealerId, 'pos_id' => $posId, 'subtotal' => $quote['subtotal'],
            'discount_type' => $discountType, 'discount_value' => (float) ($discountValue ?? 0),
            'discount_amount' => $quote['discount_amount'], 'total_amount' => $quote['total_amount'],
            'voucher_amount' => $quote['voucher_amount'], 'copayment_amount' => $quote['copayment_amount'],
            'balance_before' => $quote['balance_before'], 'balance_after' => $quote['balance_after'],
        ];
    }

    /**
     * Grava a venda com os valores recalculados, desde que coincidam com a
     * pré-visualização mostrada ao agregado.
     *
     * @param  list<array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    public function confirm(User $user, ?string $idempotencyKey, ?string $voucherCode, ?string $posId, ?array $items, ?string $discountType, mixed $discountValue, mixed $expectedTotal, mixed $expectedVoucherAmount): array
    {
        if (! $user->isActive()) {
            throw new DomainRuleException('APP_USER_NOT_ACTIVE');
        }
        if (! $user->role->isSeller()) {
            throw new DomainRuleException('ROLE_NOT_ALLOWED');
        }
        if ($user->agrodealer_id === null) {
            throw new DomainRuleException('AGRODEALER_NOT_ALLOWED');
        }
        if ($expectedTotal === null || $expectedVoucherAmount === null) {
            throw new DomainRuleException('EXPECTED_AMOUNTS_REQUIRED');
        }

        return DB::transaction(function () use ($user, $idempotencyKey, $voucherCode, $posId, $items, $discountType, $discountValue, $expectedTotal, $expectedVoucherAmount): array {
            $result = $this->redeem($user, (string) $idempotencyKey, $voucherCode, $user->agrodealer_id, $posId, $items, $discountType, $discountValue);

            if ($this->money($result['total_amount']) !== $this->money($expectedTotal)
                || $this->money($result['voucher_amount']) !== $this->money($expectedVoucherAmount)) {
                throw new DomainRuleException('PREVIEW_OUTDATED', json_encode([
                    'expected_total' => $this->money($expectedTotal), 'expected_voucher_amount' => $this->money($expectedVoucherAmount),
                    'current_total' => $result['total_amount'], 'current_voucher_amount' => $result['voucher_amount'],
                ]));
            }

            return [...$result, 'receipt' => $this->receipt($user, $result['transaction_id'])];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function receipt(User $user, ?string $transactionId): array
    {
        if (! $user->isActive()) {
            throw new DomainRuleException('APP_USER_NOT_ACTIVE');
        }
        $tx = DB::table('transactions')->where('id', $transactionId)->first() ?? throw new DomainRuleException('TRANSACTION_NOT_FOUND');
        if ($user->role->isSeller()) {
            if ($user->agrodealer_id !== $tx->agrodealer_id) {
                throw new DomainRuleException('RECEIPT_NOT_ALLOWED');
            }
        } elseif (! $user->role->canViewAllOperations()) {
            throw new DomainRuleException('RECEIPT_NOT_ALLOWED');
        }
        $agrodealer = DB::table('agrodealers as a')->leftJoin('districts as d', 'd.id', '=', 'a.district_id')
            ->where('a.id', $tx->agrodealer_id)->first(['a.code', 'a.name', DB::raw('coalesce(a.province_id, d.province_id) as province_id')]);
        if (! $this->scope->allows($user, $agrodealer->province_id)) {
            throw new DomainRuleException('RECEIPT_NOT_ALLOWED');
        }

        $voucher = DB::table('vouchers')->where('id', $tx->voucher_id)->first();
        $household = DB::table('households')->where('id', $tx->household_id)->first();
        $reversal = DB::table('reversals')->where('transaction_id', $tx->id)->first();
        $items = DB::table('transaction_items as ti')->join('products as p', 'p.id', '=', 'ti.product_id')
            ->where('ti.transaction_id', $tx->id)->orderBy('p.name')
            ->get(['p.code as product_code', 'p.name as product_name', 'p.unit', 'ti.quantity', 'ti.unit_price', 'ti.line_total']);
        $balanceAfter = DB::table('voucher_ledger')->where('voucher_id', $tx->voucher_id)
            ->where('created_at', '<=', $tx->confirmed_at ?? $tx->created_at)
            ->orderByDesc('created_at')
            ->orderByRaw("(transaction_id = ? and entry_type = 'REDEMPTION') desc", [$tx->id])
            ->orderByDesc('id')
            ->value('balance_after');

        return [
            'transaction_id' => $tx->id, 'transaction_code' => $tx->transaction_code, 'status' => $tx->status,
            'confirmed_at' => $tx->confirmed_at, 'agrodealer_code' => $agrodealer->code, 'agrodealer_name' => $agrodealer->name,
            'pos_code' => DB::table('agrodealer_pos')->where('id', $tx->pos_id)->value('pos_code'),
            'operator_name' => DB::table('users')->where('id', $tx->operator_id)->value('full_name'),
            'voucher_code' => $voucher->voucher_code, 'household_code' => $household->household_code,
            'representative_name' => $household->representative_name, 'items' => $items,
            'subtotal' => $tx->subtotal, 'discount_type' => $tx->discount_type, 'discount_value' => $tx->discount_value,
            'discount_amount' => $tx->discount_amount, 'total_amount' => $tx->total_amount, 'voucher_amount' => $tx->voucher_amount,
            'copayment_amount' => $tx->copayment_amount, 'voucher_balance_after' => $balanceAfter,
            'voided' => $tx->status === 'REVERSED', 'voided_at' => $reversal?->completed_at, 'void_reason' => $reversal?->reason,
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Vendas do próprio operador nas últimas 24 horas.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(User $user, mixed $limit): array
    {
        if (! $user->isActive()) {
            throw new DomainRuleException('APP_USER_NOT_ACTIVE');
        }
        $limit = max(1, min((int) ($limit ?? 20), 100));

        return DB::table('transactions as t')->join('vouchers as v', 'v.id', '=', 't.voucher_id')
            ->where('t.operator_id', $user->id)
            ->whereRaw('coalesce(t.confirmed_at, t.created_at) >= ?', [now()->subDay()])
            ->orderByRaw('coalesce(t.confirmed_at, t.created_at) desc')->limit($limit)
            ->get(['t.id', 't.transaction_code', 't.status', 't.confirmed_at', 't.created_at', 'v.voucher_code', 't.total_amount', 't.voucher_amount', 't.copayment_amount'])
            ->map(function (object $t): array {
                $at = CarbonImmutable::parse($t->confirmed_at ?? $t->created_at);
                $deadline = $at->addMinutes(self::VOID_WINDOW_MINUTES);

                return [
                    'transaction_id' => $t->id, 'transaction_code' => $t->transaction_code, 'status' => $t->status,
                    'confirmed_at' => $at->toIso8601String(), 'voucher_code' => $t->voucher_code,
                    'total_amount' => $t->total_amount, 'voucher_amount' => $t->voucher_amount, 'copayment_amount' => $t->copayment_amount,
                    'can_void' => $t->status === 'CONFIRMED' && now()->lessThanOrEqualTo($deadline),
                    'void_deadline' => $deadline->toIso8601String(),
                ];
            })->all();
    }

    /**
     * Anulação imediata: o saldo volta ao voucher (entrada REVERSAL) e o
     * copagamento é devolvido ao agregado pelo agrodealer.
     *
     * @return array<string, mixed>
     */
    public function void(User $user, ?string $transactionId, ?string $operatorId, ?string $posId, ?string $reason): array
    {
        if ($transactionId === null) {
            throw new DomainRuleException('TRANSACTION_ID_REQUIRED');
        }
        if ($operatorId === null) {
            throw new DomainRuleException('OPERATOR_REQUIRED');
        }
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new DomainRuleException('VOID_REASON_REQUIRED');
        }
        if ($operatorId !== $user->id) {
            throw new DomainRuleException('OPERATOR_NOT_ALLOWED');
        }

        return DB::transaction(function () use ($user, $transactionId, $posId, $reason): array {
            $tx = DB::table('transactions')->where('id', $transactionId)->lockForUpdate()->first()
                ?? throw new DomainRuleException('TRANSACTION_NOT_FOUND');
            if ($tx->operator_id !== $user->id || ! $user->isActive()) {
                throw new DomainRuleException('OPERATOR_NOT_ALLOWED');
            }
            if ($tx->status === 'REVERSED') {
                return [
                    'success' => true, 'duplicate' => true, 'transaction_id' => $tx->id, 'transaction_code' => $tx->transaction_code,
                    'transaction_status' => $tx->status, 'reversal_id' => DB::table('reversals')->where('transaction_id', $tx->id)->value('id'),
                ];
            }
            if ($tx->status !== 'CONFIRMED') {
                throw new DomainRuleException('TRANSACTION_NOT_ELIGIBLE_FOR_VOID');
            }
            $posOk = DB::table('agrodealer_pos')->where('id', $posId)->where('agrodealer_id', $tx->agrodealer_id)->where('status', 'ACTIVE')->exists();
            if (! $posOk) {
                throw new DomainRuleException('POS_NOT_ALLOWED');
            }
            $confirmedAt = CarbonImmutable::parse($tx->confirmed_at ?? $tx->created_at);
            if (now()->greaterThan($confirmedAt->addMinutes(self::VOID_WINDOW_MINUTES))) {
                throw new DomainRuleException('VOID_WINDOW_EXPIRED');
            }
            $voucher = DB::table('vouchers')->where('id', $tx->voucher_id)->lockForUpdate()->first()
                ?? throw new DomainRuleException('VOUCHER_NOT_FOUND');
            if ($voucher->status !== 'ACTIVE') {
                throw new DomainRuleException('VOUCHER_NOT_ACTIVE');
            }
            $before = $this->money($voucher->current_balance);
            $after = $this->money($before + (float) $tx->voucher_amount);
            if ($after > (float) $voucher->initial_value) {
                throw new DomainRuleException('VOID_EXCEEDS_VOUCHER_INITIAL_VALUE');
            }

            DB::table('transactions')->where('id', $tx->id)->update(['status' => 'REVERSED', 'updated_at' => now()]);
            if ((float) $tx->voucher_amount > 0) {
                $this->ledger->record($voucher->id, 'REVERSAL', $tx->voucher_amount, $after, [
                    'transaction_id' => $tx->id, 'notes' => 'Immediate void: '.$reason, 'created_by' => $user->id,
                ]);
            }
            DB::table('vouchers')->where('id', $voucher->id)->update(['current_balance' => $after, 'updated_at' => now()]);
            $reversalId = (string) Str::uuid7();
            DB::table('reversals')->insert([
                'id' => $reversalId, 'transaction_id' => $tx->id, 'requested_by' => $user->id, 'approved_by' => $user->id,
                'status' => 'COMPLETED', 'reason' => $reason, 'requested_at' => now(), 'approved_at' => now(), 'completed_at' => now(),
            ]);
            Audit::log($user->id, 'VOUCHER_TRANSACTION_VOIDED', 'TRANSACTION', $tx->id, [
                'reversal_id' => $reversalId, 'transaction_code' => $tx->transaction_code, 'voucher_code' => $voucher->voucher_code,
                'pos_id' => $posId, 'voucher_amount_restored' => $tx->voucher_amount, 'copayment_to_refund' => $tx->copayment_amount,
                'balance_before' => $before, 'balance_after' => $after, 'reason' => $reason,
            ]);

            return [
                'success' => true, 'duplicate' => false, 'reversal_id' => $reversalId, 'transaction_id' => $tx->id,
                'transaction_code' => $tx->transaction_code, 'transaction_status' => 'REVERSED', 'voucher_code' => $voucher->voucher_code,
                'voucher_amount_restored' => $tx->voucher_amount, 'copayment_to_refund' => $tx->copayment_amount,
                'balance_before' => $before, 'balance_after' => $after,
            ];
        });
    }

    /**
     * Grava a venda (antigo redeem_voucher_v2). Repetir a mesma chave devolve a
     * venda já feita, se o pedido for igual.
     *
     * @param  list<array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    private function redeem(User $operator, string $idempotencyKey, ?string $voucherCode, string $agrodealerId, ?string $posId, ?array $items, ?string $discountType, mixed $discountValue): array
    {
        if (trim($idempotencyKey) === '') {
            throw new DomainRuleException('MISSING_IDEMPOTENCY_KEY');
        }
        $existing = DB::table('transactions')->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
        if ($existing !== null) {
            return $this->duplicateResult($existing, $voucherCode, $agrodealerId, $posId, $items);
        }

        $voucher = $this->sellableVoucher($voucherCode, lock: true);
        $this->requireAgrodealerAndPos($agrodealerId, $posId);
        $quote = $this->quote($voucher, $agrodealerId, $items, $discountType, $discountValue);

        $transactionId = (string) Str::uuid7();
        $code = 'TX-'.mb_strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 12));
        $now = now();
        DB::table('transactions')->insert([
            'id' => $transactionId, 'transaction_code' => $code, 'voucher_id' => $voucher->id, 'household_id' => $voucher->household_id,
            'agrodealer_id' => $agrodealerId, 'pos_id' => $posId, 'operator_id' => $operator->id, 'subtotal' => $quote['subtotal'],
            'discount_type' => $discountType, 'discount_value' => (float) ($discountValue ?? 0), 'discount_amount' => $quote['discount_amount'],
            'total_amount' => $quote['total_amount'], 'voucher_amount' => $quote['voucher_amount'], 'copayment_amount' => $quote['copayment_amount'],
            'status' => 'CONFIRMED', 'idempotency_key' => $idempotencyKey, 'confirmed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($quote['lines'] as $line) {
            DB::table('transaction_items')->insert([
                'id' => (string) Str::uuid7(), 'transaction_id' => $transactionId, 'product_id' => $line['product_id'],
                'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'line_total' => $line['line_total'], 'created_at' => $now,
            ]);
        }
        DB::table('vouchers')->where('id', $voucher->id)->update(['current_balance' => $quote['balance_after'], 'updated_at' => $now]);
        if ($quote['voucher_amount'] > 0) {
            $this->ledger->record($voucher->id, 'REDEMPTION', -$quote['voucher_amount'], $quote['balance_after'], [
                'transaction_id' => $transactionId, 'created_by' => $operator->id, 'created_at' => $now,
                'notes' => $quote['copayment_amount'] > 0 ? 'Redemption with household co-payment' : 'Voucher redemption',
            ]);
        }

        return [
            'success' => true, 'duplicate' => false, 'transaction_id' => $transactionId, 'transaction_code' => $code, 'status' => 'CONFIRMED',
            'voucher_code' => $voucher->voucher_code, 'subtotal' => $quote['subtotal'], 'discount_amount' => $quote['discount_amount'],
            'total_amount' => $quote['total_amount'], 'voucher_amount' => $quote['voucher_amount'], 'copayment_amount' => $quote['copayment_amount'],
            'balance_before' => $quote['balance_before'], 'balance_after' => $quote['balance_after'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    private function duplicateResult(object $existing, ?string $voucherCode, string $agrodealerId, ?string $posId, ?array $items): array
    {
        $requested = [];
        foreach ($items ?? [] as $item) {
            $id = (string) ($item['product_id'] ?? '');
            $requested[$id] = ($requested[$id] ?? 0) + (float) ($item['quantity'] ?? 0);
        }
        $original = DB::table('transaction_items')->where('transaction_id', $existing->id)
            ->groupBy('product_id')->selectRaw('product_id, sum(quantity) as q')->pluck('q', 'product_id')
            ->map(fn ($q) => (float) $q)->all();
        ksort($requested);
        ksort($original);
        $sameVoucher = DB::table('vouchers')->where('id', $existing->voucher_id)->where('voucher_code', mb_strtoupper(trim((string) $voucherCode)))->exists();
        if ($existing->agrodealer_id !== $agrodealerId || $existing->pos_id !== $posId || ! $sameVoucher || $requested != $original) {
            throw new DomainRuleException('IDEMPOTENCY_KEY_MISMATCH');
        }

        return [
            'success' => true, 'duplicate' => true, 'transaction_id' => $existing->id, 'transaction_code' => $existing->transaction_code,
            'status' => $existing->status, 'total_amount' => $existing->total_amount, 'voucher_amount' => $existing->voucher_amount,
            'copayment_amount' => $existing->copayment_amount,
        ];
    }

    /**
     * Cálculo da venda, igual na pré-visualização e na confirmação.
     *
     * @param  list<array<string, mixed>>|null  $items
     * @return array{subtotal: float, discount_amount: float, total_amount: float, voucher_amount: float, copayment_amount: float, balance_before: float, balance_after: float, lines: list<array{product_id: string, quantity: float, unit_price: float, line_total: float}>}
     */
    private function quote(object $voucher, string $agrodealerId, ?array $items, ?string $discountType, mixed $discountValue): array
    {
        if ($items === null || $items === []) {
            throw new DomainRuleException('EMPTY_CART');
        }
        $lines = [];
        $seen = [];
        $subtotal = 0.0;
        foreach ($items as $item) {
            $productId = $item['product_id'] ?? null;
            if ($productId === null || $productId === '') {
                throw new DomainRuleException('PRODUCT_REQUIRED');
            }
            if (! Str::isUuid((string) $productId)) {
                throw new DomainRuleException('INVALID_PRODUCT_ID');
            }
            $quantity = $item['quantity'] ?? null;
            if (! is_numeric($quantity) || (float) $quantity <= 0) {
                throw new DomainRuleException('INVALID_QUANTITY');
            }
            if (isset($seen[$productId])) {
                throw new DomainRuleException('DUPLICATE_PRODUCT_IN_CART');
            }
            $seen[$productId] = true;
            if (! DB::table('products')->where('id', $productId)->where('status', 'ACTIVE')->exists()) {
                throw new DomainRuleException('INVALID_PRODUCT_OR_PRICE');
            }
            $unitPrice = $this->unitPrice((string) $productId, $agrodealerId);
            $lineTotal = $this->money($unitPrice * (float) $quantity);
            $subtotal = $this->money($subtotal + $lineTotal);
            $lines[] = ['product_id' => (string) $productId, 'quantity' => (float) $quantity, 'unit_price' => $unitPrice, 'line_total' => $lineTotal];
        }
        if ($subtotal <= 0) {
            throw new DomainRuleException('EMPTY_CART');
        }

        $value = (float) ($discountValue ?? 0);
        $discount = match ($discountType) {
            null, '' => $value != 0 ? throw new DomainRuleException('DISCOUNT_TYPE_REQUIRED') : 0.0,
            'PERCENTAGE' => $value < 0 || $value >= 100 ? throw new DomainRuleException('INVALID_DISCOUNT') : $this->money($subtotal * $value / 100),
            'FIXED_AMOUNT' => $value < 0 || $value >= $subtotal ? throw new DomainRuleException('INVALID_DISCOUNT') : $this->money($value),
            default => throw new DomainRuleException('INVALID_DISCOUNT_TYPE'),
        };
        $total = $this->money($subtotal - $discount);
        if ($total <= 0) {
            throw new DomainRuleException('TOTAL_MUST_BE_GREATER_THAN_ZERO');
        }

        $before = $this->money($voucher->current_balance);
        $voucherAmount = min($total, $before);
        $copayment = $this->money($total - $voucherAmount);
        $after = $this->money($before - $voucherAmount);
        if ($copayment > $this->money($total * self::MAX_COPAYMENT_SHARE)) {
            throw new DomainRuleException('COPAYMENT_LIMIT_EXCEEDED', json_encode([
                'total_amount' => $total, 'copayment_amount' => $copayment,
                'max_copayment' => $this->money($total * self::MAX_COPAYMENT_SHARE), 'max_total' => $this->money($before / 0.8),
            ]));
        }

        return [
            'subtotal' => $subtotal, 'discount_amount' => $discount, 'total_amount' => $total, 'voucher_amount' => $voucherAmount,
            'copayment_amount' => $copayment, 'balance_before' => $before, 'balance_after' => $after, 'lines' => $lines,
        ];
    }

    /**
     * Preço do produto no distrito do agrodealer (antigo pos_unit_price).
     */
    private function unitPrice(string $productId, string $agrodealerId): float
    {
        $agrodealer = DB::table('agrodealers')->where('id', $agrodealerId)->first(['district_id']) ?? throw new DomainRuleException('INVALID_AGRODEALER');
        if ($agrodealer->district_id === null) {
            throw new DomainRuleException('AGRODEALER_DISTRICT_REQUIRED');
        }
        if (! DB::table('agrodealer_products')->where('agrodealer_id', $agrodealerId)->where('product_id', $productId)->exists()) {
            throw new DomainRuleException('PRODUCT_NOT_IN_CATALOGUE');
        }
        $price = app(PriceService::class)->currentPrice($productId, $agrodealer->district_id) ?? throw new DomainRuleException('PRODUCT_NO_DISTRICT_PRICE');

        return (float) $price;
    }

    private function sellableVoucher(?string $voucherCode, bool $lock): object
    {
        $query = DB::table('vouchers')->where('voucher_code', mb_strtoupper(trim((string) $voucherCode)));
        if ($lock) {
            $query->lockForUpdate();
        }
        $voucher = $query->first() ?? throw new DomainRuleException('VOUCHER_NOT_FOUND');
        if ($voucher->status !== 'ACTIVE') {
            throw new DomainRuleException('VOUCHER_NOT_ACTIVE');
        }
        if (DB::table('households')->where('id', $voucher->household_id)->where('status', '<>', 'ACTIVE')->exists()) {
            throw new DomainRuleException('HOUSEHOLD_INACTIVE');
        }
        if ((float) $voucher->current_balance <= 0) {
            throw new DomainRuleException('VOUCHER_BALANCE_EMPTY');
        }

        return $voucher;
    }

    private function requireAgrodealerAndPos(?string $agrodealerId, ?string $posId): void
    {
        if (! DB::table('agrodealers')->where('id', $agrodealerId)->where('status', 'ACTIVE')->exists()) {
            throw new DomainRuleException('AGRODEALER_NOT_ALLOWED');
        }
        if (! DB::table('agrodealer_pos')->where('id', $posId)->where('agrodealer_id', $agrodealerId)->where('status', 'ACTIVE')->exists()) {
            throw new DomainRuleException('POS_NOT_ALLOWED');
        }
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
