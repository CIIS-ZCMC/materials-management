<?php

namespace Tests\Feature;

use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Enums\PurchaseOrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseOrderListTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $canView = true): User
    {
        Permission::findOrCreate('po.view', 'web');

        $user = User::factory()->create(['employee_no' => 'TEST-0001', 'is_active' => true]);

        if ($canView) {
            $user->givePermissionTo('po.view');
        }

        return $user;
    }

    public function test_a_user_without_po_view_gets_403(): void
    {
        $this->actingAs($this->user(canView: false))
            ->get('/purchase-orders')
            ->assertForbidden();
    }

    public function test_search_matches_po_number_and_supplier_name(): void
    {
        $zebra = Supplier::factory()->create(['name' => 'Zebra Medical Trading']);
        PurchaseOrder::factory()->create(['po_number' => '26-11-0777', 'supplier_id' => $zebra->id]);
        PurchaseOrder::factory()->create(['po_number' => '26-11-0888']);

        $this->actingAs($this->user());

        $this->get('/purchase-orders?search=Zebra')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('purchase-orders/index')
                ->has('purchaseOrders.data', 1)
                ->where('purchaseOrders.data.0.po_number', '26-11-0777'));

        $this->get('/purchase-orders?search=11-0888')
            ->assertInertia(fn (Assert $page) => $page
                ->has('purchaseOrders.data', 1)
                ->where('purchaseOrders.data.0.po_number', '26-11-0888'));
    }

    public function test_status_filter_keeps_only_that_status(): void
    {
        PurchaseOrder::factory()->withStatus(PurchaseOrderStatus::Verified)->create(['po_number' => 'V-1']);
        PurchaseOrder::factory()->withStatus(PurchaseOrderStatus::Draft)->create(['po_number' => 'D-1']);

        $this->actingAs($this->user())
            ->get('/purchase-orders?status=verified')
            ->assertInertia(fn (Assert $page) => $page
                ->has('purchaseOrders.data', 1)
                ->where('purchaseOrders.data.0.po_number', 'V-1')
                ->where('filters.status', 'verified'));
    }

    public function test_supplier_filter_works_for_an_inactive_supplier(): void
    {
        $inactive = Supplier::factory()->create(['is_active' => false]);
        PurchaseOrder::factory()->create(['po_number' => 'OLD-1', 'supplier_id' => $inactive->id]);
        PurchaseOrder::factory()->create(['po_number' => 'OTHER-1']);

        $this->actingAs($this->user())
            ->get('/purchase-orders?supplier='.$inactive->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('purchaseOrders.data', 1)
                ->where('purchaseOrders.data.0.po_number', 'OLD-1'));
    }

    public function test_active_and_closed_toggle_and_both_are_searchable(): void
    {
        PurchaseOrder::factory()->create(['po_number' => 'OPEN-1']);
        PurchaseOrder::factory()->closed()->create(['po_number' => 'CLOSED-1']);

        $this->actingAs($this->user());

        $this->get('/purchase-orders')
            ->assertInertia(fn (Assert $page) => $page
                ->has('purchaseOrders.data', 1)
                ->where('purchaseOrders.data.0.po_number', 'OPEN-1')
                ->where('filters.view', 'active'));

        $this->get('/purchase-orders?view=closed')
            ->assertInertia(fn (Assert $page) => $page
                ->has('purchaseOrders.data', 1)
                ->where('purchaseOrders.data.0.po_number', 'CLOSED-1'));

        $this->get('/purchase-orders?view=closed&search=CLOSED')
            ->assertInertia(fn (Assert $page) => $page->has('purchaseOrders.data', 1));

        $this->get('/purchase-orders?search=CLOSED')
            ->assertInertia(fn (Assert $page) => $page->has('purchaseOrders.data', 0));
    }

    public function test_default_sort_is_due_date_soonest_first_with_empty_dates_last(): void
    {
        PurchaseOrder::factory()->create(['po_number' => 'S-LATE', 'due_date' => '2026-12-20']);
        PurchaseOrder::factory()->withoutDueDate()->create(['po_number' => 'S-NONE']);
        PurchaseOrder::factory()->create(['po_number' => 'S-SOON', 'due_date' => '2026-12-01']);

        $this->actingAs($this->user());

        $this->get('/purchase-orders')
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrders.data.0.po_number', 'S-SOON')
                ->where('purchaseOrders.data.1.po_number', 'S-LATE')
                ->where('purchaseOrders.data.2.po_number', 'S-NONE')
                ->where('filters.sort', 'due_date')
                ->where('filters.direction', 'asc'));

        $this->get('/purchase-orders?sort=po_number&direction=desc')
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrders.data.0.po_number', 'S-SOON')
                ->where('purchaseOrders.data.1.po_number', 'S-NONE')
                ->where('purchaseOrders.data.2.po_number', 'S-LATE'));
    }

    public function test_an_unknown_sort_falls_back_to_the_default(): void
    {
        PurchaseOrder::factory()->create();

        $this->actingAs($this->user())
            ->get('/purchase-orders?sort=id;drop&direction=sideways')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.sort', 'due_date')
                ->where('filters.direction', 'asc'));
    }

    public function test_the_detail_page_shows_items_with_a_computed_total(): void
    {
        $po = PurchaseOrder::factory()->create(['po_number' => 'DETAIL-1']);
        PoItem::factory()->create(['purchase_order_id' => $po->id, 'unit_price' => '10.50', 'quantity_ordered' => 3]);

        $this->actingAs($this->user())
            ->get('/purchase-orders/'.$po->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('purchase-orders/show')
                ->where('purchaseOrder.po_number', 'DETAIL-1')
                ->has('purchaseOrder.items', 1)
                ->where('purchaseOrder.items.0.line_total', '31.50')
                ->where('purchaseOrder.total', '31.50'));
    }

    public function test_the_list_does_not_run_more_queries_for_more_rows(): void
    {
        $this->actingAs($this->user());
        $this->get('/purchase-orders')->assertOk(); // warm-up (permission cache)

        PurchaseOrder::factory()->count(3)->create();
        $few = $this->listQueryCount();

        PurchaseOrder::factory()->count(10)->create();
        $many = $this->listQueryCount();

        $this->assertSame($few, $many, 'The list runs extra queries per row (N+1).');
    }

    private function listQueryCount(): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get('/purchase-orders')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }
}