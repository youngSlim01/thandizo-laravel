<?php

namespace Database\Factories;

use App\Models\Community;
use App\Models\Locality;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Community>
 */
class CommunityFactory extends Factory
{
    /**
     * Comunidade com localidade; o distrito vem da cadeia da localidade.
     */
    public function definition(): array
    {
        return [
            'locality_id' => Locality::factory(),
            'district_id' => fn (array $attributes): string => Locality::query()
                ->findOrFail($attributes['locality_id'])
                ->administrativePost->district_id,
            'name' => 'Bairro '.fake()->unique()->numberBetween(1, 99999),
        ];
    }
}
