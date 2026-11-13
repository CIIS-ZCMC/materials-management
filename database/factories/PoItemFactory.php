<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PoItem>
 */
class PoItemFactory extends Factory
{
    /**
     * By default the line is NOT linked to the catalog (like a BizBox line).
     * Use forItem() to copy a real catalog item.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'item_id' => null,
            'description' => fake()->words(3, true),
            'category_id' => ItemCategory::factory(),
            'unit_id' => Unit::factory(),
            'unit_price' => fake()->randomFloat(2, 15, 2500),
            'quantity_ordered' => fake()->numberBetween(1, 500),
        ];
    }

    public function forItem(Item $item): static
    {
        return $this->state(fn () => [
            'item_id' => $item->id,
            'description' => $item->name,
            'category_id' => $item->category_id,
            'unit_id' => $item->unit_id,
        ]);
    }
}