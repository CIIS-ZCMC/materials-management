<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\StatusTransition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_moves_to_uploaded_and_the_change_is_logged(): void
    {
        $po = PurchaseOrder::factory()->create();

        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);
        $this->assertTrue(StatusTransition::apply($po, 'status', PurchaseOrderStatus::Uploaded));
        $this->assertSame(PurchaseOrderStatus::Uploaded, $po->fresh()->status);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'status_changed',
            'subject_type' => $po->getMorphClass(),
            'subject_id' => $po->id,
        ]);
    }

    public function test_an_invalid_move_is_rejected_and_nothing_changes(): void
    {
        $po = PurchaseOrder::factory()->create(); // draft

        try {
            StatusTransition::apply($po, 'status', PurchaseOrderStatus::Verified);
            $this->fail('Draft to Verified must be refused.');
        } catch (InvalidStatusTransition) {
            // expected
        }

        $this->assertSame(PurchaseOrderStatus::Draft, $po->fresh()->status);
        $this->assertDatabaseMissing('activity_log', ['subject_id' => $po->id, 'event' => 'status_changed']);
    }

    public function test_escalating_needs_a_reason(): void
    {
        $po = PurchaseOrder::factory()->withStatus(PurchaseOrderStatus::Uploaded)->create();

        try {
            StatusTransition::apply($po, 'status', PurchaseOrderStatus::Escalated);
            $this->fail('A reason should be required.');
        } catch (InvalidStatusTransition) {
            // expected
        }

        $this->assertTrue(StatusTransition::apply($po, 'status', PurchaseOrderStatus::Escalated, reason: 'Wrong supplier'));
        $this->assertSame(PurchaseOrderStatus::Escalated, $po->fresh()->status);
    }

    public function test_search_matches_po_number_and_supplier_name(): void
    {
        $supplier = Supplier::factory()->create(['name' => 'Zebra Medical Trading']);
        $match = PurchaseOrder::factory()->create(['po_number' => '26-11-0777', 'supplier_id' => $supplier->id]);
        PurchaseOrder::factory()->create();

        $bySupplier = PurchaseOrder::query();
        $match->filterBySearch($bySupplier, 'Zebra');
        $this->assertSame([$match->id], $bySupplier->pluck('id')->all());

        $byNumber = PurchaseOrder::query();
        $match->filterBySearch($byNumber, '11-0777');
        $this->assertSame([$match->id], $byNumber->pluck('id')->all());
    }

    public function test_due_date_follows_the_term_and_is_empty_without_an_emailed_date(): void
    {
        config(['mms.delivery.term_counting' => 'calendar']);

        $po = PurchaseOrder::factory()->make(['emailed_date' => '2026-07-21', 'delivery_term_days' => 60]);
        $this->assertSame('2026-09-19', $po->calculateDueDate()->toDateString());

        $draft = PurchaseOrder::factory()->withoutDueDate()->make();
        $this->assertNull($draft->calculateDueDate());
    }

    public function test_closed_orders_leave_the_open_scope(): void
    {
        $open = PurchaseOrder::factory()->create();
        $closed = PurchaseOrder::factory()->closed()->create();

        $this->assertFalse($open->isClosed());
        $this->assertTrue($closed->isClosed());
        $this->assertSame([$open->id], PurchaseOrder::query()->open()->pluck('id')->all());
    }
}