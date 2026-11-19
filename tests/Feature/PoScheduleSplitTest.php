<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Models\Item;
use App\Models\PoItem;
use App\Models\PoSchedule;
use App\Models\PoScheduleItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PurchaseOrderSeeder;
use Database\Seeders\SupplierSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Step 3.3c: the quantity of each PO line per delivery column (form 04A).
 *
 * The standard PO of these tests has delivery term 7 and two lines:
 *   line 0: 170 ordered = 60 within the term + 60 on D1 + 50 on D2
 *   line 1: 1,700 ordered = 600 within the term + 1,100 on D1 + 0 on D2   (zero is a real value)
 */
class PoScheduleSplitTest extends TestCase
{
    use RefreshDatabase;

    private const D1 = '2027-01-05';

    private const D2 = '2027-02-05';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config([
            'mms.delivery.term_counting' => 'calendar',
            'mms.delivery.extension_counting' => 'working',
            'mms.print.max_date_columns' => 4,
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

    /** @return list<array{ordered: int, term?: int|null}> */
    private function lines(): array
    {
        return [['ordered' => 170, 'term' => 60], ['ordered' => 1700, 'term' => 600]];
    }

    /** @return list<array{date: string, quantities: list<int>, id?: int|null}> */
    private function columns(): array
    {
        return [
            ['date' => self::D1, 'quantities' => [60, 1100]],
            ['date' => self::D2, 'quantities' => [50, 0]],
        ];
    }

    /**
     * Input as the Service receives it (validated). Lines are matched to the columns by position.
     *
     * @param  list<array{ordered: int, term?: int|null, id?: int|null, item_id?: int|null}>  $lines
     * @param  list<array{date: string, quantities: list<int>, id?: int|null}>  $columns
     * @return array<string, mixed>
     */
    private function data(array $lines, array $columns, ?int $term = 7, string $number = 'PO-SPLIT-1'): array
    {
        $items = [];

        foreach ($lines as $i => $line) {
            $items[] = [
                'id' => $line['id'] ?? null,
                'item_id' => $line['item_id'] ?? Item::factory()->create(['is_active' => true])->id,
                'description' => "Line {$i}",
                'unit_price' => '10.00',
                'quantity_ordered' => $line['ordered'],
                'term_quantity' => $line['term'] ?? null,
            ];
        }

        return [
            'po_number' => $number,
            'supplier_id' => Supplier::factory()->create(['is_active' => true])->id,
            'po_date' => '2026-11-16',
            'emailed_date' => '2026-11-16',
            'delivery_term_days' => $term,
            'items' => $items,
            'schedule_columns' => array_map(fn (array $c) => [
                'id' => $c['id'] ?? null,
                'date' => $c['date'],
                'quantities' => $c['quantities'],
            ], $columns),
        ];
    }

    /**
     * Input for updating an existing PO: the first lines keep their ids and catalog items.
     *
     * @param  list<array{ordered: int, term?: int|null}>  $lines
     * @param  list<array{date: string, quantities: list<int>, id?: int|null}>  $columns
     * @return array<string, mixed>
     */
    private function updateData(PurchaseOrder $po, array $lines, array $columns, ?int $term = 7): array
    {
        $existing = $po->items()->orderBy('id')->get()->values();

        foreach ($lines as $i => $line) {
            if (isset($existing[$i])) {
                $lines[$i]['id'] = $existing[$i]->id;
                $lines[$i]['item_id'] = $existing[$i]->item_id;
            }
        }

        $data = $this->data($lines, $columns, $term, $po->po_number);
        $data['supplier_id'] = $po->supplier_id;

        return $data;
    }

    private function createSplitPo(string $number = 'PO-SPLIT-1'): PurchaseOrder
    {
        return app(PurchaseOrderService::class)->create($this->data($this->lines(), $this->columns(), 7, $number));
    }

    /** @return array<string, list<string>> */
    private function errorsOf(callable $action): array
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('Expected a ValidationException.');
    }

    public function test_a_split_is_saved_with_the_zeros_and_the_term_quantity(): void
    {
        $po = $this->createSplitPo();

        $this->assertSame(2, PoSchedule::where('purchase_order_id', $po->id)->count());
        $this->assertSame(4, PoScheduleItem::count()); // every line in every column, the zero included

        $lines = $po->items()->orderBy('id')->get();
        $d1 = PoSchedule::where('scheduled_date', self::D1)->firstOrFail();
        $d2 = PoSchedule::where('scheduled_date', self::D2)->firstOrFail();

        $this->assertSame([60, 600], $lines->pluck('term_quantity')->all());
        $this->assertSame(1100, PoScheduleItem::where('po_schedule_id', $d1->id)->where('po_item_id', $lines[1]->id)->firstOrFail()->quantity);
        $this->assertSame(0, PoScheduleItem::where('po_schedule_id', $d2->id)->where('po_item_id', $lines[1]->id)->firstOrFail()->quantity);
        $this->assertSame(1, Activity::query()->where('log_name', 'po')->where('event', 'created')->count());
    }

    public function test_lines_that_do_not_add_up_are_rejected_and_nothing_is_saved(): void
    {
        $lines = $this->lines();
        $lines[0]['term'] = 61; // 61 + 60 + 50 = 171, not 170

        $errors = $this->errorsOf(fn () => app(PurchaseOrderService::class)->create($this->data($lines, $this->columns())));

        $this->assertArrayHasKey('items.0.quantity_ordered', $errors);
        $this->assertArrayNotHasKey('items.1.quantity_ordered', $errors);
        $this->assertSame(0, PurchaseOrder::count());
        $this->assertSame(0, PoScheduleItem::count());
    }

    public function test_a_date_that_delivers_nothing_is_rejected(): void
    {
        $data = $this->data([['ordered' => 100, 'term' => 100]], [['date' => self::D1, 'quantities' => [0]]]);

        $errors = $this->errorsOf(fn () => app(PurchaseOrderService::class)->create($data));

        $this->assertArrayHasKey('schedule_columns.0.date', $errors);
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_every_line_needs_a_term_quantity_when_the_po_has_a_delivery_term(): void
    {
        $lines = $this->lines();
        $lines[1]['term'] = null;

        $errors = $this->errorsOf(fn () => app(PurchaseOrderService::class)->create($this->data($lines, $this->columns())));

        $this->assertArrayHasKey('items.1.term_quantity', $errors);
    }

    public function test_without_a_delivery_term_there_is_no_term_column(): void
    {
        $service = app(PurchaseOrderService::class);

        // A quantity within the term, but no term on the PO: rejected.
        $bad = $this->data([['ordered' => 100, 'term' => 40]], [['date' => self::D1, 'quantities' => [60]]], null);

        $this->assertArrayHasKey('items.0.term_quantity', $this->errorsOf(fn () => $service->create($bad)));

        // Only dates: accepted, and the line's term quantity stays null.
        $po = $service->create($this->data([['ordered' => 100]], [['date' => self::D1, 'quantities' => [60]], ['date' => self::D2, 'quantities' => [40]]], null));

        $this->assertNull($po->items()->firstOrFail()->term_quantity);
        $this->assertSame(2, PoScheduleItem::count());
    }

    public function test_the_term_column_alone_is_a_valid_split(): void
    {
        $po = app(PurchaseOrderService::class)->create($this->data([['ordered' => 100, 'term' => 100]], []));

        $this->assertSame(100, $po->items()->firstOrFail()->term_quantity);
        $this->assertSame(0, PoSchedule::count());
        $this->assertSame(0, PoScheduleItem::count());
    }

    public function test_the_number_of_date_columns_follows_the_config(): void
    {
        config(['mms.print.max_date_columns' => 2]);

        $columns = [
            ['date' => '2027-01-05', 'quantities' => [1]],
            ['date' => '2027-02-05', 'quantities' => [1]],
            ['date' => '2027-03-05', 'quantities' => [1]],
        ];

        $errors = $this->errorsOf(fn () => app(PurchaseOrderService::class)->create($this->data([['ordered' => 3]], $columns, null)));

        $this->assertArrayHasKey('schedule_columns', $errors);

        config(['mms.print.max_date_columns' => 3]);

        $po = app(PurchaseOrderService::class)->create($this->data([['ordered' => 3]], $columns, null));

        $this->assertSame(3, $po->schedules()->count());
    }

    public function test_a_repeated_date_is_rejected(): void
    {
        $columns = [
            ['date' => self::D1, 'quantities' => [1]],
            ['date' => self::D1, 'quantities' => [1]],
        ];

        $errors = $this->errorsOf(fn () => app(PurchaseOrderService::class)->create($this->data([['ordered' => 2]], $columns, null)));

        $this->assertArrayHasKey('schedule_columns.1.date', $errors);
    }

    public function test_saving_flat_dates_still_works_and_removes_the_split(): void
    {
        $service = app(PurchaseOrderService::class);
        $po = $this->createSplitPo();

        $data = $this->updateData($po, $this->lines(), $this->columns());
        unset($data['schedule_columns']);
        $data['schedule_dates'] = [self::D1];

        $service->update($po, $data);

        $this->assertSame(0, PoScheduleItem::count());
        $this->assertSame([null, null], $po->items()->orderBy('id')->pluck('term_quantity')->all());
        $this->assertSame([self::D1], PoSchedule::query()->orderBy('scheduled_date')->get()->map(fn ($s) => $s->scheduled_date->toDateString())->all());

        $quantities = Activity::query()->where('event', 'updated')->firstOrFail()->properties->get('new')['quantities'];
        $this->assertStringContainsString('none', $quantities);
    }

    public function test_changing_a_date_keeps_its_row_and_its_quantities(): void
    {
        $po = $this->createSplitPo();
        [$s1, $s2] = $po->schedules()->get()->all();

        $columns = [
            ['id' => $s1->id, 'date' => '2027-01-15', 'quantities' => [60, 1100]],
            ['id' => $s2->id, 'date' => self::D2, 'quantities' => [50, 0]],
        ];

        app(PurchaseOrderService::class)->update($po, $this->updateData($po, $this->lines(), $columns));

        $this->assertSame('2027-01-15', $s1->fresh()->scheduled_date->toDateString());
        $this->assertSame(2, PoSchedule::count());
        $this->assertSame(4, PoScheduleItem::count());

        $lineId = $po->items()->orderBy('id')->firstOrFail()->id;
        $this->assertSame(60, PoScheduleItem::where('po_schedule_id', $s1->id)->where('po_item_id', $lineId)->firstOrFail()->quantity);
    }

    public function test_two_dates_can_be_swapped_in_one_save(): void
    {
        $po = $this->createSplitPo();
        [$s1, $s2] = $po->schedules()->get()->all();

        $columns = [
            ['id' => $s1->id, 'date' => self::D2, 'quantities' => [60, 1100]],
            ['id' => $s2->id, 'date' => self::D1, 'quantities' => [50, 0]],
        ];

        app(PurchaseOrderService::class)->update($po, $this->updateData($po, $this->lines(), $columns));

        $this->assertSame(self::D2, $s1->fresh()->scheduled_date->toDateString());
        $this->assertSame(self::D1, $s2->fresh()->scheduled_date->toDateString());

        $lineId = $po->items()->orderBy('id')->firstOrFail()->id;
        $this->assertSame(60, PoScheduleItem::where('po_schedule_id', $s1->id)->where('po_item_id', $lineId)->firstOrFail()->quantity);
    }

    public function test_without_an_id_an_identical_date_reuses_the_stored_row(): void
    {
        $service = app(PurchaseOrderService::class);

        // A plain PO with two dates and no split.
        $flat = $this->data([['ordered' => 170], ['ordered' => 1700]], [], 7);
        unset($flat['schedule_columns']);
        $flat['schedule_dates'] = [self::D1, self::D2];

        $po = $service->create($flat);
        $before = PoSchedule::query()->orderBy('id')->pluck('id')->all();

        // The form turns the split on: the columns carry no id.
        $service->update($po, $this->updateData($po, $this->lines(), $this->columns()));

        $this->assertSame($before, PoSchedule::query()->orderBy('id')->pluck('id')->all());
        $this->assertSame(4, PoScheduleItem::count());
    }

    public function test_removing_a_line_and_a_date_removes_their_quantities(): void
    {
        $po = $this->createSplitPo();
        $s1 = $po->schedules()->firstOrFail();

        $data = $this->updateData($po, [['ordered' => 170, 'term' => 60]], [['id' => $s1->id, 'date' => self::D1, 'quantities' => [110]]]);

        app(PurchaseOrderService::class)->update($po, $data);

        $this->assertSame(1, PoItem::count());
        $this->assertSame(1, PoSchedule::count());
        $this->assertSame(1, PoScheduleItem::count());
    }

    public function test_a_new_line_is_linked_to_its_quantities_by_position(): void
    {
        $po = $this->createSplitPo();
        [$s1, $s2] = $po->schedules()->get()->all();

        $lines = [...$this->lines(), ['ordered' => 30, 'term' => 10]];
        $columns = [
            ['id' => $s1->id, 'date' => self::D1, 'quantities' => [60, 1100, 10]],
            ['id' => $s2->id, 'date' => self::D2, 'quantities' => [50, 0, 10]],
        ];

        app(PurchaseOrderService::class)->update($po, $this->updateData($po, $lines, $columns));

        $new = PoItem::where('description', 'Line 2')->firstOrFail();

        $this->assertSame(10, $new->term_quantity);
        $this->assertSame(2, PoScheduleItem::where('po_item_id', $new->id)->count());
        $this->assertSame(6, PoScheduleItem::count());
    }

    public function test_a_changed_line_quantity_must_be_rebalanced(): void
    {
        $po = $this->createSplitPo();

        $lines = $this->lines();
        $lines[0]['ordered'] = 200; // the split still adds up to 170

        $errors = $this->errorsOf(fn () => app(PurchaseOrderService::class)->update($po, $this->updateData($po, $lines, $this->columns())));

        $this->assertArrayHasKey('items.0.quantity_ordered', $errors);
        $this->assertSame(170, $po->items()->orderBy('id')->firstOrFail()->quantity_ordered);
    }

    public function test_a_column_that_belongs_to_another_po_is_refused(): void
    {
        $a = $this->createSplitPo('PO-SPLIT-A');
        $b = app(PurchaseOrderService::class)->create(
            $this->data([['ordered' => 10, 'term' => 5]], [['date' => self::D1, 'quantities' => [5]]], 7, 'PO-SPLIT-B'),
        );

        $foreign = $a->schedules()->firstOrFail()->id;
        $data = $this->updateData($b, [['ordered' => 10, 'term' => 5]], [['id' => $foreign, 'date' => self::D1, 'quantities' => [5]]]);

        $errors = $this->errorsOf(fn () => app(PurchaseOrderService::class)->update($b, $data));

        $this->assertArrayHasKey('form', $errors);
        $this->assertSame(1, $b->schedules()->count());
        $this->assertSame(2, $a->schedules()->count());
    }

    public function test_a_save_with_no_changes_writes_no_audit_entry_and_keeps_every_row(): void
    {
        $po = $this->createSplitPo();
        $rows = PoScheduleItem::query()->orderBy('id')->pluck('id')->all();

        app(PurchaseOrderService::class)->update($po, $this->updateData($po, $this->lines(), $this->columns()));

        $this->assertSame(0, Activity::query()->where('log_name', 'po')->where('event', 'updated')->count());
        $this->assertSame($rows, PoScheduleItem::query()->orderBy('id')->pluck('id')->all());
    }

    public function test_changing_quantities_is_written_to_the_audit_log(): void
    {
        $po = $this->createSplitPo();
        [$s1, $s2] = $po->schedules()->get()->all();

        $lines = $this->lines();
        $lines[0]['term'] = 50;
        $columns = [
            ['id' => $s1->id, 'date' => self::D1, 'quantities' => [70, 1100]],
            ['id' => $s2->id, 'date' => self::D2, 'quantities' => [50, 0]],
        ];

        app(PurchaseOrderService::class)->update($po, $this->updateData($po, $lines, $columns));

        $entry = Activity::query()->where('log_name', 'po')->where('event', 'updated')->firstOrFail();

        $this->assertStringContainsString('within term 60', $entry->properties->get('old')['quantities']);
        $this->assertStringContainsString('within term 50', $entry->properties->get('new')['quantities']);
        $this->assertStringNotContainsString('Line 1', $entry->properties->get('new')['quantities']);
    }

    public function test_procurement_saves_the_split_through_the_form(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $item = Item::factory()->create(['is_active' => true]);
        $other = Item::factory()->create(['is_active' => true]);

        $payload = [
            'po_number' => '26-11-0777',
            'supplier_id' => Supplier::factory()->create(['is_active' => true])->id,
            'po_date' => '2026-11-02',
            'emailed_date' => '2026-11-02',
            'delivery_term_days' => 7,
            'schedule_dates' => [],
            'items' => [
                ['id' => null, 'item_id' => $item->id, 'description' => 'A', 'unit_price' => '2.40', 'quantity_ordered' => 1700, 'term_quantity' => 600],
                ['id' => null, 'item_id' => $other->id, 'description' => 'B', 'unit_price' => '208.00', 'quantity_ordered' => 170, 'term_quantity' => 60],
            ],
            'schedule_columns' => [
                ['id' => null, 'date' => '2026-12-05', 'quantities' => [1100, 60]],
                ['id' => null, 'date' => '2027-01-05', 'quantities' => [0, 50]],
            ],
        ];

        $this->actingAs($procurement)->post('/purchase-orders', $payload)->assertSessionHasNoErrors();

        $po = PurchaseOrder::where('po_number', '26-11-0777')->firstOrFail();

        $this->assertSame(2, $po->schedules()->count());
        $this->assertSame(4, PoScheduleItem::count());
        $this->assertSame([600, 60], $po->items()->orderBy('id')->pluck('term_quantity')->all());

        // The request rules: a negative quantity and a missing date never reach the Service.
        $bad = $payload;
        $bad['po_number'] = '26-11-0778';
        $bad['schedule_columns'][0]['quantities'][0] = -1;

        $this->actingAs($procurement)->post('/purchase-orders', $bad)->assertSessionHasErrors('schedule_columns.0.quantities.0');

        $bad = $payload;
        $bad['po_number'] = '26-11-0779';
        $bad['items'][0]['quantity_ordered'] = 1701; // the split adds up to 1,700

        $this->actingAs($procurement)->post('/purchase-orders', $bad)->assertSessionHasErrors('items.0.quantity_ordered');

        $this->assertSame(1, PurchaseOrder::count());
    }

    public function test_the_create_edit_and_detail_pages_send_the_split_and_the_column_limit(): void
    {
        $procurement = $this->userWithRole('Procurement');
        $po = $this->createSplitPo();
        $lines = $po->items()->orderBy('id')->get();
        $plain = PurchaseOrder::factory()->create();

        $this->actingAs($procurement)
            ->get('/purchase-orders/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('maxDateColumns', 4));

        $this->actingAs($procurement)
            ->get("/purchase-orders/{$po->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('maxDateColumns', 4)
                ->where('purchaseOrder.schedule_dates', [self::D1, self::D2])
                ->where('purchaseOrder.schedule_split.columns.0.date', self::D1)
                ->where("purchaseOrder.schedule_split.columns.0.quantities.{$lines[1]->id}", 1100)
                ->where("purchaseOrder.schedule_split.term_quantities.{$lines[0]->id}", 60));

        $this->actingAs($this->userWithRole('MMS Staff'))
            ->get("/purchase-orders/{$po->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrder.schedules', [self::D1, self::D2])
                ->where('purchaseOrder.schedule_split.columns.1.date', self::D2)
                ->where("purchaseOrder.schedule_split.columns.1.quantities.{$lines[1]->id}", 0));

        $this->actingAs($procurement)
            ->get("/purchase-orders/{$plain->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('purchaseOrder.schedule_split', null));
    }

    public function test_a_verified_po_cannot_get_a_split_through_the_form(): void
    {
        $po = $this->createSplitPo('PO-SPLIT-V');
        $verified = PurchaseOrderStatus::tryFrom('verified');
        $verified !== null || $this->markTestSkipped('No verified status in the enum.');
        $po->forceFill(['status' => $verified])->save();
        $data = $this->updateData($po, $this->lines(), $this->columns());

        $this->actingAs($this->userWithRole('Procurement'))
            ->put("/purchase-orders/{$po->id}", $data)
            ->assertForbidden();

        $this->assertSame(4, PoScheduleItem::count()); // unchanged
    }

    public function test_the_seeder_builds_splits_that_follow_the_rules(): void
    {
        $this->seed([SupplierSeeder::class, CatalogSeeder::class, PurchaseOrderSeeder::class]);

        $split = PurchaseOrder::query()->whereHas('items', fn ($q) => $q->whereNotNull('term_quantity'))->with(['items', 'schedules.quantities'])->get();

        $this->assertGreaterThan(0, $split->count());

        foreach ($split as $po) {
            foreach ($po->items as $line) {
                $sum = (int) $line->term_quantity + PoScheduleItem::where('po_item_id', $line->id)->sum('quantity');

                $this->assertSame($line->quantity_ordered, (int) $sum, "PO {$po->po_number} line {$line->id} does not add up");
            }

            foreach ($po->schedules as $schedule) {
                $this->assertGreaterThanOrEqual(1, (int) $schedule->quantities->sum('quantity'), "PO {$po->po_number}: a date delivers nothing");
            }
        }
    }
}
