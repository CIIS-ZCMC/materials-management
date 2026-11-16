<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchaseOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseOrderScheduleLimitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<string>  $dates
     * @return array<string, mixed>
     */
    private function data(int $quantity, array $dates, string $number = 'PO-LIMIT-1'): array
    {
        return [
            'po_number' => $number,
            'supplier_id' => Supplier::factory()->create()->id,
            'po_date' => '2026-11-16',
            'emailed_date' => null,
            'delivery_term_days' => null,
            'items' => [[
                'id' => null,
                'item_id' => Item::factory()->create()->id,
                'description' => 'Test item',
                'unit_price' => '10.00',
                'quantity_ordered' => $quantity,
            ]],
            'schedule_dates' => $dates,
        ];
    }

    public function test_too_many_dates_for_the_quantity_is_rejected_and_nothing_is_saved(): void
    {
        try {
            app(PurchaseOrderService::class)->create($this->data(1, ['2027-01-01', '2027-02-01', '2027-03-01']));
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('schedule_dates', $e->errors());
        }

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_one_unit_allows_exactly_one_date(): void
    {
        $po = app(PurchaseOrderService::class)->create($this->data(1, ['2027-01-01']));

        $this->assertCount(1, $po->schedules);
    }

    public function test_dates_equal_to_total_quantity_are_accepted(): void
    {
        $po = app(PurchaseOrderService::class)->create($this->data(3, ['2027-01-01', '2027-02-01', '2027-03-01']));

        $this->assertCount(3, $po->schedules);
    }

    public function test_a_po_without_dates_is_accepted(): void
    {
        $po = app(PurchaseOrderService::class)->create($this->data(1, []));

        $this->assertCount(0, $po->schedules);
    }

    public function test_edit_rejects_too_many_dates(): void
    {
        $service = app(PurchaseOrderService::class);
        $po = $service->create($this->data(5, ['2027-01-01']));

        $update = $this->data(1, ['2027-01-01', '2027-02-01']);
        $update['po_number'] = $po->po_number;
        $update['supplier_id'] = $po->supplier_id;
        $update['items'][0]['id'] = $po->items()->first()->id;
        $update['items'][0]['item_id'] = $po->items()->first()->item_id;

        $this->expectException(ValidationException::class);

        $service->update($po, $update);
    }
}