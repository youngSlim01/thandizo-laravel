<?php

namespace Database\Factories;

use App\Enums\RecordStatus;
use App\Models\Agrodealer;
use App\Models\District;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agrodealer>
 */
class AgrodealerFactory extends Factory
{
    /**
     * Agrodealer num distrito do programa; a província vem do distrito.
     */
    public function definition(): array
    {
        return [
            'code' => 'MER-TST-'.str_pad((string) fake()->unique()->numberBetween(1, 999), 3, '0', STR_PAD_LEFT),
            'name' => 'Agro '.fake()->unique()->numberBetween(1, 99999),
            'district_id' => District::factory()->inProgramme(),
            'province_id' => fn (array $attributes): string => District::query()->findOrFail($attributes['district_id'])->province_id,
            'status' => RecordStatus::Active,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['status' => RecordStatus::Inactive]);
    }
}
