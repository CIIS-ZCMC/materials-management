<?php

namespace Database\Factories;

use App\Models\ItemCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ItemCategory>
 */
class ItemCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)).' Supplies',
            'is_equipment' => false,
            'is_active' => true,
        ];
    }

    public function equipment(): static
    {
        return $this->state(['is_equipment' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
