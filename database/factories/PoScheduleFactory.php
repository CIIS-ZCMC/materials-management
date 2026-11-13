<?php

namespace Database\Factories;

use App\Models\PoSchedule;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PoSchedule>
 */
class PoScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'scheduled_date' => Carbon::today()->addDays(fake()->numberBetween(1, 30)),
        ];
    }
}