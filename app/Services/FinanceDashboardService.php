<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Painel das Finanças: o estado actual, sem comparações com meses.
 *
 * - Caixa de entrada: reconciliações à espera de validação, há quantos dias,
 *   e as que passaram o prazo (dias na Configuração).
 * - O que vem a caminho: em aprovação no MEAL e vendas ainda sem
 *   reconciliação, para prever a tesouraria.
 * - Por comerciante e por ciclo (atribuído, usado, expirado, validado), para
 *   os relatórios à organização e ao doador.
 *
 * O pagamento ao comerciante faz-se no sistema da organização: aqui o último
 * passo é a validação.
 */
class FinanceDashboardService
{
    private const TIMEZONE = 'Africa/Maputo';

    /** Reconciliações ainda no MEAL (criadas, à espera do gestor). */
    private const IN_MEAL = ['RECONCILED', 'PENDING_MANAGER'];

    public function __construct(private ClientProfileService $profile) {}

    /**
     * @return array<string, mixed>
     */
    public function current(User $viewer): array
    {
        if (! $viewer->isActive() || ! $viewer->role->canViewAllOperations() || $viewer->isProvinceScoped()) {
            throw new DomainRuleException('DASHBOARD_NOT_ALLOWED');
        }
        $deadline = $this->profile->settlementDeadlineDays();
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        $settlements = DB::table('settlement_batches as s')->join('agrodealers as a', 'a.id', '=', 's.agrodealer_id')
            ->get(['s.id', 's.settlement_code', 's.status', 's.payable_amount', 's.transaction_count', 's.period_start', 's.period_end',
                's.submitted_at', 's.manager_reviewed_at', 's.agrodealer_id', 'a.name as agrodealer', 'a.code as agrodealer_code']);
        $inbox = $settlements->where('status', 'SUBMITTED_TO_FINANCE')->map(function ($s) use ($today, $deadline) {
            $arrived = $s->manager_reviewed_at ?? $s->submitted_at;
            $s->arrived_at = $arrived;
            $s->days_waiting = $arrived ? (int) CarbonImmutable::parse($arrived, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay()->diffInDays($today) : 0;
            $s->overdue = $s->days_waiting > $deadline;

            return $s;
        })->sortByDesc('days_waiting')->values();
        $sum = fn (Collection $rows, string $field): float => round($rows->sum(fn ($r) => (float) $r->{$field}), 2);

        $sales = DB::table('transactions as t')
            ->leftJoin('settlement_items as si', 'si.active_transaction_id', '=', 't.id')
            ->leftJoin('settlement_batches as s', 's.id', '=', 'si.settlement_id')
            ->where('t.status', 'CONFIRMED')
            ->get(['t.agrodealer_id', 't.voucher_amount', 't.confirmed_at', 's.status as settlement_status'])
            ->map(function ($t) {
                $t->stage = match (true) {
                    $t->settlement_status === null => 'not_reconciled',
                    in_array($t->settlement_status, self::IN_MEAL, true) => 'in_meal',
                    $t->settlement_status === 'SUBMITTED_TO_FINANCE' => 'awaiting_finance',
                    $t->settlement_status === 'VALIDATED' => 'validated',
                    default => 'not_reconciled',
                };

                return $t;
            });
        $byStage = fn (Collection $rows, string $stage): float => round($rows->where('stage', $stage)->sum(fn ($t) => (float) $t->voucher_amount), 2);
        $dealers = DB::table('agrodealers')->get(['id', 'name', 'code'])->keyBy('id');

        return [
            'deadline_days' => $deadline,
            'kpis' => [
                'awaiting' => $inbox->count(),
                'awaiting_amount' => $sum($inbox, 'payable_amount'),
                'overdue' => $inbox->where('overdue', true)->count(),
                'overdue_amount' => $sum($inbox->where('overdue', true), 'payable_amount'),
                'oldest_days' => (int) ($inbox->max('days_waiting') ?? 0),
                'validated_amount' => $byStage($sales, 'validated'),
                'in_meal_amount' => $byStage($sales, 'in_meal'),
                'not_reconciled_amount' => $byStage($sales, 'not_reconciled'),
            ],
            'alerts' => [
                ['key' => 'overdue', 'label' => "Reconciliações há mais de {$deadline} dias por validar", 'value' => $inbox->where('overdue', true)->count(), 'href' => '/finance/settlements', 'level' => 'critical'],
                ['key' => 'awaiting', 'label' => 'Reconciliações à espera de validação', 'value' => $inbox->count(), 'href' => '/finance/settlements', 'level' => 'attention'],
                ['key' => 'returned', 'label' => 'Devolvidas ao MEAL para corrigir', 'value' => $settlements->whereIn('status', ['RETURNED', 'MANAGER_RETURNED'])->count(), 'href' => '/finance/settlements', 'level' => 'attention'],
                ['key' => 'in_meal', 'label' => 'Em aprovação no MEAL', 'value' => $settlements->whereIn('status', self::IN_MEAL)->count(), 'href' => '/finance/settlements', 'level' => 'attention'],
            ],
            'inbox' => $inbox->take(10)->map(fn ($s) => [
                'id' => $s->id, 'settlement_code' => $s->settlement_code, 'agrodealer' => $s->agrodealer, 'agrodealer_code' => $s->agrodealer_code,
                'period_start' => $s->period_start, 'period_end' => $s->period_end, 'transactions' => (int) $s->transaction_count,
                'amount' => (float) $s->payable_amount, 'arrived_at' => $s->arrived_at, 'days_waiting' => $s->days_waiting, 'overdue' => $s->overdue,
            ])->all(),
            'by_agrodealer' => $sales->groupBy('agrodealer_id')->map(fn (Collection $g, string $id) => [
                'name' => $dealers->get($id)?->name, 'code' => $dealers->get($id)?->code,
                'not_reconciled' => $byStage($g, 'not_reconciled'), 'in_meal' => $byStage($g, 'in_meal'),
                'awaiting_finance' => $byStage($g, 'awaiting_finance'), 'validated' => $byStage($g, 'validated'),
            ])->sortByDesc(fn ($d) => $d['awaiting_finance'] + $d['in_meal'] + $d['not_reconciled'])->values()->all(),
            'by_cycle' => $this->byCycle($sales),
        ];
    }

    /**
     * Por ciclo: atribuído nos cartões, usado nas compras, expirado no fecho
     * e já validado para pagamento.
     *
     * @param  Collection<int, object>  $sales
     * @return list<array<string, mixed>>
     */
    private function byCycle(Collection $sales): array
    {
        $allocated = DB::table('cycle_households')->groupBy('cycle_id')
            ->selectRaw('cycle_id, sum(amount) as allocated, sum(coalesce(unused_amount, 0)) as expired')->get()->keyBy('cycle_id');

        return DB::table('programme_cycles')->orderByDesc('opened_at')->get()->map(function ($c) use ($sales, $allocated) {
            $inCycle = $sales->filter(fn ($t) => $t->confirmed_at >= $c->opened_at && ($c->closed_at === null || $t->confirmed_at < $c->closed_at));
            $used = round($inCycle->sum(fn ($t) => (float) $t->voucher_amount), 2);
            $validated = round($inCycle->where('stage', 'validated')->sum(fn ($t) => (float) $t->voucher_amount), 2);

            return [
                'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'status' => $c->status,
                'allocated' => round((float) ($allocated->get($c->id)?->allocated ?? 0), 2),
                'used' => $used,
                'expired' => $c->status === 'CLOSED' ? round((float) ($allocated->get($c->id)?->expired ?? 0), 2) : null,
                'validated' => $validated,
                'pending' => round($used - $validated, 2),
            ];
        })->all();
    }
}
