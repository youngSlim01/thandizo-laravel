<?php

namespace Database\Factories;

use App\Models\AdministrativePost;
use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdministrativePost>
 */
class AdministrativePostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'district_id' => District::factory(),
            'name' => 'Posto '.fake()->unique()->numberBetween(1, 99999),
        ];
    }
}
