<?php

namespace Database\Factories;

use App\Models\AdministrativePost;
use App\Models\Locality;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Locality>
 */
class LocalityFactory extends Factory
{
    public function definition(): array
    {
        return [
            'administrative_post_id' => AdministrativePost::factory(),
            'name' => 'Localidade '.fake()->unique()->numberBetween(1, 99999),
        ];
    }
}
