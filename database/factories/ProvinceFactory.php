<?php

namespace Database\Factories;

use App\Models\Province;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Province>
 */
class ProvinceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Província '.fake()->unique()->numberBetween(1, 99999),
        ];
    }
}
