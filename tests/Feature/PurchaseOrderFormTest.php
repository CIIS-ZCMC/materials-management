<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderSource;
use App\Enums\PurchaseOrderStatus;
use App\Models\Item;
use App\Models\PoExtension;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PurchaseOrderFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'mms.delivery.term_counting' => 'calendar',
            'mms.delivery.extension_counting' => 'working',
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

    /** @return array<string, mixed> */
    private function line(Item $item, string $description = 'Gloves', string $price = '12.50', int $qty = 4, ?int $id = null): array
    {
        return [
            'id' => $id,
            'item_id' => $item->id,
            'description' => $description,
            'unit_price' => $price,
            'quantity_ordered' => $qty,
        ];
    }

    /**
     * 2026-11-02 is a Monday: +10 calendar days = 2026-11-12, +10 working days = 2026-11-16.
     *
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
            'schedule_dates' => ['2026-11-10', '2026-11-20'],
            'items' => [$this->line($item)],
        ], $override);
    }

    public function test_procurement_creates_a_po_with_items_and_schedule_dates(): void
    {
        $item = Item::factory()->create(['is_active' => true]);
        $payload = $this->payload(['items' => [$this->line($item)]]);

        $response = $this->actingAs($this->userWithRole('Procurement'))->post('/purchase-orders', $payload);

        $po = PurchaseOrder::where('po_number', '26-11-0001')->firstOrFail();

        $response->assertRedirect("/purchase-orders/{$po->id}");
        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);
        $this->assertSame(PurchaseOrderSource::Manual, $po->source);
        $this->assertNull($po->external_id);
        $this->assertSame('2026-11-12', $po->due_date->toDateString());
        $this->assertCount(2, $po->schedules);
        $this->assertCount(1, $po->items);
        $this->assertSame($item->category_id, $po->items->first()->category_id);
        $this->assertSame($item->unit_id, $po->items->first()->unit_id);

        $this->assertSame(1, Activity::query()->where('log_name', 'po')->where('event', 'created')->count());
    }

    public function test_due_date_follows_the_configured_counting_and_is_empty_without_a_term(): void
    {
        $procurement = $this->userWithRole('Procurement');

        foreach ([['calendar', '26-11-0101', '2026-11-12'], ['working', '26-11-0102', '2026-11-16']] as [$mode, $number, $expected]) {
            config(['mms.delivery.term_counting' => $mode]);

            $this->actingAs($procurement)->post('/purchase-orders', $this->payload(['po_number' => $number]));

            $this->assertSame($expected, PurchaseOrder::where('po_number', $number)->firstOrFail()->due_date->toDateString());
        }

        $this->actingAs($procurement)->post('/purchase-orders', $this->payload([
            'po_number' => '26-11-0103',
            'emailed_date' => null,
            'delivery_term_days' => null,
        ]));

        $this->assertNull(PurchaseOrder::where('po_number', '26-11-0103')->firstOrFail()->due_date);
    }

    public function test_duplicate_po_number_is_rejected(): void
    {
        PurchaseOrder::factory()->create(['po_number' => '26-11-0001']);

        $this->actingAs($this->userWithRole('Procurement'))
            ->post('/purchase-orders', $this->payload())
            ->assertSessionHasErrors('po_number');

        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_inactive_supplier_is_rejected_for_a_new_po(): void
    {
        $inactive = Supplier::factory()->create(['is_active' => false]);

        $this->actingAs($this->userWithRole('Procurement'))
            ->post('/purchase-orders', $this->payload(['supplier_id' => $inactive->id]))
            ->assertSessionHasErrors('supplier_id');

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_user_without_po_create_gets_403(): void
    {
        $staff = $this->userWithRole('MMS Staff');

        $this->actingAs($staff)->get('/purchase-orders/create')->assertForbidden();
        $this->actingAs($staff)->post('/purchase-orders', $this->payload())->assertForbidden();

        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_verified_and_bizbox_pos_cannot_be_edited(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $verified = PurchaseOrder::factory()->withStatus(PurchaseOrderStatus::Verified)->create();
        $bizbox = PurchaseOrder::factory()->fromBizBox()->create();

        foreach ([$verified, $bizbox] as $po) {
            $this->actingAs($procurement)->get("/purchase-orders/{$po->id}/edit")->assertForbidden();
            $this->actingAs($procurement)->put("/purchase-orders/{$po->id}", $this->payload())->assertForbidden();
        }

        $this->assertDatabaseMissing('purchase_orders', ['po_number' => '26-11-0001']);
    }

    public function test_draft_uploaded_and_escalated_can_be_edited_and_admin_can_fix_escalations(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $admin = $this->userWithRole('Admin');

        foreach ([PurchaseOrderStatus::Draft, PurchaseOrderStatus::Uploaded] as $status) {
            $po = PurchaseOrder::factory()->withStatus($status)->create();
            $this->actingAs($procurement)->get("/purchase-orders/{$po->id}/edit")->assertOk();
        }

        $escalated = PurchaseOrder::factory()->withStatus(PurchaseOrderStatus::Escalated)->create();
        $this->actingAs($admin)->get("/purchase-orders/{$escalated->id}/edit")->assertOk();
        $this->actingAs($admin)->get('/purchase-orders/create')->assertForbidden();
    }

    public function test_editing_the_emailed_date_or_term_recalculates_the_due_date_with_extensions(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $item = Item::factory()->create(['is_active' => true]);
        $po = PurchaseOrder::factory()->emailed(Carbon::parse('2026-11-02'), 10)->create();

        PoExtension::forceCreate([
            'purchase_order_id' => $po->id,
            'extra_days' => 5,
            'reason' => 'Supplier delay',
            'recorded_by' => $procurement->id,
            'approved_by' => $procurement->id,
            'approved_at' => now(),
            'old_due_date' => '2026-11-12',
            'new_due_date' => '2026-11-19',
        ]);

        $payload = $this->payload([
            'po_number' => $po->po_number,
            'supplier_id' => $po->supplier_id,
            'emailed_date' => '2026-11-09',
            'delivery_term_days' => 10,
            'schedule_dates' => [],
            'items' => [$this->line($item)],
        ]);

        // 2026-11-09 + 10 calendar days = 2026-11-19, + 5 working days = 2026-11-26.
        $this->actingAs($procurement)->put("/purchase-orders/{$po->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame('2026-11-26', $po->fresh()->due_date->toDateString());

        $this->actingAs($procurement)->put("/purchase-orders/{$po->id}", [...$payload, 'delivery_term_days' => 0]);
        $this->assertNull($po->fresh()->due_date);
    }

    public function test_audit_entry_after_a_real_edit_and_none_after_a_save_with_no_changes(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $item = Item::factory()->create(['is_active' => true]);
        $payload = $this->payload(['items' => [$this->line($item)]]);

        $this->actingAs($procurement)->post('/purchase-orders', $payload);

        $po = PurchaseOrder::where('po_number', '26-11-0001')->firstOrFail();
        $lineId = $po->items->first()->id;
        $payload['items'] = [$this->line($item, 'Gloves', '12.50', 4, $lineId)];

        $updated = fn () => Activity::query()->where('log_name', 'po')->where('event', 'updated')->count();

        $this->actingAs($procurement)->put("/purchase-orders/{$po->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame(0, $updated());

        $payload['items'] = [$this->line($item, 'Gloves', '12.50', 6, $lineId)];
        $payload['schedule_dates'] = ['2026-11-10', '2026-11-25'];

        $this->actingAs($procurement)->put("/purchase-orders/{$po->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, $updated());

        $entry = Activity::query()->where('event', 'updated')->firstOrFail();
        $new = $entry->properties->get('new');

        $this->assertArrayHasKey('items', $new);
        $this->assertArrayHasKey('schedule', $new);
        $this->assertSame(6, $po->items()->first()->quantity_ordered);
        $this->assertSame(2, $po->schedules()->count());
    }

    public function test_existing_lines_with_inactive_or_missing_items_stay_editable_but_new_lines_need_an_active_item(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $inactive = Item::factory()->create(['is_active' => false]);
        $po = PurchaseOrder::factory()->create();

        $kept = PoItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => $inactive->id,
            'description' => 'Old gauze',
            'category_id' => $inactive->category_id,
            'unit_id' => $inactive->unit_id,
            'unit_price' => '5.00',
            'quantity_ordered' => 2,
        ]);

        $legacy = PoItem::create([
            'purchase_order_id' => $po->id,
            'item_id' => null,
            'description' => 'Legacy line',
            'category_id' => $inactive->category_id,
            'unit_id' => $inactive->unit_id,
            'unit_price' => '7.00',
            'quantity_ordered' => 1,
        ]);

        $payload = $this->payload([
            'po_number' => $po->po_number,
            'supplier_id' => $po->supplier_id,
            'items' => [
                $this->line($inactive, 'Old gauze', '5.00', 3, $kept->id),
                ['id' => $legacy->id, 'item_id' => null, 'description' => 'Legacy line', 'unit_price' => '7.00', 'quantity_ordered' => 1],
            ],
        ]);

        $this->actingAs($procurement)->put("/purchase-orders/{$po->id}", $payload)->assertSessionHasNoErrors();
        $this->assertSame(3, $kept->fresh()->quantity_ordered);

        $payload['items'][] = $this->line($inactive, 'New line', '1.00', 1);

        $this->actingAs($procurement)->put("/purchase-orders/{$po->id}", $payload)->assertSessionHasErrors('items.2.item_id');
    }

    public function test_an_existing_po_keeps_an_inactive_supplier_but_cannot_switch_to_another_inactive_one(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $oldSupplier = Supplier::factory()->create(['is_active' => false]);
        $otherInactive = Supplier::factory()->create(['is_active' => false]);
        $po = PurchaseOrder::factory()->create(['supplier_id' => $oldSupplier->id]);

        $payload = $this->payload(['po_number' => $po->po_number, 'supplier_id' => $oldSupplier->id]);

        $this->actingAs($procurement)->put("/purchase-orders/{$po->id}", $payload)->assertSessionHasNoErrors();

        $this->actingAs($procurement)
            ->put("/purchase-orders/{$po->id}", [...$payload, 'supplier_id' => $otherInactive->id])
            ->assertSessionHasErrors('supplier_id');
    }

    public function test_item_search_returns_only_active_items_at_most_ten_and_needs_po_create_or_edit(): void
    {
        $procurement = $this->userWithRole('Procurement');

        Item::factory()->create(['name' => 'Sterile gauze', 'is_active' => true]);
        Item::factory()->create(['name' => 'Gauze pad', 'is_active' => true]);
        Item::factory()->create(['name' => 'Gauze roll', 'is_active' => false]);

        $response = $this->actingAs($procurement)->getJson('/purchase-orders/item-search?q=gauze')->assertOk();

        $this->assertSame(['Gauze pad', 'Sterile gauze'], collect($response->json('items'))->pluck('name')->all());

        foreach (range(1, 12) as $n) {
            Item::factory()->create(['name' => "Syringe {$n}", 'is_active' => true]);
        }

        $this->actingAs($procurement)->getJson('/purchase-orders/item-search?q=syringe')->assertJsonCount(10, 'items');

        // One letter now searches too (no 2-letter minimum): at most 10 rows, names starting with it first.
        $one = $this->actingAs($procurement)->getJson('/purchase-orders/item-search?q=s')->assertOk()->assertJsonCount(10, 'items');
        $this->assertTrue(collect($one->json('items'))->every(fn (array $item) => str_starts_with($item['name'], 'S')));

        $staff = $this->userWithRole('MMS Staff');

        $this->actingAs($staff)->getJson('/purchase-orders/item-search?q=gauze')->assertForbidden();
        $this->actingAs($staff)->getJson('/purchase-orders/item-search')->assertForbidden();
    }

    public function test_item_search_with_an_empty_box_returns_the_first_ten_active_items_alphabetically(): void
    {
        $procurement = $this->userWithRole('Procurement');

        Item::factory()->create(['name' => 'Aaa inactive item', 'is_active' => false]);

        foreach (range(1, 12) as $n) {
            Item::factory()->create(['name' => sprintf('Zeta %02d', $n), 'is_active' => true]);
        }

        $expected = array_map(fn (int $n) => sprintf('Zeta %02d', $n), range(1, 10));

        foreach (['/purchase-orders/item-search', '/purchase-orders/item-search?q=', '/purchase-orders/item-search?q=%20%20'] as $url) {
            $response = $this->actingAs($procurement)->getJson($url)->assertOk()->assertJsonCount(10, 'items');

            $this->assertSame($expected, collect($response->json('items'))->pluck('name')->all());
        }

        $first = $this->actingAs($procurement)->getJson('/purchase-orders/item-search')->json('items.0');

        $this->assertEqualsCanonicalizing(['id', 'name', 'category_id', 'category', 'unit_id', 'unit'], array_keys($first));
    }
}