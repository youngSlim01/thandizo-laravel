<?php

namespace Database\Factories;

use App\Models\District;
use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<District>
 */
class DistrictFactory extends Factory
{
    public function definition(): array
    {
        return [
            'province_id' => Province::factory(),
            'name' => 'Distrito '.fake()->unique()->numberBetween(1, 99999),
            'programme_active' => false,
        ];
    }

    public function inProgramme(): static
    {
        return $this->state(fn () => ['programme_active' => true]);
    }
}
