<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Community;
use App\Models\Household;
use App\Models\HouseholdImportBatch;
use App\Models\Province;
use App\Models\User;
use App\Models\VoucherTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Equivalente PHP de import_households e de
 * supabase/tests/0009_household_import_tests.sql e 0027 (âmbito).
 */
class HouseholdImportTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(int $row, string $code, array $overrides = []): array
    {
        return [
            'row' => $row, 'province' => 'Tete', 'district' => 'Mutarara', 'administrative_post' => 'Posto Beta',
            'locality' => 'Localidade Gama', 'community' => 'Bairro Delta', 'household_code' => $code,
            'first_name' => 'Ana', 'last_name' => 'Silva', 'gender' => 'Feminino', 'birth_date' => '15/06/1985',
            'relationship' => 'Chefe', ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function import(array $rows, bool $commit, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? User::factory()->role(UserRole::MealManager)->create())
            ->postJson('/admin/households/import', ['rows' => $rows, 'file_name' => 'lista.xlsx', 'commit' => $commit]);
    }

    public function test_an_operator_cannot_import(): void
    {
        $this->import([$this->row(2, '9999AF000001')], false, User::factory()->role(UserRole::AgrodealerOperator)->create())
            ->assertForbidden();
    }

    public function test_an_empty_list_is_rejected(): void
    {
        $this->import([], false)->assertUnprocessable()->assertJsonValidationErrors(['rows' => 'O ficheiro não tem linhas de agregados.']);
    }

    public function test_every_problem_is_reported_by_row_and_column_and_nothing_is_saved_even_with_commit(): void
    {
        $response = $this->import([
            $this->row(2, '9999af000001'),
            $this->row(3, '9999AF000001'),
            $this->row(4, '99AF1', ['gender' => 'outro']),
            $this->row(5, '9999AF000004', ['birth_date' => '31/02/1985', 'locality' => '  ']),
            $this->row(6, '9999AF000005', ['birth_date' => '01/01/2999']),
            $this->row(7, '', ['first_name' => '']),
        ], true)->assertOk();

        $errors = collect($response->json('errors'))->map(fn ($e) => "{$e['row']} {$e['field']} {$e['code']}")->all();
        $this->assertSame([
            '2 CODIGO AF DUPLICATE_IN_FILE',
            '3 CODIGO AF DUPLICATE_IN_FILE',
            '4 CODIGO AF INVALID_HOUSEHOLD_CODE',
            '4 GENERO INVALID_GENDER',
            '5 DATA NASC INVALID_DATE',
            '5 LOCALIDADE REQUIRED',
            '6 DATA NASC DATE_OUT_OF_RANGE',
            '7 CODIGO AF REQUIRED',
            '7 NOME REQUIRED',
        ], $errors);
        $response->assertJson(['valid' => false, 'committed' => false, 'error_count' => 9]);
        $this->assertSame(0, Household::query()->count());
        $this->assertSame(0, Province::query()->count());
    }

    public function test_the_preview_counts_new_geography_ignoring_case_and_spaces_and_saves_nothing(): void
    {
        $this->import([
            $this->row(2, '9999AF000001'),
            $this->row(3, '9999AF000002', ['community' => 'bairro   delta']),
            $this->row(4, '9999AF000003', ['locality' => 'Localidade Epsilon']),
        ], false)->assertOk()->assertJson([
            'valid' => true, 'committed' => false,
            'summary' => [
                'rows' => 3, 'new_households' => 3, 'existing_households' => 0, 'new_provinces' => 1, 'new_districts' => 1,
                'new_administrative_posts' => 1, 'new_localities' => 2, 'new_communities' => 2,
            ],
        ]);

        $this->assertSame(0, Household::query()->count());
        $this->assertSame(0, Community::query()->count());
    }

    public function test_a_valid_list_is_committed_with_consistent_geography_batch_and_audit(): void
    {
        $manager = User::factory()->role(UserRole::MealManager)->create();

        $this->import([
            $this->row(2, '9999AF000001'),
            $this->row(3, '9999AF000002', ['community' => 'bairro   delta', 'gender' => 'm', 'birth_date' => '1990-01-02']),
            $this->row(4, '9999AF000003', ['locality' => 'Localidade Epsilon']),
        ], true, $manager)->assertOk()->assertJson(['committed' => true, 'summary' => ['new_households' => 3]]);

        $ana = Household::query()->where('household_code', '9999AF000001')->sole();
        $this->assertSame('Ana Silva', $ana->representative_name);
        $this->assertSame('F', $ana->representative_sex);
        $this->assertSame('1985-06-15', $ana->representative_birth_date->toDateString());
        $community = $ana->community()->with('locality.administrativePost')->first();
        $this->assertSame($community->locality_id, $ana->locality_id);
        $this->assertSame($community->locality->administrative_post_id, $ana->administrative_post_id);
        $this->assertSame($community->district_id, $ana->district_id);

        $this->assertSame($ana->community_id, Household::query()->where('household_code', '9999AF000002')->value('community_id'));
        $this->assertNotSame($ana->community_id, Household::query()->where('household_code', '9999AF000003')->value('community_id'));

        $batch = HouseholdImportBatch::query()->sole();
        $this->assertSame([$manager->id, 'lista.xlsx', 3, 3, 0], [$batch->imported_by, $batch->file_name, $batch->row_count, $batch->created_households, $batch->existing_households]);
        $this->assertSame('HOUSEHOLDS_IMPORTED', AuditLog::query()->sole()->action);
    }

    public function test_reimporting_creates_only_the_new_codes_and_leaves_existing_households_unchanged(): void
    {
        $this->import([$this->row(2, '9999AF000001')], true);

        $this->import([
            $this->row(2, '9999AF000001', ['first_name' => 'Outra']),
            $this->row(3, '9999AF000002'),
        ], false)->assertJson(['summary' => ['new_households' => 1, 'existing_households' => 1], 'existing_codes' => ['9999AF000001']]);

        $this->import([
            $this->row(2, '9999AF000001', ['first_name' => 'Outra']),
            $this->row(3, '9999AF000002'),
        ], true)->assertJson(['summary' => ['new_households' => 1]]);

        $this->assertSame('Ana', Household::query()->where('household_code', '9999AF000001')->value('representative_first_name'));
        $this->assertSame(2, Household::query()->count());
    }

    public function test_the_same_post_name_in_another_district_is_a_new_post(): void
    {
        $this->import([
            $this->row(2, '9999AF000001'),
            $this->row(3, '9999AF000002', ['district' => 'Moatize']),
        ], false)->assertJson(['summary' => ['new_districts' => 2, 'new_administrative_posts' => 2]]);
    }

    public function test_a_meal_officer_imports_only_rows_of_the_province(): void
    {
        $tete = Province::factory()->create(['name' => 'Tete']);
        $officer = User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $tete->id]);

        $response = $this->import([
            $this->row(2, '9999AF000001', ['province' => 'tete']),
            $this->row(3, '9999AF000002', ['province' => 'Manica']),
        ], true, $officer);

        $response->assertJson(['valid' => false, 'errors' => [['row' => 3, 'field' => 'PROVINCIA', 'code' => 'PROVINCE_OUT_OF_SCOPE']]]);
        $this->assertSame(0, Household::query()->count());
    }

    public function test_the_value_column_puts_each_household_in_the_tier_with_that_value(): void
    {
        VoucherTier::query()->create(['name' => 'Grupo A', 'amount' => 7500]);
        VoucherTier::query()->create(['name' => 'Grupo C', 'amount' => 3000]);

        $this->import([
            $this->row(2, '9999AF000001', ['amount' => '7.500']),
            $this->row(3, '9999AF000002', ['amount' => '3000 MT']),
            $this->row(4, '9999AF000003', ['amount' => '7500']),
            $this->row(5, '9999AF000004'),
        ], true)->assertJson(['committed' => true, 'by_tier' => [
            ['name' => 'Grupo A', 'amount' => 7500, 'households' => 2],
            ['name' => 'Padrão', 'amount' => 6000, 'households' => 1],
            ['name' => 'Grupo C', 'amount' => 3000, 'households' => 1],
        ]]);

        $tiers = Household::query()->join('voucher_tiers as t', 't.id', '=', 'households.voucher_tier_id')
            ->orderBy('household_code')->pluck('t.name')->all();
        $this->assertSame(['Grupo A', 'Grupo C', 'Grupo A', 'Padrão'], $tiers);
    }

    public function test_a_value_without_an_active_tier_is_an_error_and_nothing_is_saved(): void
    {
        VoucherTier::query()->create(['name' => 'Antigo', 'amount' => 4000, 'status' => 'INACTIVE']);

        $this->import([
            $this->row(2, '9999AF000001', ['amount' => '750']),
            $this->row(3, '9999AF000002', ['amount' => '4000']),
            $this->row(4, '9999AF000003', ['amount' => 'muito']),
        ], true)->assertJson(['valid' => false, 'errors' => [
            ['row' => 2, 'field' => 'VALOR', 'code' => 'NO_TIER_FOR_AMOUNT'],
            ['row' => 3, 'field' => 'VALOR', 'code' => 'NO_TIER_FOR_AMOUNT'],
            ['row' => 4, 'field' => 'VALOR', 'code' => 'INVALID_AMOUNT'],
        ]]);
        $this->assertSame(0, Household::query()->count());
    }
}
