<?php

namespace Database\Factories;

use App\Enums\DeliveryStatus;
use App\Enums\IarSeriesType;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Makes only the delivery ROW. It does not reserve the IAR number in iar_numbers; for a full record
 * (number, signatories, audit) go through DeliveryService::create() or POST /deliveries.
 *
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'status' => DeliveryStatus::ForUploading,
            'invoice_no' => 'INV-'.fake()->unique()->numerify('######'),
            'iar_series' => IarSeriesType::Regular,
            'iar_number' => '26-'.str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'fund_cluster' => '01',
            'amount_cents' => fake()->numberBetween(100_000, 5_000_000),
            'date_received' => now()->toDateString(),
            'created_by' => User::factory(),
        ];
    }

    public function voided(string $reason = 'Entered by mistake'): static
    {
        return $this->state(fn () => [
            'status' => DeliveryStatus::Voided,
            'void_reason' => $reason,
            'voided_at' => now(),
            'voided_by' => User::factory(),
        ]);
    }
}
