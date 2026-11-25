<?php

namespace Database\Factories;

use App\Enums\DeliveryItemStatus;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\PoItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Makes only the ROW. It does not check any rule (complete delivery, over-delivery, same PO).
 * For a real record go through DeliveryItemService::record() or POST /deliveries/{id}/items.
 *
 * @extends Factory<DeliveryItem>
 */
class DeliveryItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'delivery_id' => Delivery::factory(),
            'po_item_id' => PoItem::factory(),
            'quantity' => '1.000',
            'delivered_on' => now()->toDateString(),
            'status' => DeliveryItemStatus::Active,
            'over_delivery' => false,
            'recorded_by' => User::factory(),
        ];
    }

    public function voided(string $reason = 'Entered by mistake'): static
    {
        return $this->state(fn () => [
            'status' => DeliveryItemStatus::Voided,
            'void_reason' => $reason,
            'voided_at' => now(),
            'voided_by' => User::factory(),
        ]);
    }
}