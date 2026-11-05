<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Pesquisa de agregados e geografia dos filtros.
 *
 * Substitui search_households, list_household_geography,
 * private.household_province e private.in_scope_household (20261008114200,
 * 20261008114600). Cada linha traz o cartão vivo mais recente (código, situação, saldo).
 */
class HouseholdSearchService
{
    public const MAX_ROWS = 20000;

    /** Situação do cartão: sem cartão, por distribuir, activo com saldo, sem saldo. */
    private const CARD_STATUS = "case when v.id is null then 'NONE' when v.status = 'PENDING' then 'PENDING' when v.current_balance > 0 then 'ACTIVE' else 'EMPTY' end";

    /** @var list<string> */
    private const CARD_STATUSES = ['NONE', 'PENDING', 'ACTIVE', 'EMPTY'];

    /**
     * Agregados com a localização completa (registos antigos sem todos os
     * níveis completam-nos pela comunidade), limitados à província de um
     * Oficial MEAL.
     */
    public function baseQuery(User $viewer): Builder
    {
        return $this->inScope($this->locationQuery(), $viewer, 'p.id');
    }

    /**
     * Agregados com a localização completa, sem limite de província (para
     * quem já verificou a visibilidade).
     */
    public function locationQuery(): Builder
    {
        return DB::table('households as h')
            ->leftJoin('communities as c', 'c.id', '=', 'h.community_id')
            ->leftJoin('localities as l', 'l.id', '=', DB::raw('coalesce(h.locality_id, c.locality_id)'))
            ->leftJoin('administrative_posts as a', 'a.id', '=', DB::raw('coalesce(h.administrative_post_id, l.administrative_post_id)'))
            ->leftJoin('districts as d', 'd.id', '=', DB::raw('coalesce(h.district_id, c.district_id)'))
            ->leftJoin('provinces as p', 'p.id', '=', DB::raw('coalesce(h.province_id, d.province_id)'));
    }

    /**
     * O agregado está dentro do que a pessoa pode ver: na sua província, ou
     * (Oficial MEAL) comprou num agrodealer da sua província (antigo
     * private.household_visible).
     */
    public function isVisible(User $viewer, string $householdId): bool
    {
        if ($this->baseQuery($viewer)->where('h.id', $householdId)->exists()) {
            return true;
        }

        return $viewer->isProvinceScoped() && $viewer->province_id !== null && DB::table('transactions as t')
            ->join('agrodealers as a', 'a.id', '=', 't.agrodealer_id')
            ->leftJoin('districts as d', 'd.id', '=', 'a.district_id')
            ->where('t.household_id', $householdId)
            ->whereRaw('coalesce(a.province_id, d.province_id) = ?', [$viewer->province_id])
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $filters  search, status, card_status, sex e os ids de localização
     * @return array{total: int, summary: array<string, int>, rows: list<array<string, mixed>>}
     *
     * @throws InvalidArgumentException com a mensagem para o utilizador
     */
    public function search(User $viewer, array $filters, int $limit = 50, int $offset = 0, string $sort = 'code'): array
    {
        $status = $this->filled($filters, 'status');
        $card = $this->filled($filters, 'card_status');
        if ($status !== null && RecordStatus::tryFrom($status) === null) {
            throw new InvalidArgumentException('Estado do agregado inválido.');
        }
        if ($card !== null && ! in_array($card, self::CARD_STATUSES, true)) {
            throw new InvalidArgumentException('Situação do cartão inválida.');
        }
        if (! in_array($sort, ['code', 'name', 'location'], true)) {
            throw new InvalidArgumentException('Ordenação inválida.');
        }

        $query = $this->baseQuery($viewer)
            // O cartão vivo do agregado (há no máximo um).
            ->leftJoin('vouchers as v', fn ($join) => $join->on('v.household_id', '=', 'h.id')->whereNotIn('v.status', ['REPLACED', 'CANCELLED', 'INVALIDATED']));
        $this->applyFilters($query, $filters, $status);
        if ($card !== null) {
            $query->whereRaw(self::CARD_STATUS.' = ?', [$card]);
        }

        $summary = (clone $query)->selectRaw("
            count(*) as total,
            coalesce(sum(h.status = 'ACTIVE'), 0) as active,
            coalesce(sum(h.status <> 'ACTIVE'), 0) as inactive,
            coalesce(sum(h.representative_sex = 'F'), 0) as women,
            coalesce(sum(h.representative_sex = 'M'), 0) as men,
            coalesce(sum(".self::CARD_STATUS." in ('ACTIVE', 'EMPTY')), 0) as with_card,
            coalesce(sum(".self::CARD_STATUS." = 'PENDING'), 0) as card_pending,
            coalesce(sum(".self::CARD_STATUS." = 'NONE'), 0) as without_card
        ")->first();

        $sortKey = match ($sort) {
            'name' => 'h.representative_name',
            'location' => "concat_ws('|', p.name, d.name, a.name, l.name, c.name, h.household_code)",
            default => 'h.household_code',
        };

        $rows = $query
            ->select($this->rowColumns())
            ->orderByRaw($sortKey)
            ->orderBy('h.household_code')
            ->limit(min(max($limit, 1), self::MAX_ROWS))
            ->offset(max($offset, 0))
            ->get()
            ->map(fn (object $row): array => [...(array) $row, 'current_balance' => $row->current_balance === null ? null : (float) $row->current_balance])
            ->all();

        $total = (int) $summary->total;

        return [
            'total' => $total,
            'summary' => [
                'active' => (int) $summary->active,
                'inactive' => (int) $summary->inactive,
                'women' => (int) $summary->women,
                'men' => (int) $summary->men,
                'with_card' => (int) $summary->with_card,
                'card_pending' => (int) $summary->card_pending,
                'without_card' => (int) $summary->without_card,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Lugares para o filtro em cascata, com o número de agregados activos de
     * cada um, dentro da província de um Oficial MEAL.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    public function geography(User $viewer): array
    {
        $active = $this->baseQuery($viewer)->where('h.status', RecordStatus::Active->value);
        $counts = fn (string $column): array => (clone $active)
            ->selectRaw("{$column} as place_id, count(*) as n")
            ->groupByRaw($column)
            ->pluck('n', 'place_id')
            ->all();

        $byProvince = $counts('p.id');
        $byDistrict = $counts('d.id');
        $byPost = $counts('a.id');
        $byLocality = $counts('l.id');
        $byCommunity = $counts('c.id');

        $places = function (Builder $query, array $counts, array $extra): array {
            return $query->orderBy('x.name')->get()->map(fn (object $place): array => [
                'id' => $place->id,
                ...array_intersect_key((array) $place, array_flip($extra)),
                'name' => $place->name,
                'households' => (int) ($counts[$place->id] ?? 0),
            ])->all();
        };

        $provinces = $this->inScope(DB::table('provinces as x')->select('x.id', 'x.name'), $viewer, 'x.id');
        $districts = $this->inScope(DB::table('districts as x')->select('x.id', 'x.province_id', 'x.name'), $viewer, 'x.province_id');
        $posts = $this->inScope(
            DB::table('administrative_posts as x')->join('districts as xd', 'xd.id', '=', 'x.district_id')->select('x.id', 'x.district_id', 'x.name'),
            $viewer, 'xd.province_id',
        );
        $localities = $this->inScope(
            DB::table('localities as x')
                ->join('administrative_posts as xa', 'xa.id', '=', 'x.administrative_post_id')
                ->join('districts as xd', 'xd.id', '=', 'xa.district_id')
                ->select('x.id', 'x.administrative_post_id', 'x.name'),
            $viewer, 'xd.province_id',
        );
        $communities = $this->inScope(
            DB::table('communities as x')->join('districts as xd', 'xd.id', '=', 'x.district_id')->select('x.id', 'x.locality_id', 'x.district_id', 'x.name'),
            $viewer, 'xd.province_id',
        );

        return [
            'provinces' => $places($provinces, $byProvince, []),
            'districts' => $places($districts, $byDistrict, ['province_id']),
            'administrative_posts' => $places($posts, $byPost, ['district_id']),
            'localities' => $places($localities, $byLocality, ['administrative_post_id']),
            'communities' => $places($communities, $byCommunity, ['locality_id', 'district_id']),
        ];
    }

    /**
     * Um Oficial MEAL só vê a sua província; sem província não vê nada.
     */
    private function inScope(Builder $query, User $viewer, string $provinceColumn): Builder
    {
        if (! $viewer->isProvinceScoped()) {
            return $query;
        }

        return $viewer->province_id === null
            ? $query->whereRaw('1 = 0')
            : $query->where($provinceColumn, $viewer->province_id);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, ?string $status): void
    {
        if ($status !== null) {
            $query->where('h.status', $status);
        }
        if (($sex = $this->filled($filters, 'sex')) !== null) {
            $query->where('h.representative_sex', $sex);
        }
        $places = [
            'community_id' => 'h.community_id',
            'locality_id' => 'coalesce(h.locality_id, c.locality_id)',
            'administrative_post_id' => 'coalesce(h.administrative_post_id, l.administrative_post_id)',
            'district_id' => 'coalesce(h.district_id, c.district_id)',
            'province_id' => 'coalesce(h.province_id, d.province_id)',
        ];
        foreach ($places as $key => $column) {
            if (($id = $this->filled($filters, $key)) !== null) {
                $query->whereRaw("{$column} = ?", [$id]);
            }
        }
        if (($search = $this->filled($filters, 'search')) !== null) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search).'%';
            $query->where(fn (Builder $query) => $query
                ->where('h.household_code', 'like', $like)
                ->orWhere('h.representative_name', 'like', $like)
                ->orWhereIn('h.id', DB::table('vouchers')->where('voucher_code', 'like', $like)->select('household_id')));
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filled(array $filters, string $key): ?string
    {
        $value = trim((string) ($filters[$key] ?? ''));

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private function rowColumns(): array
    {
        return [
            'h.id', 'h.household_code', 'h.representative_name', 'h.representative_first_name', 'h.representative_last_name',
            'h.representative_sex', 'h.representative_birth_date', 'h.representative_relationship', 'h.status', 'h.status_reason', 'h.created_at',
            'c.id as community_id', 'c.name as community',
            'l.id as locality_id', 'l.name as locality',
            'a.id as administrative_post_id', 'a.name as administrative_post',
            'd.id as district_id', 'd.name as district',
            'p.id as province_id', 'p.name as province',
            'v.voucher_code', 'v.status as voucher_status', 'v.current_balance', DB::raw(self::CARD_STATUS.' as card_status'),
        ];
    }
}
