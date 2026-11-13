<?php

namespace Database\Factories;

use App\Models\PoExtension;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\DueDate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PoExtension>
 */
class PoExtensionFactory extends Factory
{
    /**
     * Note: this does NOT change the PO's due_date; it only creates the row.
     * User::factory() needs UserFactory to fill employee_no.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $old = Carbon::today()->addDays(fake()->numberBetween(1, 30));
        $days = fake()->randomElement([3, 5, 7, 8, 15]);

        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'extra_days' => $days,
            'reason' => fake()->sentence(),
            'recorded_by' => User::factory(),
            'approved_by' => fn (array $attributes) => $attributes['recorded_by'], // same person
            'approved_at' => now(),
            'old_due_date' => $old,
            'new_due_date' => DueDate::extend($old, $days),
        ];
    }
}