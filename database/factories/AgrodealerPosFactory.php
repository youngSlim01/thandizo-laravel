<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Agrodealer;
use App\Models\AgrodealerPos;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgrodealerPos>
 */
class AgrodealerPosFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agrodealer_id' => Agrodealer::factory(),
            'pos_code' => 'POS-TST-'.fake()->unique()->numberBetween(1, 99999),
            'device_label' => 'Tablet',
            'status' => RecordStatus::Active,
        ];
    }
}
