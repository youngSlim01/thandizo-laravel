<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * O que o gestor de comerciante (o dono da loja) vê da sua loja (decidido a
 * 9/11/2026): todas as vendas do ciclo, de qualquer operador, e quanto lhe
 * devem em cada fase do pagamento. Só anula as vendas que ele próprio fez.
 */
class MerchantAccountService
{
    private const SALES_LIMIT = 300;

    /** Reconciliações ainda no MEAL (criadas, à espera do gestor, devolvidas). */
    private const IN_MEAL = ['RECONCILED', 'PENDING_MANAGER', 'MANAGER_RETURNED', 'RETURNED'];

    public static function isOwner(User $user): bool
    {
        return $user->isActive() && $user->role === UserRole::AgrodealerManager && $user->agrodealer_id !== null;
    }

    /**
     * Vendas da loja desde a abertura do ciclo aberto (sem ciclo aberto, os
     * últimos 30 dias), mais recentes primeiro.
     *
     * @return array{since: string, cycle: string|null, sales: list<array<string, mixed>>}
     */
    public function sales(User $owner, int $voidWindowMinutes): array
    {
        $cycle = DB::table('programme_cycles')->where('status', 'OPEN')->first(['code', 'opened_at']);
        $since = $cycle ? CarbonImmutable::parse($cycle->opened_at) : now()->subDays(30)->toImmutable();

        $sales = DB::table('transactions as t')->join('vouchers as v', 'v.id', '=', 't.voucher_id')
            ->leftJoin('users as u', 'u.id', '=', 't.operator_id')
            ->where('t.agrodealer_id', $owner->agrodealer_id)
            ->whereRaw('coalesce(t.confirmed_at, t.created_at) >= ?', [$since])
            ->orderByRaw('coalesce(t.confirmed_at, t.created_at) desc')->limit(self::SALES_LIMIT)
            ->get(['t.id', 't.transaction_code', 't.status', 't.confirmed_at', 't.created_at', 't.operator_id', 'u.full_name as operator_name',
                'v.voucher_code', 't.total_amount', 't.voucher_amount', 't.copayment_amount'])
            ->map(function (object $t) use ($owner, $voidWindowMinutes): array {
                $at = CarbonImmutable::parse($t->confirmed_at ?? $t->created_at);
                $deadline = $at->addMinutes($voidWindowMinutes);
                $mine = $t->operator_id === $owner->id;

                return [
                    'transaction_id' => $t->id, 'transaction_code' => $t->transaction_code, 'status' => $t->status,
                    'confirmed_at' => $at->toIso8601String(), 'voucher_code' => $t->voucher_code,
                    'total_amount' => $t->total_amount, 'voucher_amount' => $t->voucher_amount, 'copayment_amount' => $t->copayment_amount,
                    'operator_name' => $t->operator_name, 'mine' => $mine,
                    'can_void' => $mine && $t->status === 'CONFIRMED' && now()->lessThanOrEqualTo($deadline),
                    'void_deadline' => $deadline->toIso8601String(),
                ];
            })->all();

        return ['since' => $since->toIso8601String(), 'cycle' => $cycle?->code, 'sales' => $sales];
    }

    /**
     * Quanto se deve à loja (parte do voucher das vendas confirmadas) em cada
     * fase, e as reconciliações da loja.
     *
     * @return array{stages: array{not_reconciled: float, in_meal: float, in_finance: float, validated: float}, settlements: list<array<string, mixed>>}
     */
    public function payments(User $owner): array
    {
        $stages = ['not_reconciled' => 0.0, 'in_meal' => 0.0, 'in_finance' => 0.0, 'validated' => 0.0];
        DB::table('transactions as t')
            ->leftJoin('settlement_items as si', 'si.active_transaction_id', '=', 't.id')
            ->leftJoin('settlement_batches as s', 's.id', '=', 'si.settlement_id')
            ->where('t.agrodealer_id', $owner->agrodealer_id)->where('t.status', 'CONFIRMED')
            ->get(['t.voucher_amount', 's.status'])
            ->each(function (object $t) use (&$stages): void {
                $stage = match (true) {
                    $t->status === null => 'not_reconciled',
                    in_array($t->status, self::IN_MEAL, true) => 'in_meal',
                    $t->status === 'SUBMITTED_TO_FINANCE' => 'in_finance',
                    default => 'validated',
                };
                $stages[$stage] += (float) $t->voucher_amount;
            });

        $settlements = DB::table('settlement_batches')->where('agrodealer_id', $owner->agrodealer_id)
            ->orderByDesc('period_end')->orderByDesc('created_at')
            ->get(['id', 'settlement_code', 'period_start', 'period_end', 'transaction_count', 'payable_amount', 'status', 'created_at', 'manager_reviewed_at', 'submitted_at'])
            ->map(fn (object $s): array => [
                'id' => $s->id, 'settlement_code' => $s->settlement_code, 'period_start' => $s->period_start, 'period_end' => $s->period_end,
                'transactions' => (int) $s->transaction_count, 'amount' => (float) $s->payable_amount,
                'stage' => match (true) {
                    in_array($s->status, self::IN_MEAL, true) => 'in_meal',
                    $s->status === 'SUBMITTED_TO_FINANCE' => 'in_finance',
                    default => 'validated',
                },
                'created_at' => $s->created_at,
            ])->all();

        return ['stages' => array_map(fn (float $v): float => round($v, 2), $stages), 'settlements' => $settlements];
    }
}
