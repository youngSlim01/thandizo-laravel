<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transacções, relatório por agrodealer (com registo das impressões) e os
 * painéis MEAL e Finanças.
 *
 * Substitui search_transactions, agrodealer_transactions_report,
 * list_report_prints, meal_dashboard_month e finance_dashboard_month
 * (20261008063552 … 20261008114600). As datas contam em hora de Moçambique.
 */
class ReportService
{
    private const TIMEZONE = 'Africa/Maputo';

    private const STATUSES = ['PENDING', 'CONFIRMED', 'REJECTED', 'REVERSED'];

    public function __construct(private ProvinceScope $scope) {}

    /**
     * Agrodealers para os filtros (id, código, nome, situação), no âmbito.
     *
     * @return list<array<string, mixed>>
     */
    public function agrodealerOptions(User $viewer, bool $activeOnly): array
    {
        $this->requireCentral($viewer, 'REPORT_NOT_ALLOWED');
        $query = $this->agrodealersInScope($viewer)->orderBy('a.name')->select('a.id', 'a.code', 'a.name', 'a.status');
        if ($activeOnly) {
            $query->where('a.status', 'ACTIVE');
        }

        return $query->get()->map(fn ($a) => (array) $a)->all();
    }

    /**
     * @param  array<string, mixed>  $filters  from, to, status, agrodealer_id, search
     * @return array<string, mixed>
     */
    public function searchTransactions(User $viewer, array $filters, mixed $limit, mixed $offset, bool $withItems): array
    {
        $this->requireCentral($viewer, 'TRANSACTIONS_NOT_ALLOWED');
        $from = $this->date($filters['from'] ?? null);
        $to = $this->date($filters['to'] ?? null);
        if ($from && $to && $to < $from) {
            throw new DomainRuleException('INVALID_PERIOD');
        }
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
            throw new DomainRuleException('INVALID_STATUS');
        }
        $dealer = trim((string) ($filters['agrodealer_id'] ?? ''));
        if ($dealer !== '' && ! Str::isUuid($dealer)) {
            throw new DomainRuleException('AGRODEALER_NOT_FOUND');
        }
        $search = trim((string) ($filters['search'] ?? ''));

        $query = DB::table('transactions as t')
            ->join('agrodealers as a', 'a.id', '=', 't.agrodealer_id')
            ->leftJoin('districts as ad', 'ad.id', '=', 'a.district_id')
            ->join('vouchers as v', 'v.id', '=', 't.voucher_id')
            ->join('households as h', 'h.id', '=', 't.household_id')
            ->leftJoin('agrodealer_pos as pos', 'pos.id', '=', 't.pos_id')
            ->leftJoin('districts as d', 'd.id', '=', 'h.district_id')
            ->leftJoin('provinces as p', 'p.id', '=', DB::raw('coalesce(h.province_id, d.province_id)'));
        $this->scope->apply($query, $viewer, DB::raw('coalesce(a.province_id, ad.province_id)'));
        if ($from) {
            $query->whereRaw('coalesce(t.confirmed_at, t.created_at) >= ?', [$this->startUtc($from)]);
        }
        if ($to) {
            $query->whereRaw('coalesce(t.confirmed_at, t.created_at) < ?', [$this->startUtc($to)->addDay()]);
        }
        if ($status !== '') {
            $query->where('t.status', $status);
        }
        if ($dealer !== '') {
            $query->where('t.agrodealer_id', $dealer);
        }
        if ($search !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
            $query->where(fn (Builder $q) => $q->where('t.transaction_code', 'like', $like)->orWhere('v.voucher_code', 'like', $like)
                ->orWhere('h.household_code', 'like', $like)->orWhere('h.representative_name', 'like', $like)->orWhere('a.name', 'like', $like));
        }

        $confirmed = "t.status = 'CONFIRMED'";
        $summary = (clone $query)->selectRaw("
            count(*) as total,
            coalesce(sum(t.status = 'CONFIRMED'), 0) as n_confirmed, coalesce(sum(t.status = 'PENDING'), 0) as n_pending,
            coalesce(sum(t.status = 'REVERSED'), 0) as n_reversed, coalesce(sum(t.status = 'REJECTED'), 0) as n_rejected,
            coalesce(sum(case when {$confirmed} then t.subtotal end), 0) as subtotal,
            coalesce(sum(case when {$confirmed} then t.discount_amount end), 0) as discount_amount,
            coalesce(sum(case when {$confirmed} then t.total_amount end), 0) as total_amount,
            coalesce(sum(case when {$confirmed} then t.voucher_amount end), 0) as voucher_amount,
            coalesce(sum(case when {$confirmed} then t.copayment_amount end), 0) as copayment_amount,
            count(distinct case when {$confirmed} then t.household_id end) as households,
            count(distinct case when {$confirmed} then t.agrodealer_id end) as agrodealers
        ")->first();

        $rows = $query->orderByRaw('coalesce(t.confirmed_at, t.created_at) desc')->orderByDesc('t.transaction_code')
            ->limit(min(max((int) ($limit ?? 50), 1), 20000))->offset(max((int) ($offset ?? 0), 0))
            ->get([
                't.id', 't.transaction_code', 't.status', DB::raw('coalesce(t.confirmed_at, t.created_at) as sold_at'), 't.confirmed_at',
                't.agrodealer_id', 'a.name as agrodealer', 'a.code as agrodealer_code', 'pos.pos_code', 'v.voucher_code', 't.household_id',
                'h.household_code', 'h.representative_name', 'd.name as district', 'p.name as province', 't.subtotal', 't.discount_amount',
                't.total_amount', 't.voucher_amount', 't.copayment_amount',
            ]);
        $items = $withItems
            ? DB::table('transaction_items as ti')->join('products as pr', 'pr.id', '=', 'ti.product_id')
                ->whereIn('ti.transaction_id', $rows->pluck('id'))->orderBy('ti.created_at')->orderBy('pr.name')
                ->get(['ti.transaction_id', 'pr.code as product_code', 'pr.name as product', 'pr.unit', 'ti.quantity', 'ti.unit_price', 'ti.line_total'])
                ->groupBy('transaction_id')
            : collect();

        return [
            'total' => (int) $summary->total,
            'summary' => [
                'by_status' => ['CONFIRMED' => (int) $summary->n_confirmed, 'PENDING' => (int) $summary->n_pending, 'REVERSED' => (int) $summary->n_reversed, 'REJECTED' => (int) $summary->n_rejected],
                'subtotal' => (float) $summary->subtotal, 'discount_amount' => (float) $summary->discount_amount, 'total_amount' => (float) $summary->total_amount,
                'voucher_amount' => (float) $summary->voucher_amount, 'copayment_amount' => (float) $summary->copayment_amount,
                'households' => (int) $summary->households, 'agrodealers' => (int) $summary->agrodealers,
            ],
            'agrodealers' => $this->agrodealersInScope($viewer)->orderBy('a.name')->get(['a.id', 'a.code', 'a.name'])->map(fn ($a) => (array) $a)->all(),
            'rows' => $rows->map(fn ($r) => [
                ...(array) $r,
                'items' => $withItems ? $items->get($r->id, collect())->map(fn ($i) => collect((array) $i)->except('transaction_id')->all())->values()->all() : null,
            ])->all(),
        ];
    }

    /**
     * Relatório de vendas de um agrodealer num período; cada impressão fica
     * registada.
     *
     * @return array<string, mixed>
     */
    public function agrodealerReport(User $viewer, ?string $agrodealerId, ?string $from, ?string $to): array
    {
        $this->requireCentral($viewer, 'REPORT_NOT_ALLOWED');
        $from = $this->date($from);
        $to = $this->date($to);
        if (! $from || ! $to || $to < $from) {
            throw new DomainRuleException('INVALID_PERIOD');
        }
        $dealer = $this->agrodealersInScope($viewer)->leftJoin('provinces as p', 'p.id', '=', DB::raw('coalesce(a.province_id, ad.province_id)'))
            ->where('a.id', $agrodealerId)->first(['a.id', 'a.code', 'a.name', 'ad.name as district', 'p.id as province_id', 'p.name as province'])
            ?? throw new DomainRuleException('AGRODEALER_NOT_FOUND');

        $sales = DB::table('transactions as t')->join('vouchers as v', 'v.id', '=', 't.voucher_id')->join('households as h', 'h.id', '=', 't.household_id')
            ->leftJoin('settlement_items as si', fn ($j) => $j->on('si.transaction_id', '=', 't.id')->where('si.active', true))
            ->leftJoin('settlement_batches as sb', 'sb.id', '=', 'si.settlement_id')
            ->where('t.agrodealer_id', $dealer->id)->whereIn('t.status', ['CONFIRMED', 'REVERSED'])
            ->whereRaw('coalesce(t.confirmed_at, t.created_at) >= ? and coalesce(t.confirmed_at, t.created_at) < ?', [$this->startUtc($from), $this->startUtc($to)->addDay()])
            ->orderByRaw('coalesce(t.confirmed_at, t.created_at)')->orderBy('t.transaction_code')
            ->get(['t.id', 't.transaction_code', DB::raw('coalesce(t.confirmed_at, t.created_at) as sold_at'), 't.status', 'v.voucher_code', 't.household_id',
                'h.household_code', 'h.representative_name', 't.subtotal', 't.discount_amount', 't.total_amount', 't.voucher_amount', 't.copayment_amount',
                'sb.settlement_code', 'sb.status as settlement_status']);
        $ok = $sales->where('status', 'CONFIRMED');
        $sum = fn (string $f): float => round($ok->sum(fn ($t) => (float) $t->{$f}), 2);
        $totals = [
            'transactions' => $ok->count(), 'households' => $ok->pluck('household_id')->unique()->count(),
            'subtotal' => $sum('subtotal'), 'discount_amount' => $sum('discount_amount'), 'total_amount' => $sum('total_amount'),
            'voucher_amount' => $sum('voucher_amount'), 'copayment_amount' => $sum('copayment_amount'),
            'reversed' => $sales->where('status', 'REVERSED')->count(), 'settled' => $ok->whereNotNull('settlement_code')->count(),
        ];

        $printId = (string) Str::uuid7();
        DB::table('report_prints')->insert([
            'id' => $printId, 'report_type' => 'AGRODEALER_TRANSACTIONS', 'agrodealer_id' => $dealer->id, 'province_id' => $dealer->province_id,
            'period_start' => $from, 'period_end' => $to, 'transactions' => $totals['transactions'], 'total_amount' => $totals['total_amount'],
            'voucher_amount' => $totals['voucher_amount'], 'copayment_amount' => $totals['copayment_amount'], 'printed_by' => $viewer->id, 'printed_at' => now(),
        ]);

        return [
            'agrodealer' => ['id' => $dealer->id, 'code' => $dealer->code, 'name' => $dealer->name, 'district' => $dealer->district, 'province' => $dealer->province],
            'period_start' => $from, 'period_end' => $to, 'printed_at' => now()->toIso8601String(), 'printed_by' => $viewer->full_name,
            'totals' => $totals,
            'by_product' => DB::table('transaction_items as i')->join('products as pr', 'pr.id', '=', 'i.product_id')
                ->whereIn('i.transaction_id', $ok->pluck('id'))->groupBy('pr.id', 'pr.code', 'pr.name', 'pr.unit')
                ->selectRaw('pr.code, pr.name as product, pr.unit, sum(i.quantity) as quantity, sum(i.line_total) as total_amount')
                ->orderByDesc('total_amount')->get()->map(fn ($p) => [...(array) $p, 'quantity' => (float) $p->quantity, 'total_amount' => (float) $p->total_amount])->all(),
            'rows' => $sales->map(fn ($t) => collect((array) $t)->except(['id', 'household_id', 'subtotal', 'discount_amount'])->all())->values()->all(),
            'print_id' => $printId,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function reportPrints(User $viewer, mixed $limit): array
    {
        $this->requireCentral($viewer, 'REPORT_NOT_ALLOWED');
        $query = DB::table('report_prints as r')->join('agrodealers as a', 'a.id', '=', 'r.agrodealer_id')
            ->leftJoin('provinces as p', 'p.id', '=', 'r.province_id')->leftJoin('users as u', 'u.id', '=', 'r.printed_by')
            ->orderByDesc('r.printed_at')->limit(min(max((int) ($limit ?? 200), 1), 2000))
            ->select(['r.id', 'r.report_type', 'r.printed_at', 'u.full_name as printed_by', 'u.role as printed_by_role', 'r.agrodealer_id',
                'a.name as agrodealer', 'a.code as agrodealer_code', 'p.name as province', 'r.period_start', 'r.period_end', 'r.transactions',
                'r.total_amount', 'r.voucher_amount', 'r.copayment_amount']);

        return $this->scope->apply($query, $viewer, 'r.province_id')->get()->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Painel das Finanças de um mês (AAAA-MM): tendência de 6 meses e as
     * reconciliações do mês. Só para quem vê o programa inteiro.
     *
     * @return array<string, mixed>
     */
    public function financeDashboard(User $viewer, ?string $month): array
    {
        $this->requireCentral($viewer, 'DASHBOARD_NOT_ALLOWED');
        if ($viewer->isProvinceScoped()) {
            throw new DomainRuleException('DASHBOARD_NOT_ALLOWED');
        }
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $start = $this->monthStart($month, $today);
        $sales = DB::table('transactions')->where('status', 'CONFIRMED')->whereNotNull('confirmed_at')
            ->get(['agrodealer_id', 'voucher_amount', 'copayment_amount', 'confirmed_at'])
            ->map(function ($s) {
                $s->month = CarbonImmutable::parse($s->confirmed_at, 'UTC')->setTimezone(self::TIMEZONE)->format('Y-m');

                return $s;
            });
        $firstSettlement = DB::table('settlement_batches')->min('period_start');
        $first = collect([$today->format('Y-m'), $sales->min('month'), $firstSettlement ? substr($firstSettlement, 0, 7) : null])->filter()->min();
        $dealers = DB::table('agrodealers as a')->leftJoin('districts as d', 'd.id', '=', 'a.district_id')
            ->leftJoin('provinces as p', 'p.id', '=', DB::raw('coalesce(a.province_id, d.province_id)'))
            ->get(['a.id', 'a.name', 'a.status', 'p.name as province'])->keyBy('id');
        $byMonth = $sales->groupBy('month');

        return [
            'month' => $start->format('Y-m'),
            'months' => collect(CarbonPeriod::create(CarbonImmutable::parse($first.'-01', $today->timezone), '1 month', $today->startOfMonth()))
                ->map(fn ($m) => $m->format('Y-m'))->reverse()->values()->all(),
            'trend' => collect(range(5, 0))->map(function (int $back) use ($start, $byMonth) {
                $m = $start->subMonths($back)->format('Y-m');
                $rows = $byMonth->get($m, collect());

                return [
                    'month' => $m, 'voucher_amount' => round($rows->sum(fn ($s) => (float) $s->voucher_amount), 2),
                    'copayment_amount' => round($rows->sum(fn ($s) => (float) $s->copayment_amount), 2),
                    'transactions' => $rows->count(), 'agrodealers' => $rows->pluck('agrodealer_id')->unique()->count(),
                ];
            })->all(),
            'agrodealers_active' => $dealers->where('status', 'ACTIVE')->count(),
            'provinces' => $dealers->pluck('province')->filter()->unique()->sort()->values()->all(),
            'settlements' => DB::table('settlement_batches')->where('period_start', '>=', $start->toDateString())
                ->where('period_start', '<', $start->addMonth()->toDateString())->orderByDesc('created_at')->get()
                ->map(fn ($b) => [
                    'id' => $b->id, 'settlement_code' => $b->settlement_code, 'agrodealer_id' => $b->agrodealer_id,
                    'agrodealer' => $dealers->get($b->agrodealer_id)?->name, 'province' => $dealers->get($b->agrodealer_id)?->province,
                    'period_start' => $b->period_start, 'period_end' => $b->period_end, 'transaction_count' => $b->transaction_count,
                    'gross_amount' => $b->gross_amount, 'voucher_amount' => $b->voucher_amount, 'copayment_amount' => $b->copayment_amount,
                    'payable_amount' => $b->payable_amount, 'status' => $b->status, 'submitted_at' => $b->submitted_at, 'created_at' => $b->created_at,
                ])->all(),
        ];
    }

    private function agrodealersInScope(User $viewer): Builder
    {
        $query = DB::table('agrodealers as a')->leftJoin('districts as ad', 'ad.id', '=', 'a.district_id');

        return $this->scope->apply($query, $viewer, DB::raw('coalesce(a.province_id, ad.province_id)'));
    }

    private function requireCentral(User $viewer, string $code): void
    {
        if (! $viewer->isActive() || ! $viewer->role->canViewAllOperations()) {
            throw new DomainRuleException($code);
        }
    }

    private function monthStart(?string $month, CarbonImmutable $today): CarbonImmutable
    {
        if ($month === null || $month === '') {
            return $today->startOfMonth();
        }
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            throw new DomainRuleException('INVALID_PERIOD');
        }

        return CarbonImmutable::parse($month.'-01', self::TIMEZONE);
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1 || ! checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new DomainRuleException('INVALID_PERIOD');
        }

        return $value;
    }

    private function startUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::TIMEZONE)->startOfDay()->utc();
    }
}
