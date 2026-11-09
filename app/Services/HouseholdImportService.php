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
use App\Models\VoucherTier;
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
 *
 * Um CODIGO AF vazio só é aceite num escalão que não exige CODIGO AF: ao
 * gravar, o sistema gera o código (TZ-MUT-000001, definitivo) e devolve a
 * lista dos códigos gerados, para o MEAL actualizar a sua lista.
 */
class HouseholdImportService
{
    public const MAX_ROWS = 20000;

    private const MAX_ERRORS_SHOWN = 200;

    private const PLACE_LEVELS = ['province', 'district', 'administrative_post', 'locality', 'community'];

    public function __construct(
        private GeographyService $geography,
        private VoucherTierService $tiers,
        private HouseholdCodeGenerator $codes,
    ) {}

    /**
     * As linhas com VALOR vão para o escalão activo com esse valor; as linhas
     * sem valor (sem a coluna, ou com a célula vazia) vão para o escalão
     * escolhido no ecrã ($tierId). Sem escolha, nada se grava: o ecrã
     * pergunta o grupo (decidido a 9/11/2026; antes iam para o padrão).
     *
     * @param  list<array<string, mixed>>  $rawRows
     * @return array<string, mixed> forma da antiga import_households
     */
    public function import(User $actor, array $rawRows, ?string $fileName, bool $commit, ?string $tierId = null): array
    {
        if ($rawRows === []) {
            throw new InvalidArgumentException('O ficheiro não tem linhas de agregados.');
        }
        if (count($rawRows) > self::MAX_ROWS) {
            throw new InvalidArgumentException('O ficheiro tem mais de 20 000 linhas. Divida-o em ficheiros menores.');
        }

        $rows = array_map($this->parseRow(...), $rawRows, array_keys($rawRows));
        $chosen = null;
        if ($tierId !== null && $tierId !== '') {
            $chosen = $this->tiers->list()->first(fn ($tier) => $tier->id === $tierId && $tier->status->value === 'ACTIVE')
                ?? throw new InvalidArgumentException('Escolha um grupo activo.');
        }
        $rows = $this->assignTiers($rows, $chosen);
        $withoutAmount = count(array_filter($rows, fn (array $row): bool => $row['amount_text'] === null));
        $needsTier = $withoutAmount > 0 && $chosen === null;
        if ($needsTier && $commit) {
            throw new InvalidArgumentException("Escolha o grupo dos {$withoutAmount} agregado(s) sem valor no ficheiro.");
        }
        $existingCodes = $this->existingCodes(array_column($rows, 'household_code'));
        $errors = $this->validate($actor, $rows, $existingCodes);
        $summary = $this->summary($rows, $existingCodes);
        $byTier = $this->byTier($rows, $existingCodes);
        $duplicates = $this->possibleDuplicates($rows);

        if ($errors !== [] || ! $commit) {
            return [
                'valid' => $errors === [] && ! $needsTier,
                'rows_without_amount' => $withoutAmount,
                'needs_tier' => $needsTier,
                'committed' => false,
                'error_count' => count($errors),
                'errors' => array_slice($errors, 0, self::MAX_ERRORS_SHOWN),
                'summary' => $summary,
                'by_tier' => $byTier,
                'possible_duplicates' => $duplicates,
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
            $generated = [];
            foreach ($rows as $row) {
                if (isset($existingCodes[$row['household_code']])) {
                    continue;
                }
                $key = $this->placeKey($row, 'community');
                $communities[$key] ??= $this->geography->resolveCommunity(array_intersect_key($row, array_flip(self::PLACE_LEVELS)))
                    ->load('locality', 'district');
                $community = $communities[$key];
                $code = $row['household_code'];
                if ($code === '') {
                    $code = $this->codes->next($community->district->name);
                    $generated[] = ['row' => $row['row'], 'household_code' => $code, 'representative_name' => "{$row['first_name']} {$row['last_name']}"];
                }

                $inserts[] = [
                    'id' => (string) Str::uuid7(),
                    'household_code' => $code,
                    'household_code_generated' => $row['household_code'] === '',
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
                'metadata' => [
                    'file_name' => $fileName, 'summary' => $summary, 'by_tier' => $byTier, 'created' => $created,
                    'generated_codes' => array_column($generated, 'household_code'),
                ],
            ]);

            return [
                'valid' => true,
                'committed' => true,
                'batch_id' => $batch->id,
                'error_count' => 0,
                'errors' => [],
                'summary' => [...$summary, 'new_households' => $created],
                'by_tier' => $byTier,
                'generated_codes' => $generated,
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
     * escolhido no ecrã quando a linha não tem valor (null até se escolher).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function assignTiers(array $rows, ?VoucherTier $chosen): array
    {
        $byAmount = [];
        foreach ($this->tiers->list() as $tier) {
            if ($tier->status->value === 'ACTIVE') {
                $byAmount[number_format((float) $tier->amount, 2, '.', '')] = $tier;
            }
        }

        return array_map(function (array $row) use ($chosen, $byAmount): array {
            $tier = $row['amount_text'] === null ? $chosen : ($row['amount'] === null ? null : ($byAmount[number_format($row['amount'], 2, '.', '')] ?? null));

            return [
                ...$row, 'voucher_tier_id' => $tier?->id, 'voucher_tier' => $tier?->name,
                'voucher_amount' => $tier === null ? null : (float) $tier->amount,
                'generates_code' => $row['household_code'] === '' && $tier !== null && ! $tier->requires_household_code,
            ];
        }, $rows);
    }

    /**
     * Linhas sem CODIGO AF (vão receber um código gerado) que parecem ser um
     * agregado já registado, ou outra linha do ficheiro: mesmo nome, apelido,
     * data de nascimento e bairro. É um aviso, não um erro: o MEAL decide.
     * Evita criar a mesma pessoa duas vezes quando uma lista é reimportada
     * sem os códigos gerados.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{row: int, representative_name: string, matches: string}>
     */
    private function possibleDuplicates(array $rows): array
    {
        $person = fn (array $r): string => implode('|', array_map($this->key(...), [$r['first_name'], $r['last_name'], $r['birth_date'], $r['community']]));
        $candidates = array_filter($rows, fn (array $r): bool => $r['generates_code'] && $r['birth_date'] !== null);
        if ($candidates === []) {
            return [];
        }

        $registered = [];
        foreach (array_chunk(array_values(array_unique(array_column($candidates, 'birth_date'))), 500) as $dates) {
            DB::table('households as h')->join('communities as c', 'c.id', '=', 'h.community_id')
                ->whereIn('h.representative_birth_date', $dates)
                ->get(['h.household_code', 'h.representative_first_name as first_name', 'h.representative_last_name as last_name', 'h.representative_birth_date as birth_date', 'c.name as community'])
                ->each(function (object $h) use (&$registered, $person): void {
                    $registered[$person((array) $h)] ??= $h->household_code;
                });
        }

        $inFile = [];
        $found = [];
        foreach ($candidates as $row) {
            $key = $person($row);
            $match = isset($registered[$key]) ? "agregado já registado {$registered[$key]}" : (isset($inFile[$key]) ? "linha {$inFile[$key]} deste ficheiro" : null);
            $inFile[$key] ??= $row['row'];
            if ($match !== null) {
                $found[] = ['row' => $row['row'], 'representative_name' => "{$row['first_name']} {$row['last_name']}", 'matches' => $match];
            }
        }

        return $found;
    }

    /**
     * Agregados novos por escalão, com o valor a atribuir em cada ativação.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $existingCodes
     * @return list<array{name: string, amount: float, households: int, generated_codes: int}>
     */
    private function byTier(array $rows, array $existingCodes): array
    {
        $groups = [];
        foreach ($rows as $row) {
            if ($row['voucher_tier_id'] === null || isset($existingCodes[$row['household_code']])) {
                continue;
            }
            $groups[$row['voucher_tier_id']] ??= ['name' => $row['voucher_tier'], 'amount' => $row['voucher_amount'], 'households' => 0, 'generated_codes' => 0];
            $groups[$row['voucher_tier_id']]['households']++;
            $groups[$row['voucher_tier_id']]['generated_codes'] += $row['generates_code'] ? 1 : 0;
        }
        usort($groups, fn (array $a, array $b): int => $b['amount'] <=> $a['amount']);

        return $groups;
    }

    /**
     * Todos os erros, por linha e coluna (códigos do sistema antigo, que o
     * ecrã traduz).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $existingCodes
     * @return list<array{row: int, field: string, code: string}>
     */
    private function validate(User $actor, array $rows, array $existingCodes): array
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
            // Um código gerado só aparece numa lista reimportada: tem de existir.
            $code = $row['household_code'];
            if ($code === '' && ! $row['generates_code'] && $row['voucher_tier_id'] !== null) {
                $add('CODIGO AF', 'REQUIRED');
            } elseif ($code !== '' && ! HouseholdInput::isValidHouseholdCode($code)
                && ! (HouseholdCodeGenerator::isGenerated($code) && isset($existingCodes[$code]))) {
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
            } elseif ($row['amount_text'] !== null && $row['voucher_tier_id'] === null) {
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
            'generated_codes' => count(array_filter(array_column($rows, 'generates_code'))),
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
