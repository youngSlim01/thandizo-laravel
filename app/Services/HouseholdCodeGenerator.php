<?php

namespace App\Services;

/**
 * CODIGO AF gerado pelo sistema para os agregados que não têm um, nos
 * escalões que não o exigem: TZ-<DIS>-NNNNNN (três letras do distrito e um
 * número de série único). Nunca se confunde nem colide com um código oficial
 * (1234AF000001) e nunca é substituído.
 */
class HouseholdCodeGenerator
{
    private const PATTERN = '/^TZ-[A-Z]{3}-\d{6}$/';

    public function __construct(private CodeSequence $sequences, private AgrodealerService $agrodealers) {}

    public function next(string $districtName): string
    {
        $serial = str_pad((string) $this->sequences->next('household_code'), 6, '0', STR_PAD_LEFT);

        return 'TZ-'.$this->agrodealers->districtCodePrefix($districtName).'-'.$serial;
    }

    public static function isGenerated(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }
}
