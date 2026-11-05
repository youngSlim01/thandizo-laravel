<?php

namespace Tests\Unit;

use App\Services\HouseholdInput;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

/**
 * Limpeza dos dados das listas MEAL (antigos private.import_clean,
 * import_normalize_gender, import_parse_date).
 */
class HouseholdInputTest extends TestCase
{
    #[TestWith(['  Bairro   Delta ', 'Bairro Delta'])]
    #[TestWith(['   ', null])]
    public function test_clean(string $raw, ?string $clean): void
    {
        $this->assertSame($clean, HouseholdInput::clean($raw));
    }

    public function test_household_code_is_upper_case_without_spaces(): void
    {
        $this->assertSame('2022AF000123', HouseholdInput::householdCode(' 2022 af 000123 '));
        $this->assertTrue(HouseholdInput::isValidHouseholdCode('2022AF000123'));
        $this->assertFalse(HouseholdInput::isValidHouseholdCode('22AF000123'));
    }

    #[TestWith(['Feminino', 'F'])]
    #[TestWith(['mulher', 'F'])]
    #[TestWith(['M', 'M'])]
    #[TestWith(['Homem', 'M'])]
    #[TestWith(['outro', null])]
    public function test_gender(string $raw, ?string $gender): void
    {
        $this->assertSame($gender, HouseholdInput::gender($raw));
    }

    #[TestWith(['1985-06-15', '1985-06-15'])]
    #[TestWith(['15/06/1985', '1985-06-15'])]
    #[TestWith(['5-6-1985', '1985-06-05'])]
    #[TestWith(['31/02/1985', null])]
    #[TestWith(['ontem', null])]
    public function test_date(string $raw, ?string $date): void
    {
        $this->assertSame($date, HouseholdInput::date($raw)?->toDateString());
    }

    public function test_birth_date_between_1900_and_today(): void
    {
        $this->assertFalse(HouseholdInput::isPlausibleBirthDate(HouseholdInput::date('1899-12-31')));
        $this->assertFalse(HouseholdInput::isPlausibleBirthDate(HouseholdInput::date('2999-01-01')));
        $this->assertTrue(HouseholdInput::isPlausibleBirthDate(HouseholdInput::date('1950-01-01')));
    }
}
