<?php

namespace App\Services;

use App\Enums\RecordStatus;
use App\Models\Agrodealer;
use App\Models\AgrodealerPos;
use App\Models\AuditLog;
use App\Models\District;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Agrodealers e os seus POS.
 *
 * Substitui save_agrodealer, save_pos, list_agrodealers_admin,
 * next_agrodealer_code, next_pos_code e district_card_prefix
 * (20261008052549, 20261008114300, 20261008114600). O catálogo de cada
 * agrodealer está em CatalogueService.
 */
class AgrodealerService
{
    /**
     * Agrodealers que o utilizador pode ver, activos primeiro e por nome, com
     * os POS, o distrito e o número de utilizadores activos.
     *
     * @return Collection<int, Agrodealer>
     */
    public function list(User $viewer): Collection
    {
        return Agrodealer::query()
            ->visibleTo($viewer)
            ->with([
                'district:id,name,province_id',
                'district.province:id,name',
                'province:id,name',
                'pos' => fn ($query) => $query->orderBy('pos_code'),
            ])
            ->withCount([
                'users as active_users_count' => fn ($query) => $query->where('status', RecordStatus::Active),
                'catalogue',
            ])
            ->orderBy('status')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Cria um agrodealer num distrito do programa com o código MER-<DIS>-NNN,
     * já com o seu terminal POS: cada comerciante vende do próprio telemóvel
     * e o terminal só regista de onde veio a venda (decidido a 9/11/2026).
     *
     * @param  array{name: string, district_id: string, address?: string|null, contact_phone?: string|null}  $data
     */
    public function create(User $actor, array $data): Agrodealer
    {
        return DB::transaction(function () use ($actor, $data): Agrodealer {
            $district = District::query()->inProgramme()->findOrFail($data['district_id']);

            $agrodealer = Agrodealer::query()->create([
                'code' => $this->nextAgrodealerCode($district),
                ...$this->agrodealerFields($data, $district),
                'status' => RecordStatus::Active,
            ]);

            $this->audit($actor, 'AGRODEALER_CREATED', 'AGRODEALER', $agrodealer->id, $this->agrodealerAudit($agrodealer));
            $this->createPos($actor, $agrodealer, null);

            return $agrodealer;
        });
    }

    /**
     * Altera os dados, o distrito e a situação. O código nunca muda, nem
     * quando o agrodealer passa para outro distrito.
     *
     * @param  array{name: string, district_id: string, address?: string|null, contact_phone?: string|null, status: string}  $data
     */
    public function update(User $actor, Agrodealer $agrodealer, array $data): Agrodealer
    {
        return DB::transaction(function () use ($actor, $agrodealer, $data): Agrodealer {
            $district = District::query()->inProgramme()->findOrFail($data['district_id']);

            $agrodealer->update([
                ...$this->agrodealerFields($data, $district),
                'status' => RecordStatus::from($data['status']),
            ]);

            $this->audit($actor, 'AGRODEALER_UPDATED', 'AGRODEALER', $agrodealer->id, $this->agrodealerAudit($agrodealer));

            return $agrodealer;
        });
    }

    /**
     * Cria um POS com o código POS-<agrodealer>-NN.
     */
    public function createPos(User $actor, Agrodealer $agrodealer, ?string $deviceLabel): AgrodealerPos
    {
        return DB::transaction(function () use ($actor, $agrodealer, $deviceLabel): AgrodealerPos {
            $pos = $agrodealer->pos()->create([
                'pos_code' => $this->nextPosCode($agrodealer),
                'device_label' => $this->nullIfBlank($deviceLabel),
                'status' => RecordStatus::Active,
            ]);

            $this->audit($actor, 'POS_CREATED', 'POS', $pos->id, $this->posAudit($pos));

            return $pos;
        });
    }

    /**
     * Altera o aparelho e a situação de um POS. O código e o agrodealer nunca
     * mudam: as vendas do POS pertencem-lhe.
     */
    public function updatePos(User $actor, AgrodealerPos $pos, ?string $deviceLabel, string $status): AgrodealerPos
    {
        return DB::transaction(function () use ($actor, $pos, $deviceLabel, $status): AgrodealerPos {
            $pos->update([
                'device_label' => $this->nullIfBlank($deviceLabel),
                'status' => RecordStatus::from($status),
            ]);

            $this->audit($actor, 'POS_UPDATED', 'POS', $pos->id, $this->posAudit($pos));

            return $pos;
        });
    }

    /**
     * Prefixo de 3 letras do distrito: sem acentos, só letras, em maiúsculas,
     * completado com X (Mutarara → MUT, Chókwè → CHO, Ka → KAX).
     */
    public function districtCodePrefix(string $districtName): string
    {
        $letters = preg_replace('/[^A-Z]/', '', Str::upper(Str::ascii($districtName)));

        return str_pad(substr($letters, 0, 3), 3, 'X');
    }

    private function nextAgrodealerCode(District $district): string
    {
        $prefix = 'MER-'.$this->districtCodePrefix($district->name).'-';

        return $prefix.str_pad((string) ($this->highestNumber('agrodealers', 'code', $prefix) + 1), 3, '0', STR_PAD_LEFT);
    }

    private function nextPosCode(Agrodealer $agrodealer): string
    {
        $prefix = 'POS-'.preg_replace('/^MER-/', '', Str::upper($agrodealer->code)).'-';

        return $prefix.str_pad((string) ($this->highestNumber('agrodealer_pos', 'pos_code', $prefix) + 1), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Maior número já usado depois do prefixo. O "for update" bloqueia esses
     * códigos até ao fim da transacção, para dois registos criados ao mesmo
     * tempo não receberem o mesmo número.
     */
    private function highestNumber(string $table, string $column, string $prefix): int
    {
        return DB::table($table)
            ->where($column, 'like', str_replace(['%', '_'], ['\%', '\_'], $prefix).'%')
            ->lockForUpdate()
            ->pluck($column)
            ->map(fn (string $code): string => substr($code, strlen($prefix)))
            ->filter(fn (string $number): bool => ctype_digit($number))
            ->map(fn (string $number): int => (int) $number)
            ->max() ?? 0;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, district_id: string, province_id: string, address: string|null, contact_phone: string|null}
     */
    private function agrodealerFields(array $data, District $district): array
    {
        return [
            'name' => trim($data['name']),
            'district_id' => $district->id,
            'province_id' => $district->province_id,
            'address' => $this->nullIfBlank($data['address'] ?? null),
            'contact_phone' => $this->nullIfBlank($data['contact_phone'] ?? null),
        ];
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<string, string|null>
     */
    private function agrodealerAudit(Agrodealer $agrodealer): array
    {
        return [
            'code' => $agrodealer->code,
            'name' => $agrodealer->name,
            'district_id' => $agrodealer->district_id,
            'status' => $agrodealer->status->value,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function posAudit(AgrodealerPos $pos): array
    {
        return [
            'pos_code' => $pos->pos_code,
            'agrodealer_id' => $pos->agrodealer_id,
            'status' => $pos->status->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(User $actor, string $action, string $entityType, string $entityId, array $metadata): void
    {
        AuditLog::query()->create([
            'actor_id' => $actor->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata,
        ]);
    }
}
