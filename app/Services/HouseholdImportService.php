<?php

namespace App\Services;

use App\Models\AdministrativePost;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\District;
use App\Models\Household;
use App\Models\HouseholdImportBatch;
use App\Models\Locality;
use App\Models\Province;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Importação da lista MEAL de agregados (Excel lido no browser e enviado como
 * linhas). Substitui import_households (20261007195344, 20261008114600).
 *
 * Primeiro valida tudo e mostra o resumo (pré-visualização); só grava se
 * não houver nenhum erro, e então grava tudo de uma vez. Agregados cujo
 * CODIGO AF já existe ficam como estão.
 *
 * A coluna VALOR (opcional) diz o valor do voucher de cada agregado: o
 * agregado fica no escalão activo com esse valor. Sem valor, fica no escalão
 * padrão. Um valor sem escalão é um erro: os escalões criam-se primeiro na
 * Configuração, para um engano no ficheiro não criar dinheiro.
 */
class HouseholdImportService
{
    public const MAX_ROWS = 20000;

    private const MAX_ERRORS_SHOWN = 200;

    private const PLACE_LEVELS = ['province', 'district', 'administrative_post', 'locality', 'community'];

    public function __construct(private GeographyService $geography, private VoucherTierService $tiers) {}

    /**
     * @param  list<array<string, mixed>>  $rawRows
     * @return array<string, mixed> forma da antiga import_households
     */
    public function import(User $actor, array $rawRows, ?string $fileName, bool $commit): array
    {
        if ($rawRows === []) {
            throw new InvalidArgumentException('O ficheiro não tem linhas de agregados.');
        }
        if (count($rawRows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('O ficheiro tem mais de 20 000 linhas. Divida-o em ficheiros menores.');
        }

        $rows = array_map($this->parseRow(...), $rawRows, array_keys($rawRows));
        $rows = $this->assignTiers($rows);
        $errors = $this->validate($actor, $rows);
        $existingCodes = $this->existingCodes(array_column($rows, 'household_code'));
        $summary = $this->summary($rows, $existingCodes);
        $byTier = $this->byTier($rows, $existingCodes);

        if ($errors !== [] || ! $commit) {
            return [
                'valid' => $errors === [],
                'committed' => false,
                'error_count' => count($errors),
                'errors' => array_slice($errors, 0, self::MAX_ERRORS_SHOWN),
                'summary' => $summary,
                'by_tier' => $byTier,
                'existing_codes' => array_slice(array_values($existingCodes), 0, 50),
            ];
        }

        return DB::transaction(function () use ($actor, $rows, $fileName, $existingCodes, $summary, $byTier): array {
            $batch = HouseholdImportBatch::query()->create([
                'file_name' => $fileName,
                'imported_by' => $actor->id,
                'row_count' => count($rows),
                'created_households' => count($rows) - count($existingCodes),
                'existing_households' => count($existingCodes),
            ]);

            $communities = [];
            $now = now();
            $inserts = [];
            foreach ($rows as $row) {
                if (isset($existingCodes[$row['household_code']])) {
                    continue;
                }
                $key = $this->placeKey($row, 'community');
                $communities[$key] ??= $this->geography->resolveCommunity(array_intersect_key($row, array_flip(self::PLACE_LEVELS)))
                    ->load('locality', 'district');
                $community = $communities[$key];

                $inserts[] = [
                    'id' => (string) Str::uuid7(),
                    'household_code' => $row['household_code'],
                    'representative_name' => "{$row['first_name']} {$row['last_name']}",
                    'representative_first_name' => $row['first_name'],
                    'representative_last_name' => $row['last_name'],
                    'representative_sex' => $row['gender'],
                    'representative_birth_date' => $row['birth_date'],
                    'representative_relationship' => $row['relationship'],
                    'province_id' => $community->district->province_id,
                    'district_id' => $community->district_id,
                    'administrative_post_id' => $community->locality?->administrative_post_id,
                    'locality_id' => $community->locality_id,
                    'community_id' => $community->id,
                    'voucher_tier_id' => $row['voucher_tier_id'],
                    'status' => 'ACTIVE',
                    'import_batch_id' => $batch->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            // Em blocos de 500: 20 000 linhas gravam-se em segundos.
            foreach (array_chunk($inserts, 500) as $chunk) {
                DB::table('households')->insert($chunk);
            }
            $created = count($inserts);

            AuditLog::query()->create([
                'actor_id' => $actor->id,
                'action' => 'HOUSEHOLDS_IMPORTED',
                'entity_type' => 'HOUSEHOLD_IMPORT_BATCH',
                'entity_id' => $batch->id,
                'metadata' => ['file_name' => $fileName, 'summary' => $summary, 'by_tier' => $byTier, 'created' => $created],
            ]);

            return [
                'valid' => true,
                'committed' => true,
                'batch_id' => $batch->id,
                'error_count' => 0,
                'errors' => [],
                'summary' => [...$summary, 'new_households' => $created],
                'by_tier' => $byTier,
            ];
        });
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function parseRow(array $raw, int $index): array
    {
        $birth = HouseholdInput::date($raw['birth_date'] ?? null);

        return [
            'row' => (int) ($raw['row'] ?? $index + 2),
            'province' => HouseholdInput::clean($raw['province'] ?? null),
            'district' => HouseholdInput::clean($raw['district'] ?? null),
            'administrative_post' => HouseholdInput::clean($raw['administrative_post'] ?? null),
            'locality' => HouseholdInput::clean($raw['locality'] ?? null),
            'community' => HouseholdInput::clean($raw['community'] ?? null),
            'household_code' => HouseholdInput::householdCode($raw['household_code'] ?? ''),
            'first_name' => HouseholdInput::clean($raw['first_name'] ?? null),
            'last_name' => HouseholdInput::clean($raw['last_name'] ?? null),
            'gender' => HouseholdInput::gender($raw['gender'] ?? null),
            'birth_date' => $birth?->toDateString(),
            'birth_date_plausible' => $birth !== null && HouseholdInput::isPlausibleBirthDate($birth),
            'relationship' => HouseholdInput::clean($raw['relationship'] ?? null),
            'amount_text' => HouseholdInput::clean($raw['amount'] ?? null),
            'amount' => HouseholdInput::amount($raw['amount'] ?? null),
        ];
    }

    /**
     * Escalão de cada linha: o activo com o valor da coluna VALOR, ou o
     * padrão quando a linha não tem valor.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function assignTiers(array $rows): array
    {
        $default = $this->tiers->default();
        $byAmount = [];
        foreach ($this->tiers->list() as $tier) {
            if ($tier->status->value === 'ACTIVE') {
                $byAmount[number_format((float) $tier->amount, 2, '.', '')] = $tier;
            }
        }

        return array_map(function (array $row) use ($default, $byAmount): array {
            $tier = $row['amount_text'] === null ? $default : ($row['amount'] === null ? null : ($byAmount[number_format($row['amount'], 2, '.', '')] ?? null));

            return [...$row, 'voucher_tier_id' => $tier?->id, 'voucher_tier' => $tier?->name, 'voucher_amount' => $tier === null ? null : (float) $tier->amount];
        }, $rows);
    }

    /**
     * Agregados novos por escalão, com o valor a atribuir em cada ativação.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $existingCodes
     * @return list<array{name: string, amount: float, households: int}>
     */
    private function byTier(array $rows, array $existingCodes): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if ($row['voucher_tier_id'] === null || isset($existingCodes[$row['household_code']])) {
                continue;
            }
            $groups[$row['voucher_tier_id']] ??= ['name' => $row['voucher_tier'], 'amount' => $row['voucher_amount'], 'households' => 0];
            $groups[$row['voucher_tier_id']]['households']++;
        }
        usort($groups, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $groups;
    }

    /**
     * Todos os erros, por linha e coluna (códigos do sistema antigo, que o
     * ecrã traduz).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{row: int, field: string, code: string}>
     */
    private function validate(User $actor, array $rows): array
    {
        $required = [
            'province' => 'PROVINCIA', 'district' => 'DISTRITO', 'administrative_post' => 'P. ADMINISTRATIVO',
            'locality' => 'LOCALIDADE', 'community' => 'BAIRRO/POVOACAO', 'first_name' => 'NOME',
            'last_name' => 'APELIDO', 'relationship' => 'GRAU PARENTESCO',
        ];
        $copies = array_count_values(array_filter(array_column($rows, 'household_code')));
        $scopeProvince = $actor->isProvinceScoped()
            ? $this->key(Province::query()->whereKey($actor->province_id)->value('name'))
            : null;

        $errors = [];
        foreach ($rows as $row) {
            $add = function (string $field, string $code) use (&$errors, $row): void {
                $errors[] = ['row' => $row['row'], 'field' => $field, 'code' => $code];
            };
            foreach ($required as $key => $label) {
                if ($row[$key] === null) {
                    $add($label, 'REQUIRED');
                }
            }
            $code = $row['household_code'];
            if ($code === '') {
                $add('CODIGO AF', 'REQUIRED');
            } elseif (! HouseholdInput::isValidHouseholdCode($code)) {
                $add('CODIGO AF', 'INVALID_HOUSEHOLD_CODE');
            }
            if ($code !== '' && $copies[$code] > 1) {
                $add('CODIGO AF', 'DUPLICATE_IN_FILE');
            }
            if ($row['gender'] === null) {
                $add('GENERO', 'INVALID_GENDER');
            }
            if ($row['birth_date'] === null) {
                $add('DATA NASC', 'INVALID_DATE');
            } elseif (! $row['birth_date_plausible']) {
                $add('DATA NASC', 'DATE_OUT_OF_RANGE');
            }
            if ($row['amount_text'] !== null && $row['amount'] === null) {
                $add('VALOR', 'INVALID_AMOUNT');
            } elseif ($row['voucher_tier_id'] === null) {
                $add('VALOR', 'NO_TIER_FOR_AMOUNT');
            }
            if ($actor->isProvinceScoped() && $row['province'] !== null && $this->key($row['province']) !== $scopeProvince) {
                $add('PROVINCIA', 'PROVINCE_OUT_OF_SCOPE');
            }
        }

        usort($errors, fn (array $a, array $b): int => [$a['row'], $a['field']] <=> [$b['row'], $b['field']]);

        return $errors;
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, string> código => código, dos agregados que já existem
     */
    private function existingCodes(array $codes): array
    {
        $existing = [];
        foreach (array_chunk(array_values(array_unique(array_filter($codes))), 1000) as $chunk) {
            foreach (Household::query()->whereIn('household_code', $chunk)->orderBy('household_code')->pluck('household_code') as $code) {
                $existing[mb_strtoupper($code)] = $code;
            }
        }
        ksort($existing);

        return $existing;
    }

    /**
     * Agregados novos e geografia nova que a importação vai criar.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $existingCodes
     * @return array<string, int>
     */
    private function summary(array $rows, array $existingCodes): array
    {
        $summary = [
            'rows' => count($rows),
            'new_households' => count($rows) - count($existingCodes),
            'existing_households' => count($existingCodes),
        ];

        $models = [
            'province' => Province::class, 'district' => District::class, 'administrative_post' => AdministrativePost::class,
            'locality' => Locality::class, 'community' => Community::class,
        ];
        $countKeys = [
            'province' => 'new_provinces', 'district' => 'new_districts', 'administrative_post' => 'new_administrative_posts',
            'locality' => 'new_localities', 'community' => 'new_communities',
        ];
        $existingIds = [];
        foreach (self::PLACE_LEVELS as $depth => $level) {
            $new = 0;
            $seen = [];
            foreach ($rows as $row) {
                if ($row[$level] === null || in_array(null, array_intersect_key($row, array_flip(array_slice(self::PLACE_LEVELS, 0, $depth + 1))), true)) {
                    continue;
                }
                $key = $this->placeKey($row, $level);
                if (isset($seen[$key])) {
                    continue;
                }
                $parentId = $depth === 0 ? null : ($existingIds[$this->placeKey($row, self::PLACE_LEVELS[$depth - 1])] ?? false);
                $id = $parentId === false ? null : $this->findPlace($models[$level], $level, $row[$level], $parentId);
                $seen[$key] = true;
                if ($id === null) {
                    $new++;
                } else {
                    $existingIds[$key] = $id;
                }
            }
            $summary[$countKeys[$level]] = $new;
        }

        return $summary;
    }

    /**
     * @param  class-string  $model
     */
    private function findPlace(string $model, string $level, string $name, ?string $parentId): ?string
    {
        $parentColumn = ['district' => 'province_id', 'administrative_post' => 'district_id', 'locality' => 'administrative_post_id', 'community' => 'locality_id'][$level] ?? null;
        $query = $model::query()->where('name', $name);
        if ($parentColumn !== null) {
            $query->where($parentColumn, $parentId);
        }

        return $query->value('id');
    }

    /**
     * Chave de um lugar até ao nível dado, sem maiúsculas nem acentos (como a
     * collation do MySQL).
     *
     * @param  array<string, mixed>  $row
     */
    private function placeKey(array $row, string $level): string
    {
        $levels = array_slice(self::PLACE_LEVELS, 0, array_search($level, self::PLACE_LEVELS, true) + 1);

        return implode('|', array_map(fn (string $l): string => $this->key($row[$l]), $levels));
    }

    private function key(?string $name): string
    {
        return Str::lower(Str::ascii((string) $name));
    }
}
