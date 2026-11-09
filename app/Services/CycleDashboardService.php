<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Painel MEAL do dia-a-dia, organizado por ciclo (o aberto por omissão).
 *
 * Responde a "o que faço hoje?": o ritmo de uso do dinheiro no tempo do
 * ciclo, o que precisa de acção (alertas), a utilização por escalão, quem
 * ainda não comprou, por sexo, por província e o que falta pagar a cada
 * comerciante. O Oficial MEAL vê só a sua província: os agregados, as
 * compras deles e os comerciantes e lotes da província.
 *
 * Valores do ciclo: atribuído = o carregado nos cartões (cycle_households);
 * usado = parte do voucher das compras confirmadas dos agregados do ciclo,
 * entre a abertura e o fecho.
 */
class CycleDashboardService
{
    private const TIMEZONE = 'Africa/Maputo';

    /** Um bairro entra no alerta quando pelo menos esta parte não comprou. */
    public const NOT_BOUGHT_ALERT_SHARE = 0.3;

    /** Dias antes do fim do ciclo em que o saldo por usar vira alerta. */
    public const CLOSING_SOON_DAYS = 7;

    public function __construct(private HouseholdSearchService $households, private ProvinceScope $scope, private CyclePlanService $plans) {}

    /**
     * @return array<string, mixed>
     */
    public function forViewer(User $viewer, ?string $cycleId): array
    {
        if (! $viewer->isActive() || ! $viewer->role->canViewAllOperations()) {
            throw new DomainRuleException('DASHBOARD_NOT_ALLOWED');
        }
        $cycles = DB::table('programme_cycles')->orderByDesc('opened_at')->get(['id', 'code', 'name', 'description', 'status', 'starts_on', 'ends_on', 'opened_at', 'closed_at']);
        $cycle = $cycleId
            ? ($cycles->firstWhere('id', $cycleId) ?? throw new DomainRuleException('CYCLE_NOT_FOUND'))
            : ($cycles->firstWhere('status', 'OPEN') ?? $cycles->first());

        $base = [
            'cycles' => $cycles->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'status' => $c->status])->all(),
            'province' => $viewer->isProvinceScoped() ? DB::table('provinces')->where('id', $viewer->province_id)->value('name') : null,
            'alerts' => $this->alerts($viewer),
            'activity' => $this->activity($viewer),
        ];
        if ($cycle === null) {
            return [...$base, 'alerts' => collect($base['alerts'])->filter(fn ($a) => $a['value'] > 0)->values()->all(), 'cycle' => null];
        }

        $members = $this->members($viewer, $cycle->id);
        $sales = $this->sales($cycle, $members);
        $spentBy = $sales->groupBy('household_id')->map(fn (Collection $s) => $s->sum(fn ($t) => (float) $t->voucher_amount));
        $balances = $this->balances($members);
        $closed = $cycle->status === 'CLOSED';
        foreach ($members as $m) {
            $m->spent = (float) $spentBy->get($m->household_id, 0);
            $m->bought = $spentBy->has($m->household_id);
            $m->unused = $closed ? (float) ($m->unused_amount ?? 0) : (float) $balances->get($m->household_id, 0);
        }
        $allocated = round($members->sum(fn ($m) => (float) $m->amount), 2);
        $used = round($sales->sum(fn ($t) => (float) $t->voucher_amount), 2);
        $notBought = $members->reject(fn ($m) => $m->bought);

        $timeline = $this->timeline($cycle);
        $closingIn = $timeline['days_left'];
        $unused = round($members->sum(fn ($m) => $m->unused), 2);
        $districts = $this->districts($viewer, $cycle, $members);
        $live = $this->liveFairs($districts, $members, $sales);

        $alerts = $base['alerts'];
        foreach ($live as $fair) {
            $when = match ($fair['days_left']) {
                0 => 'acaba hoje', 1 => 'acaba amanhã', default => "acaba dentro de {$fair['days_left']} dias"
            };
            $alerts[] = ['key' => "fair_missing_{$fair['district_id']}", 'level' => 'critical', 'label' => "Agregados de {$fair['district']} que ainda não compraram (a feira {$when})", 'value' => $fair['missing'], 'href' => "/admin/dashboard/faltosos?cycle={$cycle->id}&district={$fair['district_id']}"];
        }
        foreach ($districts as $d) {
            if ($d['fair_status'] === 'finished') {
                $alerts[] = ['key' => "fair_absent_{$d['district_id']}", 'level' => 'attention', 'label' => "Agregados de {$d['district']} que faltaram à feira", 'value' => $d['missing'], 'href' => "/admin/dashboard/faltosos?cycle={$cycle->id}&district={$d['district_id']}"];
            }
        }
        if (! $closed && $closingIn !== null && $closingIn <= self::CLOSING_SOON_DAYS && $unused > 0) {
            $alerts[] = ['key' => 'closing', 'level' => 'critical', 'label' => "O ciclo fecha em {$closingIn} dia(s) com saldo por usar", 'value' => $unused, 'money' => true, 'href' => '/admin/cycles'];
        }
        $communitiesBehind = $this->byCommunity($members)->filter(fn ($c) => $c['households'] > 0 && $c['not_bought'] / $c['households'] >= self::NOT_BOUGHT_ALERT_SHARE)->count();
        $alerts[] = ['key' => 'communities_behind', 'level' => 'attention', 'label' => 'Bairros com 30% ou mais sem compras', 'value' => $communitiesBehind, 'href' => '#sem-compras'];
        $order = ['critical' => 0, 'attention' => 1, 'info' => 2];
        $alerts = collect($alerts)->filter(fn ($a) => $a['value'] > 0)->sortBy(fn ($a) => $order[$a['level']])->values()->all();

        return [
            ...$base,
            'alerts' => $alerts,
            'cycle' => [
                'id' => $cycle->id, 'code' => $cycle->code, 'name' => $cycle->name, 'description' => $cycle->description, 'status' => $cycle->status,
                'starts_on' => $cycle->starts_on, 'ends_on' => $cycle->ends_on, 'closed_at' => $cycle->closed_at, ...$timeline,
            ],
            'districts' => $districts,
            'live_fairs' => $live,
            'kpis' => [
                'target' => collect($districts)->sum('target') ?: null,
                'households' => $members->count(),
                'active_cards' => $balances->count(),
                'allocated' => $allocated,
                'used' => $used,
                'used_share' => $allocated > 0 ? round($used / $allocated, 4) : 0,
                'unused' => $unused,
                'not_bought' => $notBought->count(),
                'not_bought_value' => round($notBought->sum(fn ($m) => $m->unused), 2),
                'unpaid' => round($sales->where('settlement', '!=', 'validated')->sum(fn ($t) => (float) $t->voucher_amount), 2),
                'copayment' => round($sales->sum(fn ($t) => (float) $t->copayment_amount), 2),
                'transactions' => $sales->count(),
            ],
            'usage' => $this->usage($cycle, $sales, $allocated, $cycles),
            'by_tier' => $members->groupBy('tier_id')->map(fn (Collection $g) => [
                'tier' => $g->first()->tier, 'amount' => (float) $g->first()->tier_amount, 'households' => $g->count(),
                'bought' => $g->where('bought', true)->count(), 'generated_codes' => $g->where('household_code_generated', 1)->count(),
                'allocated' => round($g->sum(fn ($m) => (float) $m->amount), 2), 'used' => round($g->sum('spent'), 2),
            ])->sortByDesc('amount')->values()->all(),
            'by_sex' => collect(['F', 'M'])->map(function (string $sex) use ($members) {
                $g = $members->where('representative_sex', $sex);

                return [
                    'sex' => $sex, 'households' => $g->count(), 'bought' => $g->where('bought', true)->count(),
                    'average_spent' => $g->where('bought', true)->count() > 0 ? round($g->where('bought', true)->avg('spent'), 2) : 0,
                ];
            })->all(),
            'substitutes' => DB::table('vouchers')->whereIn('id', $members->pluck('voucher_id')->filter()->values())->whereNotNull('received_by_name')->count(),
            'by_province' => $members->whereNotNull('province')->groupBy('province')->map(fn (Collection $g, string $province) => [
                'province' => $province, 'households' => $g->count(), 'with_card' => $g->count(),
                'transactions' => $sales->whereIn('household_id', $g->pluck('household_id'))->count(),
                'total_amount' => round($g->sum('spent'), 2),
            ])->values()->all(),
            'not_bought_by_community' => $this->byCommunity($members)->filter(fn ($c) => $c['not_bought'] > 0)
                ->sortByDesc(fn ($c) => [$c['not_bought'] / max($c['households'], 1), $c['not_bought']])->take(8)->values()->all(),
            'by_agrodealer' => $sales->groupBy('agrodealer_id')->map(fn (Collection $g) => [
                'name' => $g->first()->agrodealer, 'code' => $g->first()->agrodealer_code, 'transactions' => $g->count(),
                'voucher_amount' => round($g->sum(fn ($t) => (float) $t->voucher_amount), 2),
                'to_reconcile' => round($g->where('settlement', 'none')->sum(fn ($t) => (float) $t->voucher_amount), 2),
                'in_approval' => round($g->where('settlement', 'in_approval')->sum(fn ($t) => (float) $t->voucher_amount), 2),
                'validated' => round($g->where('settlement', 'validated')->sum(fn ($t) => (float) $t->voucher_amount), 2),
            ])->sortByDesc('voucher_amount')->values()->all(),
        ];
    }

    /**
     * Metas e feiras por distrito, com os agregados do ciclo e quantos já
     * compraram.
     *
     * @param  Collection<int, object>  $members
     * @return list<array<string, mixed>>
     */
    private function districts(User $viewer, object $cycle, Collection $members): array
    {
        $byDistrict = $members->groupBy('district_id');

        return collect($this->plans->forCycle($viewer, $cycle->id))->map(function (array $d) use ($byDistrict) {
            $g = $byDistrict->get($d['district_id'], collect());
            $bought = $g->where('bought', true)->count();

            return [
                'district_id' => $d['district_id'], 'district' => $d['district'], 'province' => $d['province'],
                'target' => $d['target_households'], 'households' => $g->count(), 'bought' => $bought, 'missing' => $g->count() - $bought,
                'fair_starts_on' => $d['fair_starts_on'], 'fair_ends_on' => $d['fair_ends_on'], 'fair_status' => $d['fair_status'],
            ];
        })->filter(fn ($d) => $d['households'] > 0 || $d['target'] !== null || $d['fair_starts_on'] !== null)->values()->all();
    }

    /**
     * Feiras a decorrer hoje: dia da feira, quem já comprou (ontem, hoje) e
     * compras por hora de hoje (hora de Moçambique).
     *
     * @param  list<array<string, mixed>>  $districts
     * @param  Collection<int, object>  $members
     * @param  Collection<int, object>  $sales
     * @return list<array<string, mixed>>
     */
    private function liveFairs(array $districts, Collection $members, Collection $sales): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $local = fn (object $t) => CarbonImmutable::parse($t->confirmed_at, 'UTC')->setTimezone(self::TIMEZONE);

        return collect($districts)->where('fair_status', 'ongoing')->map(function (array $d) use ($today, $local, $members, $sales) {
            $ids = $members->where('district_id', $d['district_id'])->pluck('household_id')->flip();
            $fairSales = $sales->filter(fn ($t) => $ids->has($t->household_id));
            $todaySales = $fairSales->filter(fn ($t) => $local($t)->isSameDay($today));
            $start = CarbonImmutable::parse($d['fair_starts_on'], self::TIMEZONE);
            $end = CarbonImmutable::parse($d['fair_ends_on'], self::TIMEZONE);
            $hours = array_fill(0, 24, 0);
            foreach ($todaySales as $t) {
                $hours[(int) $local($t)->format('G')]++;
            }

            return [
                ...$d,
                'day' => (int) $start->diffInDays($today) + 1,
                'days' => (int) $start->diffInDays($end) + 1,
                'days_left' => (int) $today->diffInDays($end),
                'bought_today' => $todaySales->pluck('household_id')->unique()->count(),
                'bought_yesterday' => $fairSales->filter(fn ($t) => $local($t)->isSameDay($today->subDay()))->pluck('household_id')->unique()->count(),
                'hours' => $hours,
            ];
        })->values()->all();
    }

    /**
     * Agregados do ciclo de um distrito que ainda não compraram (para a
     * mobilização durante a feira, ou quem faltou depois dela).
     *
     * @return Collection<int, object>
     */
    public function missing(User $viewer, string $cycleId, string $districtId): Collection
    {
        $cycle = DB::table('programme_cycles')->where('id', $cycleId)->first() ?? throw new DomainRuleException('CYCLE_NOT_FOUND');
        $members = $this->members($viewer, $cycle->id)->where('district_id', $districtId);
        $bought = $this->sales($cycle, $members)->pluck('household_id')->flip();

        return $members->reject(fn ($m) => $bought->has($m->household_id))->sortBy(fn ($m) => $m->community.'|'.$m->household_code)->values();
    }

    /**
     * Agregados do ciclo visíveis para quem vê, com escalão e localização.
     *
     * @return Collection<int, object>
     */
    private function members(User $viewer, string $cycleId): Collection
    {
        return $this->households->baseQuery($viewer)
            ->join('cycle_households as ch', 'ch.household_id', '=', 'h.id')
            ->join('voucher_tiers as vt', 'vt.id', '=', DB::raw('coalesce(ch.voucher_tier_id, h.voucher_tier_id)'))
            ->leftJoin('vouchers as v', 'v.id', '=', 'ch.voucher_id')
            ->where('ch.cycle_id', $cycleId)
            ->get([
                'ch.household_id', 'ch.amount', 'ch.voucher_id', 'ch.unused_amount', 'h.representative_sex', 'h.household_code_generated',
                'vt.id as tier_id', 'vt.name as tier', 'vt.amount as tier_amount', 'c.id as community_id', 'c.name as community',
                'd.id as district_id', 'd.name as district', 'p.name as province', 'h.household_code', 'h.representative_name', 'v.voucher_code',
            ]);
    }

    /**
     * Compras confirmadas dos agregados do ciclo dentro do período do ciclo,
     * com a situação na reconciliação: none, in_approval ou validated.
     *
     * @param  Collection<int, object>  $members
     * @return Collection<int, object>
     */
    private function sales(object $cycle, Collection $members): Collection
    {
        $ids = $members->pluck('household_id')->flip();

        return DB::table('transactions as t')
            ->join('cycle_households as ch', fn ($j) => $j->on('ch.household_id', '=', 't.household_id')->where('ch.cycle_id', $cycle->id))
            ->join('agrodealers as a', 'a.id', '=', 't.agrodealer_id')
            ->leftJoin('settlement_items as si', 'si.active_transaction_id', '=', 't.id')
            ->leftJoin('settlement_batches as sb', 'sb.id', '=', 'si.settlement_id')
            ->where('t.status', 'CONFIRMED')->where('t.confirmed_at', '>=', $cycle->opened_at)
            ->when($cycle->closed_at, fn ($q) => $q->where('t.confirmed_at', '<', $cycle->closed_at))
            ->get(['t.household_id', 't.agrodealer_id', 'a.name as agrodealer', 'a.code as agrodealer_code', 't.voucher_amount', 't.copayment_amount', 't.confirmed_at', 'sb.status as settlement_status'])
            ->filter(fn ($t) => $ids->has($t->household_id))
            ->map(function ($t) {
                $t->settlement = match ($t->settlement_status) {
                    null => 'none',
                    'VALIDATED' => 'validated',
                    default => 'in_approval',
                };

                return $t;
            })->values();
    }

    /**
     * Saldo actual por agregado com cartão activo (zero incluído).
     *
     * @param  Collection<int, object>  $members
     * @return Collection<string, float>
     */
    private function balances(Collection $members): Collection
    {
        $ids = $members->pluck('household_id')->flip();

        return DB::table('vouchers')->where('status', 'ACTIVE')
            ->whereIn('household_id', DB::table('cycle_households')->select('household_id'))
            ->groupBy('household_id')->selectRaw('household_id, sum(current_balance) as balance')->get()
            ->filter(fn ($v) => $ids->has($v->household_id))
            ->mapWithKeys(fn ($v) => [$v->household_id => (float) $v->balance]);
    }

    /**
     * Dia do ciclo, duração e dias que faltam (hora de Moçambique).
     *
     * @return array{day: int, total_days: int|null, days_left: int|null, time_share: float|null}
     */
    private function timeline(object $cycle): array
    {
        $start = CarbonImmutable::parse($cycle->starts_on ?? $cycle->opened_at, self::TIMEZONE)->startOfDay();
        $last = $cycle->closed_at
            ? CarbonImmutable::parse($cycle->closed_at, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay()
            : CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $day = max(1, (int) $start->diffInDays($last) + 1);
        $total = $cycle->ends_on ? max(1, (int) $start->diffInDays(CarbonImmutable::parse($cycle->ends_on, self::TIMEZONE)) + 1) : null;

        return [
            'day' => $total ? min($day, $total) : $day,
            'total_days' => $total,
            'days_left' => $total && ! $cycle->closed_at ? max(0, $total - $day) : null,
            'time_share' => $total ? round(min($day, $total) / $total, 4) : null,
        ];
    }

    /**
     * Valor usado acumulado, dia a dia, em percentagem do atribuído; e o do
     * ciclo anterior nos mesmos dias, para comparar o ritmo.
     *
     * @param  Collection<int, object>  $sales
     * @param  Collection<int, object>  $cycles
     * @return array{days: list<array{day: int, share: float}>, previous: array{code: string, name: string, days: list<array{day: int, share: float}>}|null}
     */
    private function usage(object $cycle, Collection $sales, float $allocated, Collection $cycles): array
    {
        $days = $this->cumulative($cycle, $sales, $allocated, $this->timeline($cycle)['day']);
        $previous = $cycles->filter(fn ($c) => $c->opened_at < $cycle->opened_at)->sortByDesc('opened_at')->first();
        if ($previous === null) {
            return ['days' => $days, 'previous' => null];
        }
        $prevSales = DB::table('transactions as t')
            ->join('cycle_households as ch', fn ($j) => $j->on('ch.household_id', '=', 't.household_id')->where('ch.cycle_id', $previous->id))
            ->where('t.status', 'CONFIRMED')->where('t.confirmed_at', '>=', $previous->opened_at)
            ->when($previous->closed_at, fn ($q) => $q->where('t.confirmed_at', '<', $previous->closed_at))
            ->get(['t.voucher_amount', 't.confirmed_at']);
        $prevAllocated = (float) DB::table('cycle_households')->where('cycle_id', $previous->id)->sum('amount');
        $prevDays = $this->timeline($previous)['day'];

        return ['days' => $days, 'previous' => ['code' => $previous->code, 'name' => $previous->name, 'days' => $this->cumulative($previous, $prevSales, $prevAllocated, $prevDays)]];
    }

    /**
     * @param  Collection<int, object>  $sales
     * @return list<array{day: int, share: float}>
     */
    private function cumulative(object $cycle, Collection $sales, float $allocated, int $days): array
    {
        $start = CarbonImmutable::parse($cycle->starts_on ?? $cycle->opened_at, self::TIMEZONE)->startOfDay();
        $byDay = $sales->groupBy(fn ($t) => (int) $start->diffInDays(CarbonImmutable::parse($t->confirmed_at, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay()) + 1)
            ->map(fn (Collection $g) => $g->sum(fn ($t) => (float) $t->voucher_amount));
        $total = 0.0;
        $points = [];
        for ($d = 1; $d <= $days; $d++) {
            $total += (float) $byDay->get($d, 0);
            $points[] = ['day' => $d, 'share' => $allocated > 0 ? round($total / $allocated, 4) : 0.0];
        }

        return $points;
    }

    /**
     * @param  Collection<int, object>  $members
     * @return Collection<int, array<string, mixed>>
     */
    private function byCommunity(Collection $members): Collection
    {
        return $members->groupBy('community_id')->map(fn (Collection $g) => [
            'community' => $g->first()->community, 'district' => $g->first()->district, 'households' => $g->count(),
            'not_bought' => $g->where('bought', false)->count(),
            'unused' => round($g->where('bought', false)->sum('unused'), 2),
        ])->values();
    }

    /**
     * O que precisa de acção, independentemente do ciclo escolhido.
     *
     * @return list<array<string, mixed>>
     */
    private function alerts(User $viewer): array
    {
        $batches = DB::table('card_batches as b')->join('communities as c', 'c.id', '=', 'b.community_id')
            ->join('districts as d', 'd.id', '=', 'c.district_id');
        $this->scope->apply($batches, $viewer, 'd.province_id');
        $toDeliver = (clone $batches)->join('vouchers as v', 'v.card_batch_id', '=', 'b.id')
            ->whereIn('b.status', ['PRINTED', 'IN_DISTRIBUTION'])->where('v.status', 'PENDING')->count();
        $notPrinted = (clone $batches)->where('b.status', 'GENERATED')->count();

        $settlements = DB::table('settlement_batches as s')->join('agrodealers as a', 'a.id', '=', 's.agrodealer_id')
            ->leftJoin('districts as ad', 'ad.id', '=', 'a.district_id');
        $this->scope->apply($settlements, $viewer, DB::raw('coalesce(a.province_id, ad.province_id)'));
        $returned = (clone $settlements)->whereIn('s.status', ['MANAGER_RETURNED', 'RETURNED'])->count();
        $waiting = (clone $settlements)->where('s.status', 'PENDING_MANAGER')->count();

        $withoutCard = $this->households->baseQuery($viewer)->where('h.status', 'ACTIVE')
            ->whereNotExists(fn ($q) => $q->from('vouchers as v')->whereColumn('v.household_id', 'h.id')->whereNotIn('v.status', ['REPLACED', 'CANCELLED', 'INVALIDATED']))
            ->count();

        return [
            ['key' => 'returned', 'level' => 'critical', 'label' => 'Reconciliações devolvidas para corrigir', 'value' => $returned, 'href' => '/admin/settlements'],
            ['key' => 'to_deliver', 'level' => 'attention', 'label' => 'Cartões impressos por entregar', 'value' => $toDeliver, 'href' => '/admin/cards'],
            ['key' => 'without_card', 'level' => 'attention', 'label' => 'Agregados activos sem cartão', 'value' => $withoutCard, 'href' => '/admin/cards'],
            ['key' => 'not_printed', 'level' => 'info', 'label' => 'Lotes gerados por imprimir', 'value' => $notPrinted, 'href' => '/admin/cards'],
            ['key' => 'waiting_manager', 'level' => 'info', 'label' => 'Reconciliações à espera do gestor MEAL', 'value' => $waiting, 'href' => '/admin/settlements'],
        ];
    }

    /**
     * Últimas acções no programa (importações, cartões, ciclos,
     * reconciliações). O Oficial MEAL vê as dos lotes e comerciantes da sua
     * província e as dos ciclos.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(User $viewer): array
    {
        $query = DB::table('audit_logs as l')->leftJoin('users as u', 'u.id', '=', 'l.actor_id')
            ->whereIn('l.action', ['HOUSEHOLDS_IMPORTED', 'CARD_BATCH_GENERATED', 'CARDS_DISTRIBUTED', 'CYCLE_OPENED', 'CYCLE_ALLOCATED', 'CYCLE_CLOSED', 'SETTLEMENT_CREATED', 'SETTLEMENT_APPROVED_BY_MANAGER', 'SETTLEMENT_VALIDATED', 'CYCLE_PLAN_UPDATED']);
        if ($viewer->isProvinceScoped()) {
            $province = $viewer->province_id;
            $query->where(fn ($q) => $q->whereIn('l.action', ['CYCLE_OPENED', 'CYCLE_ALLOCATED', 'CYCLE_CLOSED', 'CYCLE_PLAN_UPDATED'])
                ->orWhereIn('l.entity_id', DB::table('card_batches as b')->join('communities as c', 'c.id', '=', 'b.community_id')
                    ->join('districts as d', 'd.id', '=', 'c.district_id')->where('d.province_id', $province)->select('b.id'))
                ->orWhereIn('l.entity_id', DB::table('settlement_batches as s')->join('agrodealers as a', 'a.id', '=', 's.agrodealer_id')
                    ->leftJoin('districts as ad', 'ad.id', '=', 'a.district_id')->whereRaw('coalesce(a.province_id, ad.province_id) = ?', [$province])->select('s.id')));
        }

        return $query->orderByDesc('l.created_at')->orderByDesc('l.id')->limit(6)
            ->get(['l.action', 'l.metadata', 'l.created_at', 'u.full_name as actor'])
            ->map(function ($l) {
                $m = json_decode((string) $l->metadata, true) ?: [];

                return [
                    'action' => $l->action,
                    'text' => match ($l->action) {
                        'HOUSEHOLDS_IMPORTED' => ($m['created'] ?? 0).' agregados importados'.(isset($m['file_name']) ? " ({$m['file_name']})" : ''),
                        'CARD_BATCH_GENERATED' => "Lote {$m['batch_code']} gerado: ".($m['card_count'] ?? 0).' cartões',
                        'CARDS_DISTRIBUTED' => ($m['activated'] ?? 0)." cartões entregues ({$m['batch_code']})",
                        'CYCLE_OPENED' => "Ciclo {$m['code']} aberto",
                        'CYCLE_ALLOCATED' => ($m['allocated'] ?? 0)." agregados recarregados ({$m['code']})",
                        'CYCLE_CLOSED' => "Ciclo {$m['code']} fechado",
                        'CYCLE_PLAN_UPDATED' => isset($m['after']['fair_starts_on']) && $m['after']['fair_starts_on']
                            ? "Feira de {$m['district']} marcada: ".date('d/m', strtotime($m['after']['fair_starts_on'])).' a '.date('d/m', strtotime($m['after']['fair_ends_on']))
                            : "Meta de {$m['district']} actualizada",
                        'SETTLEMENT_CREATED' => 'Reconciliação criada'.(isset($m['settlement_code']) ? " ({$m['settlement_code']})" : ''),
                        'SETTLEMENT_APPROVED_BY_MANAGER' => 'Reconciliação aprovada pelo gestor MEAL'.(isset($m['settlement_code']) ? " ({$m['settlement_code']})" : ''),
                        default => 'Reconciliação validada pelas Finanças'.(isset($m['settlement_code']) ? " ({$m['settlement_code']})" : ''),
                    },
                    'at' => $l->created_at,
                    'actor' => $l->actor,
                ];
            })->all();
    }
}
