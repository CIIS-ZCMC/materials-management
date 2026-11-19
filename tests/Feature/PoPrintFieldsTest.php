<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Step 4.5: the fields the printed PO forms need (supplier TIN, contact number and role;
 * PO P.R. no., CAF no., mode of procurement and free-text line; line stock no., brand,
 * manufacturer, country of origin and specifications).
 */
class PoPrintFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'mms.delivery.term_counting' => 'calendar',
            'mms.delivery.extension_counting' => 'working',
            'mms.print.procurement_modes' => ['Competitive/Public Bidding', 'Nego. Proc. - Emergency Cases'],
        ]);
    }

    /** A user holding exactly the role's default permissions from config/mms_access.php. */
    private function userWithRole(string $role): User
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $names = config("mms_access.roles.{$role}");

        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate($role, 'web')->syncPermissions($names));

        return $user;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function line(Item $item, array $extra = [], ?int $id = null): array
    {
        return array_replace([
            'id' => $id,
            'item_id' => $item->id,
            'description' => 'Gloves',
            'unit_price' => '12.50',
            'quantity_ordered' => 4,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function payload(array $override = []): array
    {
        $item = Item::factory()->create(['is_active' => true]);

        return array_replace([
            'po_number' => '26-11-0001',
            'supplier_id' => Supplier::factory()->create(['is_active' => true])->id,
            'po_date' => '2026-11-02',
            'emailed_date' => '2026-11-02',
            'delivery_term_days' => 10,
            'schedule_dates' => [],
            'items' => [$this->line($item)],
        ], $override);
    }

    public function test_procurement_saves_supplier_print_fields_and_they_reach_the_edit_page_and_the_audit_log(): void
    {
        $procurement = $this->userWithRole('Procurement');

        $this->actingAs($procurement)->post('/suppliers', [
            'name' => 'Print Test Trading',
            'tin' => '123-456-789-000',
            'contact_no' => '0917 123 4567',
            'supply_chain_role' => 'Distributor',
        ])->assertSessionHasNoErrors();

        $supplier = Supplier::where('name', 'Print Test Trading')->firstOrFail();

        $this->assertSame('123-456-789-000', $supplier->tin);
        $this->assertSame('0917 123 4567', $supplier->contact_no);
        $this->assertSame('Distributor', $supplier->supply_chain_role);

        $entry = Activity::query()->where('log_name', 'suppliers')->where('event', 'created')->firstOrFail();
        $this->assertSame('123-456-789-000', $entry->properties->get('new')['tin']);

        $this->actingAs($procurement)
            ->get("/suppliers/{$supplier->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('supplier.tin', '123-456-789-000')
                ->where('supplier.contact_no', '0917 123 4567')
                ->where('supplier.supply_chain_role', 'Distributor'));
    }

    public function test_supplier_print_fields_are_optional_and_the_tin_rejects_letters(): void
    {
        $procurement = $this->userWithRole('Procurement');

        $this->actingAs($procurement)
            ->post('/suppliers', ['name' => 'Plain Supplier'])
            ->assertSessionHasNoErrors();

        $this->assertNull(Supplier::where('name', 'Plain Supplier')->firstOrFail()->tin);

        $this->actingAs($procurement)
            ->post('/suppliers', ['name' => 'Bad TIN Supplier', 'tin' => 'abc'])
            ->assertSessionHasErrors('tin');

        $this->assertDatabaseMissing('suppliers', ['name' => 'Bad TIN Supplier']);
    }

    public function test_editing_a_supplier_print_field_is_written_to_the_audit_log(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $supplier = Supplier::factory()->create(['name' => 'Audit Supplier', 'tin' => '111-111-111-000']);

        $this->actingAs($procurement)->put("/suppliers/{$supplier->id}", [
            'name' => 'Audit Supplier',
            'email' => $supplier->email,
            'contact_person' => $supplier->contact_person,
            'address' => $supplier->address,
            'tin' => '222-222-222-000',
        ])->assertSessionHasNoErrors();

        $entry = Activity::query()->where('log_name', 'suppliers')->where('event', 'updated')->firstOrFail();

        $this->assertSame('111-111-111-000', $entry->properties->get('old')['tin']);
        $this->assertSame('222-222-222-000', $entry->properties->get('new')['tin']);
    }

    public function test_procurement_saves_the_catalog_stock_number(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $category = ItemCategory::factory()->create(['is_active' => true]);
        $unit = Unit::factory()->create(['is_active' => true]);

        $this->actingAs($procurement)->post('/catalog/items', [
            'name' => 'Test Gloves',
            'category_id' => $category->id,
            'unit_id' => $unit->id,
            'stock_no' => 'MED00084',
        ])->assertSessionHasNoErrors();

        $item = Item::where('name', 'Test Gloves')->firstOrFail();

        $this->assertSame('MED00084', $item->stock_no);

        $this->actingAs($procurement)
            ->get("/catalog/items/{$item->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('item.stock_no', 'MED00084'));
    }

    public function test_procurement_creates_a_po_with_print_fields_and_line_details(): void
    {
        $item = Item::factory()->create(['is_active' => true, 'stock_no' => 'MED00084']);

        $payload = $this->payload([
            'pr_no' => '26-03-0112',
            'caf_no' => '2026-0860',
            'mode_of_procurement' => 'Competitive/Public Bidding',
            'remarks' => 'for Pharmacy use',
            'items' => [$this->line($item, [
                'brand' => 'Acme',
                'manufacturer' => 'Acme Labs',
                'country_of_origin' => 'India',
                'specifications' => 'soft, at least 40g',
            ])],
        ]);

        $this->actingAs($this->userWithRole('Procurement'))
            ->post('/purchase-orders', $payload)
            ->assertSessionHasNoErrors();

        $po = PurchaseOrder::where('po_number', '26-11-0001')->firstOrFail();
        $line = $po->items->firstOrFail();

        $this->assertSame('26-03-0112', $po->pr_no);
        $this->assertSame('2026-0860', $po->caf_no);
        $this->assertSame('Competitive/Public Bidding', $po->mode_of_procurement);
        $this->assertSame('for Pharmacy use', $po->remarks);
        $this->assertSame('Acme', $line->brand);
        $this->assertSame('Acme Labs', $line->manufacturer);
        $this->assertSame('India', $line->country_of_origin);
        $this->assertSame('soft, at least 40g', $line->specifications);

        $new = Activity::query()->where('log_name', 'po')->where('event', 'created')->firstOrFail()->properties->get('new');

        $this->assertSame('26-03-0112', $new['pr_no']);
        $this->assertSame('2026-0860', $new['caf_no']);
        $this->assertSame('Competitive/Public Bidding', $new['mode_of_procurement']);
        $this->assertSame('for Pharmacy use', $new['remarks']);
    }

    public function test_a_po_without_print_fields_still_saves_with_empty_values(): void
    {
        $this->actingAs($this->userWithRole('Procurement'))
            ->post('/purchase-orders', $this->payload())
            ->assertSessionHasNoErrors();

        $po = PurchaseOrder::where('po_number', '26-11-0001')->firstOrFail();

        $this->assertNull($po->pr_no);
        $this->assertNull($po->caf_no);
        $this->assertNull($po->mode_of_procurement);
        $this->assertNull($po->remarks);
        $this->assertNull($po->items->firstOrFail()->brand);
    }

    public function test_the_line_stock_number_is_copied_from_the_catalog_unless_one_is_typed(): void
    {
        $copied = Item::factory()->create(['is_active' => true, 'stock_no' => 'MED00084']);
        $typed = Item::factory()->create(['is_active' => true, 'stock_no' => 'SUP11025']);

        $payload = $this->payload([
            'items' => [
                $this->line($copied, ['description' => 'Copied line', 'stock_no' => '']),
                $this->line($typed, ['description' => 'Typed line', 'stock_no' => 'CUSTOM-1']),
            ],
        ]);

        $this->actingAs($this->userWithRole('Procurement'))
            ->post('/purchase-orders', $payload)
            ->assertSessionHasNoErrors();

        $po = PurchaseOrder::where('po_number', '26-11-0001')->firstOrFail();

        $this->assertSame('MED00084', $po->items->firstWhere('description', 'Copied line')->stock_no);
        $this->assertSame('CUSTOM-1', $po->items->firstWhere('description', 'Typed line')->stock_no);
    }

    public function test_the_mode_of_procurement_must_be_in_the_list_but_an_existing_po_keeps_its_old_value(): void
    {
        $procurement = $this->userWithRole('Procurement');

        $this->actingAs($procurement)
            ->post('/purchase-orders', $this->payload(['mode_of_procurement' => 'Made up mode']))
            ->assertSessionHasErrors('mode_of_procurement');

        $this->assertSame(0, PurchaseOrder::count());

        $po = PurchaseOrder::factory()->create(['mode_of_procurement' => 'Old mode no longer listed']);

        $payload = $this->payload([
            'po_number' => $po->po_number,
            'supplier_id' => $po->supplier_id,
            'mode_of_procurement' => 'Old mode no longer listed',
        ]);

        $this->actingAs($procurement)->put("/purchase-orders/{$po->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame('Old mode no longer listed', $po->fresh()->mode_of_procurement);

        $this->actingAs($procurement)
            ->put("/purchase-orders/{$po->id}", [...$payload, 'mode_of_procurement' => 'Another made up mode'])
            ->assertSessionHasErrors('mode_of_procurement');
    }

    public function test_a_save_that_leaves_the_print_keys_out_does_not_wipe_stored_values(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $item = Item::factory()->create(['is_active' => true]);
        $po = PurchaseOrder::factory()->create(['pr_no' => '26-03-0112', 'remarks' => 'for Pharmacy use']);

        $line = PoItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Gloves',
            'category_id' => $item->category_id,
            'unit_id' => $item->unit_id,
            'unit_price' => '12.50',
            'quantity_ordered' => 4,
            'brand' => 'Keep me',
        ]);

        $payload = $this->payload([
            'po_number' => $po->po_number,
            'supplier_id' => $po->supplier_id,
            'items' => [$this->line($item, [], $line->id)],
        ]);

        $this->actingAs($procurement)->put("/purchase-orders/{$po->id}", $payload)->assertSessionHasNoErrors();

        $this->assertSame('26-03-0112', $po->fresh()->pr_no);
        $this->assertSame('for Pharmacy use', $po->fresh()->remarks);
        $this->assertSame('Keep me', $line->fresh()->brand);
    }

    public function test_changing_print_fields_is_written_to_the_audit_log(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $item = Item::factory()->create(['is_active' => true]);

        $this->actingAs($procurement)->post('/purchase-orders', $this->payload(['items' => [$this->line($item)]]));

        $po = PurchaseOrder::where('po_number', '26-11-0001')->firstOrFail();
        $lineId = $po->items->firstOrFail()->id;

        $payload = $this->payload([
            'po_number' => $po->po_number,
            'supplier_id' => $po->supplier_id,
            'remarks' => 'Baked Goods',
            'items' => [$this->line($item, ['brand' => 'Acme'], $lineId)],
        ]);

        $this->actingAs($procurement)->put("/purchase-orders/{$po->id}", $payload)->assertSessionHasNoErrors();

        $new = Activity::query()->where('log_name', 'po')->where('event', 'updated')->firstOrFail()->properties->get('new');

        $this->assertSame('Baked Goods', $new['remarks']);
        $this->assertStringContainsString('brand', $new['items']);
        $this->assertStringContainsString('Acme', $new['items']);
    }

    public function test_the_detail_page_shows_the_supplier_and_po_print_fields(): void
    {
        $supplier = Supplier::factory()->create([
            'tin' => '123-456-789-000',
            'contact_no' => '0917 123 4567',
            'supply_chain_role' => 'Distributor',
        ]);
        $po = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'pr_no' => '26-03-0112',
            'mode_of_procurement' => 'Competitive/Public Bidding',
        ]);
        $item = Item::factory()->create();

        PoItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'description' => 'Gloves',
            'category_id' => $item->category_id,
            'unit_id' => $item->unit_id,
            'unit_price' => '12.50',
            'quantity_ordered' => 4,
            'stock_no' => 'MED00084',
            'brand' => 'Acme',
        ]);

        $this->actingAs($this->userWithRole('MMS Staff'))
            ->get("/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrder.supplier_details.tin', '123-456-789-000')
                ->where('purchaseOrder.supplier_details.contact_no', '0917 123 4567')
                ->where('purchaseOrder.supplier_details.supply_chain_role', 'Distributor')
                ->where('purchaseOrder.pr_no', '26-03-0112')
                ->where('purchaseOrder.mode_of_procurement', 'Competitive/Public Bidding')
                ->where('purchaseOrder.caf_no', null)
                ->where('purchaseOrder.items.0.stock_no', 'MED00084')
                ->where('purchaseOrder.items.0.brand', 'Acme'));
    }

    public function test_the_edit_page_sends_the_print_fields_and_the_list_of_modes(): void
    {
        $po = PurchaseOrder::factory()->create(['caf_no' => '2026-0860']);

        $this->actingAs($this->userWithRole('Procurement'))
            ->get("/purchase-orders/{$po->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrder.caf_no', '2026-0860')
                ->where('procurementModes', ['Competitive/Public Bidding', 'Nego. Proc. - Emergency Cases']));
    }

    public function test_the_columns_the_system_controls_are_still_not_mass_assignable(): void
    {
        $po = new PurchaseOrder;

        foreach (['pr_no', 'caf_no', 'mode_of_procurement', 'remarks'] as $field) {
            $this->assertTrue($po->isFillable($field), "{$field} should be fillable");
        }

        foreach ([
            'status', 'due_date', 'closed_at',
            'consolidated_path', 'consolidated_pages', 'consolidated_built_at', 'consolidated_stale', 'consolidated_error',
        ] as $field) {
            $this->assertFalse($po->isFillable($field), "{$field} must not be fillable");
        }
    }

    public function test_bizbox_and_verified_pos_cannot_get_print_fields_through_the_form(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $bizbox = PurchaseOrder::factory()->fromBizBox()->create(['pr_no' => '26-03-0112']);
        $verified = PurchaseOrder::factory()->withStatus(PurchaseOrderStatus::Verified)->create(['pr_no' => '26-03-0113']);

        foreach ([$bizbox, $verified] as $po) {
            $this->actingAs($procurement)
                ->put("/purchase-orders/{$po->id}", $this->payload(['pr_no' => 'CHANGED']))
                ->assertForbidden();

            $this->assertNotSame('CHANGED', $po->fresh()->pr_no);
        }
    }
}