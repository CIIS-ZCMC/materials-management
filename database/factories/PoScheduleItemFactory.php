<?php

namespace Database\Factories;

use App\Models\PoItem;
use App\Models\PoSchedule;
use App\Models\PoScheduleItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Note: the default schedule and line are created separately, so they belong to DIFFERENT POs.
 * Pass both ids (from the same PO) when a test needs a consistent split.
 *
 * @extends Factory<PoScheduleItem>
 */
class PoScheduleItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'po_schedule_id' => PoSchedule::factory(),
            'po_item_id' => PoItem::factory(),
            'quantity' => fake()->numberBetween(0, 100),
        ];
    }
}
