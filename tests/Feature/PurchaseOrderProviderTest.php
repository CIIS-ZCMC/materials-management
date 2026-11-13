<?php

namespace Tests\Feature;

use App\Contracts\PurchaseOrderProvider;
use App\Enums\PurchaseOrderSource;
use App\Exceptions\BizBoxNotConfigured;
use App\Exceptions\PurchaseOrderNotFound;
use App\Integrations\PurchaseOrders\BizBoxPurchaseOrderProvider;
use App\Integrations\PurchaseOrders\LocalPurchaseOrderProvider;
use App\Models\PoItem;
use App\Models\PoSchedule;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PurchaseOrderProviderTest extends TestCase
{
    use RefreshDatabase;

    private function makePo(): PurchaseOrder
    {
        $po = PurchaseOrder::factory()->create([
            'po_number' => 'PO-3-4-001',
            'po_date' => '2026-11-01',
            'emailed_date' => '2026-11-02',
            'delivery_term_days' => 30,
        ]);

        PoItem::factory()->create([
            'purchase_order_id' => $po->id,
            'description' => 'Gauze, sterile',
            'unit_price' => '125.50',
            'quantity_ordered' => 40,
        ]);

        PoItem::factory()->create([
            'purchase_order_id' => $po->id,
            'item_id' => null,
            'description' => 'Line outside the catalog',
            'unit_price' => '10.00',
            'quantity_ordered' => 5,
        ]);

        PoSchedule::factory()->create(['purchase_order_id' => $po->id, 'scheduled_date' => '2026-11-27']);
        PoSchedule::factory()->create(['purchase_order_id' => $po->id, 'scheduled_date' => '2026-11-20']);

        return $po->fresh();
    }

    public function test_local_provider_returns_the_po_and_its_lines(): void
    {
        $po = $this->makePo();
        $provider = new LocalPurchaseOrderProvider;

        $data = $provider->fetch('PO-3-4-001');

        $this->assertTrue($provider->isAvailable());
        $this->assertSame(PurchaseOrderSource::Manual, $provider->source());
        $this->assertSame('PO-3-4-001', $data->poNumber);
        $this->assertSame($po->supplier->name, $data->supplierName);
        $this->assertSame('2026-11-01', $data->poDate);
        $this->assertSame('2026-11-02', $data->emailedDate);
        $this->assertSame(30, $data->deliveryTermDays);
        $this->assertNull($data->externalId);
        $this->assertSame(['2026-11-20', '2026-11-27'], $data->scheduleDates);

        $this->assertCount(2, $data->lines);
        $this->assertSame('Gauze, sterile', $data->lines[0]->description);
        $this->assertSame('125.50', $data->lines[0]->unitPrice);
        $this->assertSame(40, $data->lines[0]->quantityOrdered);
        $this->assertNull($data->lines[1]->itemId);
        $this->assertSame('Line outside the catalog', $data->lines[1]->description);
    }

    public function test_local_provider_trims_the_reference(): void
    {
        $this->makePo();

        $data = (new LocalPurchaseOrderProvider)->fetch("  PO-3-4-001  ");

        $this->assertSame('PO-3-4-001', $data->poNumber);
    }

    public function test_local_provider_throws_for_an_unknown_po(): void
    {
        $this->expectException(PurchaseOrderNotFound::class);

        (new LocalPurchaseOrderProvider)->fetch('NO-SUCH-PO');
    }

    public function test_local_provider_has_no_iar_checklist_yet(): void
    {
        $this->makePo();

        $this->assertSame([], (new LocalPurchaseOrderProvider)->iarChecklist('PO-3-4-001'));
    }

    public function test_bizbox_stub_throws_not_configured(): void
    {
        $provider = new BizBoxPurchaseOrderProvider;

        $this->assertFalse($provider->isAvailable());
        $this->assertSame(PurchaseOrderSource::BizBox, $provider->source());

        $this->expectException(BizBoxNotConfigured::class);
        $this->expectExceptionMessage('BizBox is not configured');

        $provider->fetch('PO-3-4-001');
    }

    public function test_bizbox_stub_checklist_throws_not_configured(): void
    {
        $this->expectException(BizBoxNotConfigured::class);

        (new BizBoxPurchaseOrderProvider)->iarChecklist('PO-3-4-001');
    }

    public function test_container_gives_the_local_provider_when_the_flag_is_false(): void
    {
        config(['mms.bizbox.enabled' => false]);

        $this->assertInstanceOf(LocalPurchaseOrderProvider::class, $this->app->make(PurchaseOrderProvider::class));
    }

    public function test_container_gives_the_bizbox_stub_when_the_flag_is_true(): void
    {
        config(['mms.bizbox.enabled' => true]);

        $this->assertInstanceOf(BizBoxPurchaseOrderProvider::class, $this->app->make(PurchaseOrderProvider::class));
    }

    public function test_the_flag_is_a_boolean_in_config(): void
    {
        $this->assertIsBool(config('mms.bizbox.enabled'));
    }

    public function test_create_page_still_loads(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        foreach (['po.view', 'po.create'] as $name) {
            Permission::findOrCreate($name, 'web');
            $user->givePermissionTo($name);
        }

        $this->actingAs($user)
            ->get('/purchase-orders/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('purchase-orders/create'));
    }
}