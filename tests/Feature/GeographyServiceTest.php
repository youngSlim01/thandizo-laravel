<?php

namespace Tests\Feature;

use App\Models\AdministrativePost;
use App\Models\Community;
use App\Models\District;
use App\Models\Locality;
use App\Models\Province;
use App\Services\GeographyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

/**
 * Equivalente PHP da parte geográfica de supabase/tests/0009_household_import_tests.sql.
 */
class GeographyServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array{province: string, district: string, administrative_post: string, locality: string, community: string}
     */
    private const NAMES = [
        'province' => 'Tete',
        'district' => 'Mutarara',
        'administrative_post' => 'Posto Beta',
        'locality' => 'Localidade Gama',
        'community' => 'Bairro Delta',
    ];

    private function resolve(array $overrides = []): Community
    {
        return app(GeographyService::class)->resolveCommunity([...self::NAMES, ...$overrides]);
    }

    public function test_creates_the_whole_chain_when_nothing_exists(): void
    {
        $community = $this->resolve();

        $locality = $community->locality;
        $post = $locality->administrativePost;
        $district = $post->district;

        $this->assertSame('Bairro Delta', $community->name);
        $this->assertSame('Localidade Gama', $locality->name);
        $this->assertSame('Posto Beta', $post->name);
        $this->assertSame('Mutarara', $district->name);
        $this->assertSame('Tete', $district->province->name);
        $this->assertSame($district->id, $community->district_id);
        $this->assertFalse($district->programme_active);
    }

    public function test_same_place_written_differently_is_one_community(): void
    {
        $first = $this->resolve();

        $second = $this->resolve([
            'province' => ' TETE ',
            'district' => 'mutarara',
            'administrative_post' => 'posto  beta',
            'locality' => 'Localidade Gamá',
            'community' => 'bairro   delta',
        ]);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, Province::query()->count());
        $this->assertSame(1, District::query()->count());
        $this->assertSame(1, AdministrativePost::query()->count());
        $this->assertSame(1, Locality::query()->count());
        $this->assertSame(1, Community::query()->count());
    }

    public function test_existing_programme_district_is_reused_and_stays_in_the_programme(): void
    {
        $tete = Province::factory()->create(['name' => 'Tete']);
        $mutarara = District::factory()->inProgramme()->for($tete)->create(['name' => 'Mutarara']);

        $community = $this->resolve();

        $this->assertSame($mutarara->id, $community->district_id);
        $this->assertTrue($mutarara->fresh()->programme_active);
    }

    public function test_same_bairro_name_in_another_locality_is_another_community(): void
    {
        $first = $this->resolve();
        $second = $this->resolve(['locality' => 'Localidade Epsilon']);

        $this->assertFalse($first->is($second));
        $this->assertSame(2, Locality::query()->count());
        $this->assertSame(1, AdministrativePost::query()->count());
    }

    #[TestWith(['province'])]
    #[TestWith(['district'])]
    #[TestWith(['administrative_post'])]
    #[TestWith(['locality'])]
    #[TestWith(['community'])]
    public function test_every_level_is_required(string $level): void
    {
        try {
            $this->resolve([$level => '   ']);
            $this->fail('Devia recusar um nome vazio.');
        } catch (InvalidArgumentException) {
            $this->assertSame(0, Province::query()->count());
        }
    }

    public function test_bairro_name_is_unique_within_a_locality(): void
    {
        $community = Community::factory()->create(['name' => 'Bairro Delta']);

        $this->expectException(QueryException::class);
        Community::query()->create([
            'district_id' => $community->district_id,
            'locality_id' => $community->locality_id,
            'name' => 'bairro délta',
        ]);
    }

    public function test_old_communities_without_locality_are_unique_per_district(): void
    {
        $district = District::factory()->create();
        Community::query()->create(['district_id' => $district->id, 'name' => 'Bairro Antigo']);

        $this->expectException(QueryException::class);
        Community::query()->create(['district_id' => $district->id, 'name' => 'BAIRRO ANTIGO']);
    }

    public function test_old_community_name_does_not_block_a_new_one_with_locality(): void
    {
        $locality = Locality::factory()->create();
        $districtId = $locality->administrativePost->district_id;
        Community::query()->create(['district_id' => $districtId, 'name' => 'Bairro Antigo']);

        Community::query()->create(['district_id' => $districtId, 'locality_id' => $locality->id, 'name' => 'Bairro Antigo']);

        $this->assertSame(2, Community::query()->count());
    }
}
