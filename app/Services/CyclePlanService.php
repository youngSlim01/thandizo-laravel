<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\DomainRuleException;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Planeamento do ciclo por distrito: meta de agregados e datas da feira.
 *
 * Regras (decididas a 9/11/2026):
 * - uma feira por distrito em cada ciclo, marcada pelo gestor MEAL ou pelo
 *   oficial MEAL (este só nos distritos da sua província);
 * - as datas ficam dentro das datas do ciclo;
 * - mudar as datas de uma feira que já começou (prolongar, marcar outra
 *   data) só o gestor MEAL ou o administrador, com motivo: é executar uma
 *   decisão da direcção do programa;
 * - só se vende nos dias da feira do distrito do comerciante (PosService);
 *   acabada a feira, o distrito fica travado e o saldo fica guardado.
 */
class CyclePlanService
{
    private const TIMEZONE = 'Africa/Maputo';

    public function __construct(private ProvinceScope $scope) {}

    /**
     * Distritos do programa visíveis para quem vê, com o planeamento no ciclo.
     *
     * @return list<array<string, mixed>>
     */
    public function forCycle(User $viewer, string $cycleId): array
    {
        $today = $this->today();
        $query = DB::table('districts as d')->join('provinces as p', 'p.id', '=', 'd.province_id')
            ->leftJoin('cycle_districts as cd', fn ($j) => $j->on('cd.district_id', '=', 'd.id')->where('cd.cycle_id', $cycleId))
            ->where('d.programme_active', true)
            ->orderBy('p.name')->orderBy('d.name');
        $this->scope->apply($query, $viewer, 'p.id');

        return $query->get(['d.id as district_id', 'd.name as district', 'p.name as province', 'cd.target_households', 'cd.fair_starts_on', 'cd.fair_ends_on'])
            ->map(fn (object $row): array => [
                ...(array) $row,
                'target_households' => $row->target_households === null ? null : (int) $row->target_households,
                'fair_status' => $this->fairStatus($row->fair_starts_on, $row->fair_ends_on, $today),
            ])->all();
    }

    /**
     * Grava a meta e as datas da feira de um distrito no ciclo aberto.
     *
     * @param  array{target_households?: mixed, fair_starts_on?: string|null, fair_ends_on?: string|null, reason?: string|null}  $data
     */
    public function save(User $actor, string $cycleId, string $districtId, array $data): void
    {
        DB::transaction(function () use ($actor, $cycleId, $districtId, $data): void {
            $cycle = DB::table('programme_cycles')->where('id', $cycleId)->lockForUpdate()->first() ?? throw new DomainRuleException('CYCLE_NOT_FOUND');
            if ($cycle->status !== 'OPEN') {
                throw new DomainRuleException('CYCLE_CLOSED');
            }
            $district = DB::table('districts')->where('id', $districtId)->where('programme_active', true)->first(['id', 'name', 'province_id']);
            if ($district === null || ! $this->scope->allows($actor, $district->province_id)) {
                throw new DomainRuleException('DISTRICT_NOT_FOUND');
            }

            $target = $data['target_households'] ?? null;
            if ($target !== null && $target !== '' && (! is_numeric($target) || (int) $target < 0 || (int) $target != $target)) {
                throw new DomainRuleException('PLAN_TARGET_INVALID');
            }
            $target = ($target === null || $target === '') ? null : (int) $target;
            $starts = ($data['fair_starts_on'] ?? null) ?: null;
            $ends = ($data['fair_ends_on'] ?? null) ?: null;
            if (($starts === null) !== ($ends === null)) {
                throw new DomainRuleException('FAIR_DATES_REQUIRED');
            }
            if ($starts !== null) {
                if ($ends < $starts) {
                    throw new DomainRuleException('INVALID_PERIOD');
                }
                if (($cycle->starts_on && $starts < $cycle->starts_on) || ($cycle->ends_on && $ends > $cycle->ends_on)) {
                    throw new DomainRuleException('FAIR_OUTSIDE_CYCLE');
                }
            }

            $current = DB::table('cycle_districts')->where('cycle_id', $cycleId)->where('district_id', $districtId)->lockForUpdate()->first();
            $datesChange = $current !== null && ($current->fair_starts_on !== $starts || $current->fair_ends_on !== $ends);
            $started = $current?->fair_starts_on !== null && $current->fair_starts_on <= $this->today()->toDateString();
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($datesChange && $started) {
                if (! in_array($actor->role, [UserRole::MealManager, UserRole::SystemAdmin], true)) {
                    throw new DomainRuleException('FAIR_STARTED_MANAGER_ONLY');
                }
                if ($reason === '') {
                    throw new DomainRuleException('FAIR_CHANGE_REASON_REQUIRED');
                }
            }

            $values = ['target_households' => $target, 'fair_starts_on' => $starts, 'fair_ends_on' => $ends, 'updated_by' => $actor->id, 'updated_at' => now()];
            if ($current === null) {
                DB::table('cycle_districts')->insert([...$values, 'id' => (string) Str::uuid7(), 'cycle_id' => $cycleId, 'district_id' => $districtId, 'created_at' => now()]);
            } else {
                DB::table('cycle_districts')->where('id', $current->id)->update($values);
            }
            Audit::log($actor->id, 'CYCLE_PLAN_UPDATED', 'CYCLE', $cycleId, [
                'district' => $district->name, 'reason' => $reason ?: null,
                'before' => $current ? ['target_households' => $current->target_households, 'fair_starts_on' => $current->fair_starts_on, 'fair_ends_on' => $current->fair_ends_on] : null,
                'after' => ['target_households' => $target, 'fair_starts_on' => $starts, 'fair_ends_on' => $ends],
            ]);
        });
    }

    /**
     * A venda só é possível nos dias da feira do distrito, no ciclo aberto.
     * Fora deles, a regra diz quando é a próxima feira, se houver.
     */
    public function requireFairToday(?string $districtId): void
    {
        $today = $this->today()->toDateString();
        $fair = DB::table('cycle_districts as cd')->join('programme_cycles as c', 'c.id', '=', 'cd.cycle_id')
            ->where('c.status', 'OPEN')->where('cd.district_id', $districtId)
            ->first(['cd.fair_starts_on', 'cd.fair_ends_on']);
        if ($fair?->fair_starts_on !== null && $fair->fair_starts_on <= $today && $fair->fair_ends_on >= $today) {
            return;
        }
        $next = $fair?->fair_starts_on !== null && $fair->fair_starts_on > $today
            ? 'A feira está marcada de '.$this->dayMonth($fair->fair_starts_on).' a '.$this->dayMonth($fair->fair_ends_on).'.'
            : 'O MEAL ainda não marcou a feira.';
        if ($fair?->fair_ends_on !== null && $fair->fair_ends_on < $today) {
            $next = 'A feira terminou a '.$this->dayMonth($fair->fair_ends_on).'.';
        }
        throw new DomainRuleException('FAIR_NOT_RUNNING', $next);
    }

    /**
     * Feira de hoje do distrito no ciclo aberto, ou a próxima, para o POS.
     *
     * @return array{starts_on: string, ends_on: string, status: string}|null
     */
    public function fairFor(?string $districtId): ?array
    {
        $fair = DB::table('cycle_districts as cd')->join('programme_cycles as c', 'c.id', '=', 'cd.cycle_id')
            ->where('c.status', 'OPEN')->where('cd.district_id', $districtId)->whereNotNull('cd.fair_starts_on')
            ->first(['cd.fair_starts_on', 'cd.fair_ends_on']);

        return $fair === null ? null : [
            'starts_on' => $fair->fair_starts_on, 'ends_on' => $fair->fair_ends_on,
            'status' => $this->fairStatus($fair->fair_starts_on, $fair->fair_ends_on, $this->today()),
        ];
    }

    /**
     * none | planned | ongoing | finished
     */
    public function fairStatus(?string $starts, ?string $ends, CarbonImmutable $today): string
    {
        $day = $today->toDateString();

        return match (true) {
            $starts === null => 'none',
            $day < $starts => 'planned',
            $day > $ends => 'finished',
            default => 'ongoing',
        };
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::TIMEZONE)->startOfDay();
    }

    private function dayMonth(string $date): string
    {
        return CarbonImmutable::parse($date)->locale('pt')->translatedFormat('j \d\e F');
    }
}
