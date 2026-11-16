<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Events\PoEscalated;
use App\Events\PoReturnedToReviewer;
use App\Events\PoVerified;
use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Step 3.6: verify, escalate, send back, the Admin queue and the History tab.
 *
 * Users are built here from the real roles (RolePermissionSeeder) and the PO Reviewer
 * permissions. If your other tests create users differently (factory fields), mirror
 * that in user() below.
 */
class PoReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    // ---- helpers -------------------------------------------------------------------------

    private function user(): User
    {
        return User::factory()->create();
    }

    /** PO Reviewer job: MMS Staff base plus the review permissions. */
    private function reviewer(): User
    {
        $user = $this->user();
        $user->assignRole('MMS Staff');
        $user->givePermissionTo(['po.review', 'po.escalate', 'extension.manage']);

        return $user;
    }

    private function admin(): User
    {
        $user = $this->user();
        $user->assignRole('Admin');

        return $user;
    }

    private function procurement(): User
    {
        $user = $this->user();
        $user->assignRole('Procurement');

        return $user;
    }

    private function staff(): User
    {
        $user = $this->user();
        $user->assignRole('MMS Staff');

        return $user;
    }

    private function po(string $status, array $attributes = []): PurchaseOrder
    {
        return PurchaseOrder::factory()->create(['status' => $status, ...$attributes]);
    }

    private function lastStatusEntry(PurchaseOrder $po): ?Activity
    {
        return Activity::query()
            ->where('subject_type', $po->getMorphClass())
            ->where('subject_id', $po->id)
            ->where('event', 'status_changed')
            ->latest('id')
            ->first();
    }

    // ---- verify --------------------------------------------------------------------------

    public function test_reviewer_verifies_an_uploaded_po_and_the_event_fires(): void
    {
        Event::fake([PoVerified::class]);

        $reviewer = $this->reviewer();
        $po = $this->po('uploaded');

        $this->actingAs($reviewer)
            ->post("/purchase-orders/{$po->id}/verify")
            ->assertRedirect("/purchase-orders/{$po->id}")
            ->assertSessionHas('success');

        $this->assertSame(PurchaseOrderStatus::Verified, $po->fresh()->status);

        Event::assertDispatched(PoVerified::class, fn (PoVerified $e) => $e->purchaseOrder->is($po) && $e->actor->is($reviewer));

        $entry = $this->lastStatusEntry($po);
        $this->assertNotNull($entry);
        $this->assertSame('po', $entry->log_name);
        $this->assertSame($reviewer->id, (int) $entry->causer_id);
        $this->assertSame('Verified', $entry->properties['new']['status']);
    }

    public function test_a_po_without_a_due_date_can_be_verified(): void
    {
        Event::fake([PoVerified::class]);

        $po = $this->po('uploaded', ['emailed_date' => null, 'due_date' => null]);

        $this->actingAs($this->reviewer())
            ->post("/purchase-orders/{$po->id}/verify")
            ->assertSessionHas('success');

        $this->assertSame(PurchaseOrderStatus::Verified, $po->fresh()->status);
        Event::assertDispatched(PoVerified::class);
    }

    // ---- escalate ------------------------------------------------------------------------

    public function test_escalating_needs_a_reason(): void
    {
        Event::fake([PoEscalated::class]);

        $po = $this->po('uploaded');

        $this->actingAs($this->reviewer())
            ->post("/purchase-orders/{$po->id}/escalate", ['reason' => '   '])
            ->assertSessionHasErrors('reason');

        $this->assertSame(PurchaseOrderStatus::Uploaded, $po->fresh()->status);
        Event::assertNotDispatched(PoEscalated::class);
        $this->assertNull($this->lastStatusEntry($po));
    }

    public function test_reviewer_escalates_with_remarks_and_it_is_audited(): void
    {
        Event::fake([PoEscalated::class]);

        $reviewer = $this->reviewer();
        $po = $this->po('uploaded');

        $this->actingAs($reviewer)
            ->post("/purchase-orders/{$po->id}/escalate", ['reason' => 'Wrong supplier on the PO'])
            ->assertRedirect("/purchase-orders/{$po->id}")
            ->assertSessionHas('success');

        $this->assertSame(PurchaseOrderStatus::Escalated, $po->fresh()->status);
        Event::assertDispatched(PoEscalated::class, fn (PoEscalated $e) => $e->reason === 'Wrong supplier on the PO');

        $entry = $this->lastStatusEntry($po);
        $this->assertSame('Wrong supplier on the PO', $entry->properties['reason']);
        $this->assertSame('Escalated to Admin', $entry->properties['new']['status']);
        $this->assertSame($reviewer->id, (int) $entry->causer_id);
    }

    // ---- send back -----------------------------------------------------------------------

    public function test_admin_opens_the_edit_form_of_an_escalated_po_and_sends_it_back(): void
    {
        Event::fake([PoReturnedToReviewer::class]);

        $admin = $this->admin();
        $po = $this->po('escalated');

        // The Admin fixes the details through the existing edit form (PoEditRule allows escalated).
        $this->actingAs($admin)->get("/purchase-orders/{$po->id}/edit")->assertOk();

        $this->post("/purchase-orders/{$po->id}/send-back", ['reason' => 'Supplier corrected'])
            ->assertRedirect("/purchase-orders/{$po->id}")
            ->assertSessionHas('success');

        $this->assertSame(PurchaseOrderStatus::Uploaded, $po->fresh()->status);
        Event::assertDispatched(PoReturnedToReviewer::class, fn (PoReturnedToReviewer $e) => $e->note === 'Supplier corrected' && $e->actor->is($admin));

        $entry = $this->lastStatusEntry($po);
        $this->assertSame('Supplier corrected', $entry->properties['reason']);
        $this->assertSame('Uploaded', $entry->properties['new']['status']);
        $this->assertSame($admin->id, (int) $entry->causer_id);
    }

    public function test_sending_back_needs_a_note(): void
    {
        $po = $this->po('escalated');

        $this->actingAs($this->admin())
            ->post("/purchase-orders/{$po->id}/send-back", ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(PurchaseOrderStatus::Escalated, $po->fresh()->status);
    }

    // ---- invalid moves -------------------------------------------------------------------

    public function test_invalid_moves_are_rejected_and_nothing_changes(): void
    {
        Event::fake([PoVerified::class, PoEscalated::class, PoReturnedToReviewer::class]);

        $reviewer = $this->reviewer();
        $admin = $this->admin();

        $draft = $this->po('draft');
        $verified = $this->po('verified');
        $escalated = $this->po('escalated');
        $uploaded = $this->po('uploaded');

        // draft -> verified
        $this->actingAs($reviewer)->post("/purchase-orders/{$draft->id}/verify")->assertSessionHasErrors('form');
        // verified -> escalated
        $this->actingAs($reviewer)->post("/purchase-orders/{$verified->id}/escalate", ['reason' => 'x'])->assertSessionHasErrors('form');
        // verified -> verified
        $this->actingAs($reviewer)->post("/purchase-orders/{$verified->id}/verify")->assertSessionHasErrors('form');
        // escalated -> verified (the Reviewer cannot skip the Admin)
        $this->actingAs($reviewer)->post("/purchase-orders/{$escalated->id}/verify")->assertSessionHasErrors('form');
        // uploaded -> uploaded (send back only works from escalated)
        $this->actingAs($admin)->post("/purchase-orders/{$uploaded->id}/send-back", ['reason' => 'x'])->assertSessionHasErrors('form');

        $this->assertSame(PurchaseOrderStatus::Draft, $draft->fresh()->status);
        $this->assertSame(PurchaseOrderStatus::Verified, $verified->fresh()->status);
        $this->assertSame(PurchaseOrderStatus::Escalated, $escalated->fresh()->status);
        $this->assertSame(PurchaseOrderStatus::Uploaded, $uploaded->fresh()->status);

        Event::assertNotDispatched(PoVerified::class);
        Event::assertNotDispatched(PoEscalated::class);
        Event::assertNotDispatched(PoReturnedToReviewer::class);
        $this->assertSame(0, Activity::query()->where('event', 'status_changed')->count());
    }

    // ---- permissions ---------------------------------------------------------------------

    public function test_people_without_the_permission_get_403(): void
    {
        $uploaded = $this->po('uploaded');
        $escalated = $this->po('escalated');

        // MMS Staff base: can view, cannot do anything here.
        $this->actingAs($this->staff());
        $this->post("/purchase-orders/{$uploaded->id}/verify")->assertForbidden();
        $this->post("/purchase-orders/{$uploaded->id}/escalate", ['reason' => 'x'])->assertForbidden();
        $this->post("/purchase-orders/{$escalated->id}/send-back", ['reason' => 'x'])->assertForbidden();

        // The Admin does not verify or escalate (answer 1).
        $this->actingAs($this->admin());
        $this->post("/purchase-orders/{$uploaded->id}/verify")->assertForbidden();
        $this->post("/purchase-orders/{$uploaded->id}/escalate", ['reason' => 'x'])->assertForbidden();

        // Procurement edits but does not review.
        $this->actingAs($this->procurement());
        $this->post("/purchase-orders/{$uploaded->id}/verify")->assertForbidden();

        // The PO Reviewer does not hold po.edit, so cannot send back.
        $this->actingAs($this->reviewer());
        $this->post("/purchase-orders/{$escalated->id}/send-back", ['reason' => 'x'])->assertForbidden();

        $this->assertSame(PurchaseOrderStatus::Uploaded, $uploaded->fresh()->status);
        $this->assertSame(PurchaseOrderStatus::Escalated, $escalated->fresh()->status);
        $this->assertSame(0, Activity::query()->where('event', 'status_changed')->count());
    }

    // ---- BizBox --------------------------------------------------------------------------

    public function test_an_escalated_bizbox_po_cannot_be_edited_or_sent_back(): void
    {
        Event::fake([PoReturnedToReviewer::class]);

        $admin = $this->admin();
        $po = $this->po('escalated', ['source' => 'bizbox', 'external_id' => 'BB-TEST-1']);

        $this->actingAs($admin)->get("/purchase-orders/{$po->id}/edit")->assertForbidden();

        $this->post("/purchase-orders/{$po->id}/send-back", ['reason' => 'Fixed in BizBox'])
            ->assertSessionHasErrors('form');

        $this->assertSame(PurchaseOrderStatus::Escalated, $po->fresh()->status);
        Event::assertNotDispatched(PoReturnedToReviewer::class);

        // The page tells the Admin why, and offers no Send back button.
        $this->get("/purchase-orders/{$po->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->component('purchase-orders/show')
                ->where('purchaseOrder.review.can_send_back', false)
                ->where('purchaseOrder.review.send_back_reason', fn ($reason) => str_contains((string) $reason, 'BizBox'))
                ->where('purchaseOrder.edit.allowed', false));
    }

    public function test_a_reviewer_can_still_escalate_a_bizbox_po(): void
    {
        Event::fake([PoEscalated::class]);

        $po = $this->po('uploaded', ['source' => 'bizbox', 'external_id' => 'BB-TEST-2']);

        $this->actingAs($this->reviewer())
            ->post("/purchase-orders/{$po->id}/escalate", ['reason' => 'Quantity is wrong in BizBox'])
            ->assertSessionHas('success');

        $this->assertSame(PurchaseOrderStatus::Escalated, $po->fresh()->status);
    }

    // ---- page data -----------------------------------------------------------------------

    public function test_buttons_follow_permission_and_status(): void
    {
        $uploaded = $this->po('uploaded');
        $escalated = $this->po('escalated');

        $this->actingAs($this->reviewer())
            ->get("/purchase-orders/{$uploaded->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrder.review.can_verify', true)
                ->where('purchaseOrder.review.can_escalate', true)
                ->where('purchaseOrder.review.can_send_back', false));

        $this->actingAs($this->admin())
            ->get("/purchase-orders/{$uploaded->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrder.review.can_verify', false)
                ->where('purchaseOrder.review.can_escalate', false)
                ->where('purchaseOrder.review.can_send_back', false)
                ->where('purchaseOrder.review.reason', null));

        $this->actingAs($this->admin())
            ->get("/purchase-orders/{$escalated->id}")
            ->assertInertia(fn (Assert $page) => $page->where('purchaseOrder.review.can_send_back', true));
    }

    // ---- history and the Admin queue -----------------------------------------------------

    public function test_history_tab_lists_every_step_newest_first(): void
    {
        $reviewer = $this->reviewer();
        $admin = $this->admin();
        $po = $this->po('uploaded');

        $this->actingAs($reviewer)->post("/purchase-orders/{$po->id}/escalate", ['reason' => 'Wrong supplier']);
        $this->actingAs($admin)->post("/purchase-orders/{$po->id}/send-back", ['reason' => 'Supplier corrected']);
        $this->actingAs($reviewer)->post("/purchase-orders/{$po->id}/verify");

        $this->actingAs($admin)
            ->get("/purchase-orders/{$po->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('purchaseOrder.history', 3)
                ->where('purchaseOrder.history.0.who', $reviewer->name)
                ->where('purchaseOrder.history.0.changes.0.new', 'Verified')
                ->where('purchaseOrder.history.1.who', $admin->name)
                ->where('purchaseOrder.history.1.reason', 'Supplier corrected')
                ->where('purchaseOrder.history.2.reason', 'Wrong supplier')
                ->where('purchaseOrder.history.2.changes.0.old', 'Uploaded')
                ->where('purchaseOrder.history.2.changes.0.new', 'Escalated to Admin'));
    }

    public function test_history_is_empty_for_a_po_without_audit_rows(): void
    {
        $po = $this->po('uploaded');

        $this->actingAs($this->admin())
            ->get("/purchase-orders/{$po->id}")
            ->assertInertia(fn (Assert $page) => $page->has('purchaseOrder.history', 0));
    }

    public function test_escalated_filter_shows_the_remarks_and_the_count(): void
    {
        $reviewer = $this->reviewer();
        $po = $this->po('uploaded');
        $seeded = $this->po('escalated'); // no audit row: "No remarks recorded"
        $this->po('uploaded');

        $this->actingAs($reviewer)->post("/purchase-orders/{$po->id}/escalate", ['reason' => 'Quantity is wrong']);

        $this->actingAs($this->admin())
            ->get('/purchase-orders?status=escalated')
            ->assertInertia(fn (Assert $page) => $page
                ->component('purchase-orders/index')
                ->where('escalatedCount', 2)
                ->has('purchaseOrders.data', 2)
                ->where('purchaseOrders.data', function ($rows) use ($po, $seeded, $reviewer) {
                    $rows = collect($rows)->keyBy('id');

                    return $rows[$po->id]['escalation']['reason'] === 'Quantity is wrong'
                        && $rows[$po->id]['escalation']['by'] === $reviewer->name
                        && $rows[$seeded->id]['escalation'] === null;
                }));
    }
}