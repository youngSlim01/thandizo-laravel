<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Community;
use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Equivalente PHP de search_households, get_household_detail e
 * list_household_geography (supabase/tests/0016, 0024 e 0027).
 */
class HouseholdSearchTest extends TestCase
{
    use RefreshDatabase;

    private Community $tete;

    private Community $manica;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tete = Community::factory()->create(['name' => 'Bairro Tete']);
        $this->manica = Community::factory()->create(['name' => 'Bairro Manica']);
        Household::factory()->inCommunity($this->tete)->create(['household_code' => '1111AF000002', 'representative_name' => 'Zita Mário', 'representative_sex' => 'F']);
        Household::factory()->inCommunity($this->tete)->create(['household_code' => '1111AF000001', 'representative_name' => 'Abel João', 'representative_sex' => 'M']);
        Household::factory()->inCommunity($this->manica)->inactive()->create(['household_code' => '2222AF000001', 'representative_name' => 'Carla Sitoe', 'representative_sex' => 'F']);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function search(array $body, ?User $user = null): TestResponse
    {
        return $this->actingAs($user ?? User::factory()->role(UserRole::MealManager)->create())
            ->postJson('/admin/households/search', $body);
    }

    private function officerOf(Community $community): User
    {
        return User::factory()->role(UserRole::MealOfficer)->create(['province_id' => $community->district->province_id]);
    }

    public function test_page_is_for_the_meal_back_office(): void
    {
        $this->actingAs(User::factory()->role(UserRole::FinanceOfficer)->create())->get('/admin/households')->assertForbidden();
        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create())->get('/admin/households')
            ->assertInertia(fn (Assert $page) => $page->component('admin/households')->where('canManage', false));
    }

    public function test_list_is_sorted_by_code_with_a_summary(): void
    {
        $this->search([])->assertOk()
            ->assertJsonPath('total', 3)
            ->assertJsonPath('rows.0.household_code', '1111AF000001')
            ->assertJsonPath('rows.0.community', 'Bairro Tete')
            ->assertJsonPath('rows.0.card_status', 'NONE')
            ->assertJsonPath('summary', ['active' => 2, 'inactive' => 1, 'women' => 2, 'men' => 1, 'with_card' => 0, 'card_pending' => 0, 'without_card' => 3]);
    }

    public function test_filters_combine_and_can_sort_by_name(): void
    {
        $this->search(['filters' => ['district_id' => $this->tete->district_id, 'sex' => 'F']])
            ->assertJsonPath('total', 1)->assertJsonPath('rows.0.representative_name', 'Zita Mário');
        $this->search(['filters' => ['status' => 'INACTIVE']])->assertJsonPath('total', 1);
        $this->search(['filters' => ['community_id' => $this->manica->id]])->assertJsonPath('rows.0.household_code', '2222AF000001');
        $this->search(['filters' => ['search' => 'mario']])->assertJsonPath('total', 1);
        $this->search(['sort' => 'name'])->assertJsonPath('rows.0.representative_name', 'Abel João');
        $this->search(['filters' => ['card_status' => 'ACTIVE']])->assertJsonPath('total', 0);
        $this->search(['filters' => ['status' => 'GONE']])->assertJsonValidationErrors(['message' => 'Estado do agregado inválido.']);
    }

    public function test_a_meal_officer_sees_only_the_province_and_others_are_not_found(): void
    {
        $officer = $this->officerOf($this->tete);
        $elsewhere = Household::query()->where('household_code', '2222AF000001')->sole();

        $this->search([], $officer)->assertJsonPath('total', 2);
        $this->actingAs($officer)->getJson("/admin/households/{$elsewhere->id}")->assertNotFound();
        $this->actingAs(User::factory()->role(UserRole::MealOfficer)->create(['province_id' => null]))
            ->postJson('/admin/households/search', [])->assertJsonPath('total', 0);
    }

    public function test_detail_shows_the_location_and_import(): void
    {
        $household = Household::query()->where('household_code', '1111AF000001')->sole();

        $this->actingAs(User::factory()->role(UserRole::MealManager)->create())
            ->getJson("/admin/households/{$household->id}")
            ->assertOk()
            ->assertJson(['household_code' => '1111AF000001', 'community' => 'Bairro Tete', 'status' => 'ACTIVE', 'import' => null, 'cards' => [], 'sales' => []]);
    }

    public function test_geography_counts_active_households_and_respects_the_province(): void
    {
        $manager = User::factory()->role(UserRole::MealManager)->create();
        $this->actingAs($manager)->getJson('/admin/households/geography')
            ->assertJsonFragment(['id' => $this->tete->id, 'name' => 'Bairro Tete', 'households' => 2])
            ->assertJsonFragment(['id' => $this->manica->id, 'name' => 'Bairro Manica', 'households' => 0]);

        $this->actingAs($this->officerOf($this->tete))->getJson('/admin/households/geography')
            ->assertJsonCount(1, 'provinces')
            ->assertJsonMissing(['name' => 'Bairro Manica']);
    }
}
