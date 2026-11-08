<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Community;
use App\Models\Household;
use App\Models\VoucherTier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Household>
 */
class HouseholdFactory extends Factory
{
    /**
     * Agregado activo numa comunidade, com os cinco níveis de localização.
     */
    public function definition(): array
    {
        $first = fake()->firstName();
        $last = fake()->lastName();

        return [
            'household_code' => '2026AF'.str_pad((string) fake()->unique()->numberBetween(1, 999999), 6, '0', STR_PAD_LEFT),
            'representative_name' => "{$first} {$last}",
            'representative_first_name' => $first,
            'representative_last_name' => $last,
            'representative_sex' => fake()->randomElement(['F', 'M']),
            'representative_birth_date' => '1985-06-15',
            'representative_relationship' => 'Chefe',
            'community_id' => Community::factory(),
            'voucher_tier_id' => fn () => VoucherTier::query()->where('is_default', true)->value('id'),
            'status' => RecordStatus::Active,
        ];
    }

    /**
     * Copia a cadeia de localização da comunidade para o agregado.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Household $household): void {
            $community = Community::query()->with('locality.administrativePost.district')->find($household->community_id);
            if ($community === null) {
                return;
            }
            $post = $community->locality?->administrativePost;
            $household->forceFill([
                'locality_id' => $household->locality_id ?? $community->locality_id,
                'administrative_post_id' => $household->administrative_post_id ?? $post?->id,
                'district_id' => $household->district_id ?? $community->district_id,
                'province_id' => $household->province_id ?? $post?->district?->province_id,
            ]);
        });
    }

    public function inCommunity(Community $community): static
    {
        return $this->state(fn () => ['community_id' => $community->id]);
    }

    public function inTier(VoucherTier $tier): static
    {
        return $this->state(fn () => ['voucher_tier_id' => $tier->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => RecordStatus::Inactive, 'status_reason' => 'Mudou-se']);
    }
}
