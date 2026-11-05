<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Limpeza dos dados de agregados, igual para a importação e para os
 * formulários (antigos private.import_clean, import_normalize_gender e
 * import_parse_date).
 */
class HouseholdInput
{
    /**
     * Espaços nas pontas retirados e repetidos juntos; vazio passa a null.
     */
    public static function clean(mixed $value): ?string
    {
        $value = Str::squish((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * CODIGO AF em maiúsculas e sem espaços ("2022 af 000123" → "2022AF000123").
     */
    public static function householdCode(mixed $value): string
    {
        return mb_strtoupper(str_replace(' ', '', (string) self::clean($value)));
    }

    public static function isValidHouseholdCode(string $code): bool
    {
        return preg_match('/^\d{4}AF\d{6}$/', $code) === 1;
    }

    /**
     * F/M a partir das formas usadas nas listas; null quando não se reconhece.
     */
    public static function gender(mixed $value): ?string
    {
        return match (mb_strtolower((string) self::clean($value))) {
            'f', 'feminino', 'mulher', 'female' => 'F',
            'm', 'masculino', 'homem', 'male' => 'M',
            default => null,
        };
    }

    /**
     * Data em aaaa-mm-dd ou dd/mm/aaaa (também dd-mm-aaaa); null quando não é
     * uma data válida.
     */
    public static function date(mixed $value): ?CarbonImmutable
    {
        $text = (string) self::clean($value);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $text, $m) === 1) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/^(\d{1,2})[\/-](\d{1,2})[\/-](\d{4})$/', $text, $m) === 1) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }

        return checkdate($month, $day, $year) ? CarbonImmutable::create($year, $month, $day) : null;
    }

    /**
     * Data de nascimento entre 1900 e hoje.
     */
    public static function isPlausibleBirthDate(CarbonImmutable $date): bool
    {
        return $date->year >= 1900 && $date->lessThanOrEqualTo(CarbonImmutable::today());
    }
}
