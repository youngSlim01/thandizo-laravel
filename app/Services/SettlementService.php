<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\DomainRuleException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reconciliações dos agrodealers: o MEAL junta as vendas de um período, o
 * gestor MEAL aprova (quando foi um oficial a criar) e as Finanças validam.
 *
 * Substitui create_agrodealer_settlement, submit_agrodealer_settlement,
 * manager_approve_settlement, manager_return_settlement,
 * finance_validate_settlement, finance_return_settlement e list_settlements
 * (20261008060000 … 20261008114600).
 *
 * Regras: só períodos já terminados; uma venda está no máximo numa
 * reconciliação activa; devolver liberta as vendas para outra; o valor a
 * pagar ao agrodealer é o valor dos vouchers.
 */
class SettlementService
{
    private const TIMEZONE = 'Africa/Maputo';

    public function __construct(private ProvinceScope $scope) {}

    /**
     * @return string id da reconciliação
     */
    public function create(User $actor, ?string $agrodealerId, ?string $periodStart, ?string $periodEnd): string
    {
        $this->requireMealRole($actor, 'SETTLEMENT_NOT_ALLOWED');
        if (! $periodStart || ! $periodEnd || $periodEnd < $periodStart) {
            throw new DomainRuleException('INVALID_SETTLEMENT_PERIOD');
        }
        if ($periodEnd >= CarbonImmutable::now(self::TIMEZONE)->toDateString()) {
            throw new DomainRuleException('SETTLEMENT_PERIOD_NOT_CLOSED');
        }
        if (! $this->agrodealerInScope($actor, $agrodealerId)) {
            throw new DomainRuleException('AGRODEALER_NOT_FOUND');
        }

        return DB::transaction(function () use ($actor, $agrodealerId, $periodStart, $periodEnd): string {
            DB::table('agrodealers')->where('id', $agrodealerId)->lockForUpdate()->first();
            $exists = DB::table('settlement_batches')->where('agrodealer_id', $agrodealerId)
                ->where('period_start', $periodStart)->where('period_end', $periodEnd)
                ->whereNotIn('status', ['RETURNED', 'MANAGER_RETURNED'])->exists();
            if ($exists) {
                throw new DomainRuleException('SETTLEMENT_ALREADY_EXISTS');
            }

            [$from, $to] = $this->utcRange($periodStart, $periodEnd);
            $sales = DB::table('transactions as t')->where('t.agrodealer_id', $agrodealerId)->where('t.status', 'CONFIRMED')
                ->where('t.confirmed_at', '>=', $from)->where('t.confirmed_at', '<', $to)
                ->whereNotExists(fn ($q) => $q->from('settlement_items as si')->whereColumn('si.transaction_id', 't.id')->where('si.active', true))
                ->lockForUpdate()
                ->get(['t.id', 't.total_amount', 't.voucher_amount', 't.copayment_amount', 't.discount_amount']);
            if ($sales->isEmpty()) {
                throw new DomainRuleException('NO_SETTLEMENT_TRANSACTIONS');
            }

            $sum = fn (string $field): float => round($sales->sum(fn ($t) => (float) $t->{$field}), 2);
            $id = (string) Str::uuid7();
            $code = 'SET-'.str_replace('-', '', $periodStart).'-'.mb_strtoupper(substr(str_replace('-', '', (string) $agrodealerId), 0, 8))
                .'-'.mb_strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 5));
            DB::table('settlement_batches')->insert([
                'id' => $id, 'settlement_code' => $code, 'agrodealer_id' => $agrodealerId, 'period_start' => $periodStart, 'period_end' => $periodEnd,
                'transaction_count' => $sales->count(), 'gross_amount' => $sum('total_amount'), 'voucher_amount' => $sum('voucher_amount'),
                'copayment_amount' => $sum('copayment_amount'), 'discount_amount' => $sum('discount_amount'), 'payable_amount' => $sum('voucher_amount'),
                'status' => 'RECONCILED', 'created_by' => $actor->id, 'created_at' => now(),
            ]);
            DB::table('settlement_items')->insert($sales->map(fn ($t) => [
                'id' => (string) Str::uuid7(), 'settlement_id' => $id, 'transaction_id' => $t->id, 'active' => true,
            ])->all());
            Audit::log($actor->id, 'SETTLEMENT_CREATED', 'SETTLEMENT', $id, [
                'settlement_code' => $code, 'agrodealer_id' => $agrodealerId, 'period_start' => $periodStart, 'period_end' => $periodEnd,
                'transactions' => $sales->count(), 'payable_amount' => $sum('voucher_amount'),
            ]);

            return $id;
        });
    }

    /**
     * Enviar: de um oficial vai ao gestor MEAL; de um gestor ou administrador
     * vai directamente às Finanças.
     */
    public function submit(User $actor, ?string $settlementId): string
    {
        $this->requireMealRole($actor, 'SETTLEMENT_NOT_ALLOWED');

        return DB::transaction(function () use ($actor, $settlementId): string {
            $batch = DB::table('settlement_batches')->where('id', $settlementId)->lockForUpdate()->first();
            if ($batch === null || ! $this->agrodealerInScope($actor, $batch->agrodealer_id)) {
                throw new DomainRuleException('SETTLEMENT_NOT_FOUND');
            }
            if ($batch->status !== 'RECONCILED') {
                throw new DomainRuleException('SETTLEMENT_NOT_RECONCILED');
            }
            $next = $actor->role === UserRole::MealOfficer ? 'PENDING_MANAGER' : 'SUBMITTED_TO_FINANCE';
            DB::table('settlement_batches')->where('id', $batch->id)->update(['status' => $next, 'submitted_at' => now(), 'submitted_by' => $actor->id]);
            Audit::log($actor->id, $next === 'PENDING_MANAGER' ? 'SETTLEMENT_SENT_TO_MANAGER' : 'SETTLEMENT_SUBMITTED_TO_FINANCE', 'SETTLEMENT', $batch->id, [
                'settlement_code' => $batch->settlement_code,
            ]);

            return $next;
        });
    }

    public function managerApprove(User $actor, ?string $settlementId): void
    {
        $this->requireManager($actor);
        DB::transaction(function () use ($actor, $settlementId): void {
            $batch = $this->pendingManager($settlementId);
            DB::table('settlement_batches')->where('id', $batch->id)->update([
                'status' => 'SUBMITTED_TO_FINANCE', 'manager_reviewed_by' => $actor->id, 'manager_reviewed_at' => now(),
            ]);
            Audit::log($actor->id, 'SETTLEMENT_APPROVED_BY_MANAGER', 'SETTLEMENT', $batch->id, ['settlement_code' => $batch->settlement_code]);
        });
    }

    public function managerReturn(User $actor, ?string $settlementId, ?string $reason): void
    {
        $this->requireManager($actor);
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new DomainRuleException('RETURN_REASON_REQUIRED');
        }
        DB::transaction(function () use ($actor, $settlementId, $reason): void {
            $batch = $this->pendingManager($settlementId);
            DB::table('settlement_batches')->where('id', $batch->id)->update([
                'status' => 'MANAGER_RETURNED', 'manager_reviewed_by' => $actor->id, 'manager_reviewed_at' => now(), 'manager_notes' => $reason,
            ]);
            $this->releaseSales($batch->id);
            Audit::log($actor->id, 'SETTLEMENT_RETURNED_BY_MANAGER', 'SETTLEMENT', $batch->id, ['settlement_code' => $batch->settlement_code, 'reason' => $reason]);
        });
    }

    public function financeValidate(User $actor, ?string $settlementId): void
    {
        $this->requireFinance($actor);
        $updated = DB::table('settlement_batches')->where('id', $settlementId)->where('status', 'SUBMITTED_TO_FINANCE')->update(['status' => 'VALIDATED']);
        if ($updated === 0) {
            throw new DomainRuleException('SETTLEMENT_NOT_READY_FOR_VALIDATION');
        }
        Audit::log($actor->id, 'SETTLEMENT_VALIDATED', 'SETTLEMENT', $settlementId);
    }

    public function financeReturn(User $actor, ?string $settlementId, ?string $reason): void
    {
        $this->requireFinance($actor);
        $reason = trim((string) $reason);
        if ($reason === '') {
            throw new DomainRuleException('FINANCE_REJECTION_REASON_REQUIRED');
        }
        DB::transaction(function () use ($actor, $settlementId, $reason): void {
            $updated = DB::table('settlement_batches')->where('id', $settlementId)->where('status', 'SUBMITTED_TO_FINANCE')
                ->update(['status' => 'RETURNED', 'notes' => $reason]);
            if ($updated === 0) {
                throw new DomainRuleException('SETTLEMENT_NOT_READY_FOR_RETURN');
            }
            $this->releaseSales((string) $settlementId);
            Audit::log($actor->id, 'SETTLEMENT_RETURNED', 'SETTLEMENT', $settlementId, ['reason' => $reason]);
        });
    }

    /**
     * Reconciliações, mais recentes primeiro (o Oficial MEAL só da sua província).
     *
     * @return list<array<string, mixed>>
     */
    public function list(User $viewer): array
    {
        $allowed = $viewer->isActive() && ($viewer->role->isMealBackOffice() || $viewer->role === UserRole::FinanceOfficer);
        if (! $allowed) {
            throw new DomainRuleException('SETTLEMENT_NOT_ALLOWED');
        }
        $query = DB::table('settlement_batches as b')
            ->join('agrodealers as a', 'a.id', '=', 'b.agrodealer_id')
            ->leftJoin('districts as d', 'd.id', '=', 'a.district_id')
            ->leftJoin('provinces as p', 'p.id', '=', DB::raw('coalesce(a.province_id, d.province_id)'))
            ->leftJoin('users as cu', 'cu.id', '=', 'b.created_by')
            ->leftJoin('users as su', 'su.id', '=', 'b.submitted_by')
            ->leftJoin('users as mu', 'mu.id', '=', 'b.manager_reviewed_by')
            ->orderByDesc('b.created_at')->orderByDesc('b.id')
            ->select([
                'b.id', 'b.settlement_code', 'b.agrodealer_id', 'a.name as agrodealer', 'a.code as agrodealer_code', 'p.name as province',
                'b.period_start', 'b.period_end', 'b.transaction_count', 'b.gross_amount', 'b.voucher_amount', 'b.copayment_amount',
                'b.discount_amount', 'b.payable_amount', 'b.status', 'b.notes', 'b.manager_notes', 'b.created_at', 'cu.full_name as created_by',
                'b.submitted_at', 'su.full_name as submitted_by', 'b.manager_reviewed_at', 'mu.full_name as manager_reviewed_by',
            ]);

        return $this->scope->apply($query, $viewer, 'p.id')->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Vendas de uma reconciliação (antiga leitura directa de
     * settlement_items + transactions).
     *
     * @return list<array<string, mixed>>
     */
    public function transactions(User $viewer, ?string $settlementId): array
    {
        $batch = DB::table('settlement_batches')->where('id', $settlementId)->first();
        $allowed = $batch !== null && (
            ($viewer->isActive() && $viewer->role->isFinance())
            || ($viewer->isActive() && $viewer->role->isMealBackOffice() && $this->agrodealerInScope($viewer, $batch->agrodealer_id))
        );
        if (! $allowed) {
            throw new DomainRuleException('SETTLEMENT_NOT_FOUND');
        }

        return DB::table('settlement_items as si')->join('transactions as t', 't.id', '=', 'si.transaction_id')
            ->join('vouchers as v', 'v.id', '=', 't.voucher_id')
            ->where('si.settlement_id', $settlementId)->orderBy('t.confirmed_at')
            ->get(['t.id', 't.transaction_code', 't.total_amount', 't.voucher_amount', 't.copayment_amount', 't.status', 't.confirmed_at', 'v.voucher_code'])
            ->map(fn ($t) => (array) $t)
            ->all();
    }

    private function releaseSales(string $settlementId): void
    {
        DB::table('settlement_items')->where('settlement_id', $settlementId)->where('active', true)->update(['active' => false, 'released_at' => now()]);
    }

    private function pendingManager(?string $settlementId): object
    {
        $batch = DB::table('settlement_batches')->where('id', $settlementId)->lockForUpdate()->first();
        if ($batch === null || $batch->status !== 'PENDING_MANAGER') {
            throw new DomainRuleException('SETTLEMENT_NOT_PENDING_MANAGER');
        }

        return $batch;
    }

    private function agrodealerInScope(User $viewer, ?string $agrodealerId): bool
    {
        $province = DB::table('agrodealers as a')->leftJoin('districts as d', 'd.id', '=', 'a.district_id')
            ->where('a.id', $agrodealerId)->value(DB::raw('coalesce(a.province_id, d.province_id)'));
        $exists = DB::table('agrodealers')->where('id', $agrodealerId)->exists();

        return $exists && $this->scope->allows($viewer, $province);
    }

    /**
     * Um período em datas de Moçambique, em instantes UTC [início, fim).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcRange(string $periodStart, string $periodEnd): array
    {
        return [
            CarbonImmutable::parse($periodStart, self::TIMEZONE)->startOfDay()->utc(),
            CarbonImmutable::parse($periodEnd, self::TIMEZONE)->addDay()->startOfDay()->utc(),
        ];
    }

    private function requireMealRole(User $actor, string $code): void
    {
        if (! $actor->isActive() || ! $actor->role->isMealBackOffice()) {
            throw new DomainRuleException($code);
        }
    }

    private function requireManager(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->role->canManageHouseholds()) {
            throw new DomainRuleException('SETTLEMENT_APPROVAL_NOT_ALLOWED');
        }
    }

    private function requireFinance(User $actor): void
    {
        if (! $actor->isActive() || ! $actor->role->isFinance()) {
            throw new DomainRuleException('FINANCE_NOT_ALLOWED');
        }
    }
}
