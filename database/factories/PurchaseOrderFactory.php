<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderSource;
use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\DueDate;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $poDate = Carbon::today()->subDays(fake()->numberBetween(5, 90));
        $emailed = $poDate->copy()->addDays(fake()->numberBetween(0, 3));
        $term = fake()->randomElement([7, 15, 15, 15, 30]);
        $sequence = fake()->unique()->numberBetween(1, 9999);

        return [
            'po_number' => $poDate->format('y-m').'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
            'supplier_id' => Supplier::factory(),
            'po_date' => $poDate,
            'emailed_date' => $emailed,
            'delivery_term_days' => $term,
            'due_date' => DueDate::fromTerm($emailed, $term),
            'source' => PurchaseOrderSource::Manual,
            'external_id' => null,
            'status' => PurchaseOrderStatus::Draft,
            'closed_at' => null,
        ];
    }

    public function withStatus(PurchaseOrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** Emailed on this date; the due date follows (term kept unless a new one is given). */
    public function emailed(CarbonInterface $date, ?int $term = null): static
    {
        return $this->state(function (array $attributes) use ($date, $term) {
            $term ??= $attributes['delivery_term_days'];

            return [
                'emailed_date' => $date,
                'delivery_term_days' => $term,
                'due_date' => DueDate::fromTerm($date, $term),
            ];
        });
    }

    /** Not emailed yet (a draft), so there is no due date. */
    public function withoutDueDate(): static
    {
        return $this->state(fn () => [
            'emailed_date' => null,
            'delivery_term_days' => null,
            'due_date' => null,
        ]);
    }

    public function fromBizBox(): static
    {
        return $this->state(fn () => [
            'source' => PurchaseOrderSource::BizBox,
            'external_id' => 'BB-'.fake()->unique()->numerify('######'),
        ]);
    }

    public function closed(): static
    {
        return $this->state(fn () => ['closed_at' => now()]);
    }
}