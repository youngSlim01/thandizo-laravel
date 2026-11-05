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
 * 20261008114600). Os dados de cartão (código, saldo, situação) entram com
 * o nível 7; até lá todos os agregados aparecem "Sem cartão".
 */
class HouseholdSearchService
{
    public const MAX_ROWS = 20000;

    /** @var list<string> */
    private const CARD_STATUSES = ['NONE', 'PENDING', 'ACTIVE', 'EMPTY'];

    /**
     * Agregados com a localização completa (registos antigos sem todos os
     * níveis completam-nos pela comunidade), limitados à província de um
     * Oficial MEAL.
     */
    public function baseQuery(User $viewer): Builder
    {
        $query = DB::table('households as h')
            ->leftJoin('communities as c', 'c.id', '=', 'h.community_id')
            ->leftJoin('localities as l', 'l.id', '=', DB::raw('coalesce(h.locality_id, c.locality_id)'))
            ->leftJoin('administrative_posts as a', 'a.id', '=', DB::raw('coalesce(h.administrative_post_id, l.administrative_post_id)'))
            ->leftJoin('districts as d', 'd.id', '=', DB::raw('coalesce(h.district_id, c.district_id)'))
            ->leftJoin('provinces as p', 'p.id', '=', DB::raw('coalesce(h.province_id, d.province_id)'));

        return $this->inScope($query, $viewer, 'p.id');
    }

    /**
     * O agregado está dentro do que a pessoa pode ver.
     */
    public function isVisible(User $viewer, string $householdId): bool
    {
        return $this->baseQuery($viewer)->where('h.id', $householdId)->exists();
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

        $query = $this->baseQuery($viewer);
        $this->applyFilters($query, $filters, $status);
        // Sem cartões ainda (nível 7): só "Sem cartão" tem agregados.
        if ($card !== null && $card !== 'NONE') {
            $query->whereRaw('1 = 0');
        }

        $summary = (clone $query)->selectRaw("
            count(*) as total,
            coalesce(sum(h.status = 'ACTIVE'), 0) as active,
            coalesce(sum(h.status <> 'ACTIVE'), 0) as inactive,
            coalesce(sum(h.representative_sex = 'F'), 0) as women,
            coalesce(sum(h.representative_sex = 'M'), 0) as men
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
            ->map(fn (object $row): array => [...(array) $row, 'voucher_code' => null, 'voucher_status' => null, 'current_balance' => null, 'card_status' => 'NONE'])
            ->all();

        $total = (int) $summary->total;

        return [
            'total' => $total,
            'summary' => [
                'active' => (int) $summary->active,
                'inactive' => (int) $summary->inactive,
                'women' => (int) $summary->women,
                'men' => (int) $summary->men,
                'with_card' => 0,
                'card_pending' => 0,
                'without_card' => $total,
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
                ->orWhere('h.representative_name', 'like', $like));
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
        ];
    }
}
