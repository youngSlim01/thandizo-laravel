<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'PRD-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'name' => 'Produto '.fake()->unique()->numberBetween(1, 99999),
            'category' => 'Sementes',
            'unit' => 'kg',
            'status' => RecordStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => RecordStatus::Inactive]);
    }
}
