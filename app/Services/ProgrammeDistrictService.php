<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\District;
use App\Models\Province;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Distritos onde o programa actua.
 *
 * Substitui as funções SQL list_programme_districts e add_programme_district
 * (supabase/migrations/20261008113800_district_prices_and_catalogues.sql).
 */
class ProgrammeDistrictService
{
    /**
     * Distritos do programa com a sua província, por ordem alfabética.
     *
     * O número de agrodealers por distrito volta quando existir o nível 3.
     *
     * @return Collection<int, District>
     */
    public function list(): Collection
    {
        return District::query()
            ->inProgramme()
            ->with('province:id,name')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'province_id', 'name']);
    }

    /**
     * Junta um distrito ao programa.
     *
     * A província e o distrito são procurados pelo nome sem diferenciar
     * maiúsculas (collation *_ci do MySQL). Se não existirem são criados; um
     * distrito que já existe fora do programa passa a fazer parte dele.
     */
    public function add(User $actor, string $provinceName, string $districtName): District
    {
        $provinceName = trim($provinceName);
        $districtName = trim($districtName);

        return DB::transaction(function () use ($actor, $provinceName, $districtName): District {
            $province = Province::query()->firstOrCreate(['name' => $provinceName]);

            $district = District::query()->firstOrNew([
                'province_id' => $province->id,
                'name' => $districtName,
            ]);
            $district->programme_active = true;
            $district->save();

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'PROGRAMME_DISTRICT_ADDED',
                'entity_type' => 'DISTRICT',
                'entity_id' => $district->id,
                'metadata' => ['province' => $provinceName, 'district' => $districtName],
            ]);

            return $district;
        });
    }
}
