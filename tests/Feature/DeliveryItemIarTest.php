<?php

use App\Enums\DeliveryItemStatus;
use App\Enums\DeliveryProgress;
use App\Enums\DeliveryStatus;
use App\Enums\IarSeriesType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\DeliveryService;
use App\Support\DeliveryItemRule;
use App\Support\DeliveryTotals;
use App\Support\Quantity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
| Step 6.1b: ONE DELIVERY = ONE IAR = ONE QUANTITY PER PO LINE (revision of step 6.1).
| Helper names start with "ditem2_" so they cannot clash with "ditem_", "delivery*", "ddoc_", "po_doc_" or "po_types_".
| A delivery is recorded the normal way (POST /deliveries, so its IAR number exists) and then set to "Documents complete"
| directly in the database: the completeness gate itself is tested in DeliveryDocumentTest.
| NOT run when written (no PHP in that session): run `php artisan test --filter=DeliveryItemIarTest`.
*/

beforeEach(function () {
    Storage::fake('local');
    Process::fake();
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

// ---- helpers ---------------------------------------------------------------------------------------

function ditem2_user(string $role, ?string $preset = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    if ($preset !== null) {
        $user->givePermissionTo(config("mms_access.job_presets.{$preset}.permissions"));
    }

    return $user;
}

function ditem2_reviewer(): User
{
    return ditem2_user('MMS Staff', 'po-reviewer');
}

function ditem2_encoder(): User
{
    return ditem2_user('MMS Staff', 'encoder');
}

/** A verified PO with one PO line per number given (the quantity ordered). @param  list<int>  $ordered */
function ditem2_po(array $ordered = [1000]): array
{
    $po = PurchaseOrder::factory()->create(['po_date' => '2026-01-05']);
    $po->forceFill(['status' => PurchaseOrderStatus::Verified])->save();

    $lines = array_map(fn (int $quantity) => PoItem::factory()->create(['purchase_order_id' => $po->id, 'quantity_ordered' => $quantity]), $ordered);

    return [$po->fresh(), $lines];
}

/** A delivery recorded the normal way (a new IAR number each time), then set to "Documents complete". */
function ditem2_delivery(PurchaseOrder $po, ?string $dateReceived = null, bool $complete = true): Delivery
{
    static $sequence = 7000;
    $sequence++;

    $year = (int) now()->setTimezone('Asia/Manila')->format('Y');
    $number = IarSeriesType::Regular->format($year, $sequence);

    test()->actingAs(ditem2_encoder())->post('/deliveries', [
        'purchase_order_id' => $po->id,
        'invoice_no' => 'INV-'.$sequence,
        'iar_series' => 'regular',
        'iar_number' => $number,
        'fund_cluster' => '01',
        'amount' => '1000.00',
        'date_received' => $dateReceived ?? now()->setTimezone('Asia/Manila')->toDateString(),
        'inspectors' => [['name' => 'Ana Inspector', 'position' => 'Pharmacist II']],
        'end_user' => ['name' => 'Cora Enduser', 'position' => 'Nurse IV'],
    ])->assertSessionHasNoErrors();

    $delivery = Delivery::query()->where('iar_number', $number)->firstOrFail();

    if ($complete) {
        $delivery->forceFill(['status' => DeliveryStatus::Complete])->save();
    }

    return $delivery->fresh();
}

/** @param  list<array<string, mixed>>  $lines */
function ditem2_post(User $user, Delivery $delivery, array $lines)
{
    return test()->actingAs($user)->post("/deliveries/{$delivery->id}/items", ['lines' => $lines]);
}

function ditem2_line(PoItem $line, string|int $quantity, array $extra = []): array
{
    return ['po_item_id' => $line->id, 'quantity' => (string) $quantity, ...$extra];
}

/** The delivered total of a PO line as the pages show it (computed, never stored). */
function ditem2_delivered(PoItem $line): string
{
    return Quantity::format(DeliveryTotals::deliveredByLine([$line->id])[$line->id]);
}

// ---- one quantity per PO line per delivery ----------------------------------------------------------

it('refuses a second active quantity for the same line on the same IAR, and the first stays', function () {
    [$po, [$line]] = ditem2_po([100]);
    $delivery = ditem2_delivery($po);
    $reviewer = ditem2_reviewer();

    ditem2_post($reviewer, $delivery, [ditem2_line($line, 40)])->assertSessionHasNoErrors();

    ditem2_post($reviewer, $delivery, [ditem2_line($line, 10)])
        ->assertSessionHasErrors(['lines.0.po_item_id' => DeliveryItemRule::LINE_ALREADY_ENTERED]);

    $rows = DeliveryItem::query()->where('delivery_id', $delivery->id)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->status)->toBe(DeliveryItemStatus::Active)
        ->and(Quantity::thousandths($rows->first()->quantity))->toBe(40_000)
        ->and(ditem2_delivered($line))->toBe('40');
});

it('uses the exact message "This line already has a quantity on this IAR ..."', function () {
    expect(DeliveryItemRule::LINE_ALREADY_ENTERED)
        ->toBe('This line already has a quantity on this IAR. Void it and enter it again, or record a new delivery with a new IAR.');
});

it('saves nothing when one line of the save is taken and another is free', function () {
    [$po, [$taken, $free]] = ditem2_po([100, 100]);
    $delivery = ditem2_delivery($po);
    $reviewer = ditem2_reviewer();

    ditem2_post($reviewer, $delivery, [ditem2_line($taken, 40)])->assertSessionHasNoErrors();

    ditem2_post($reviewer, $delivery, [ditem2_line($free, 25), ditem2_line($taken, 10)])->assertSessionHasErrors('lines.1.po_item_id');

    expect(DeliveryItem::query()->where('delivery_id', $delivery->id)->count())->toBe(1)
        ->and(ditem2_delivered($free))->toBe('0');
});

it('lets a line be entered again after its row is voided, and then blocks it again', function () {
    [$po, [$line]] = ditem2_po([100]);
    $delivery = ditem2_delivery($po);
    $reviewer = ditem2_reviewer();

    ditem2_post($reviewer, $delivery, [ditem2_line($line, 500)])->assertSessionHasErrors('lines.0.quantity'); // 500 of 100: over-delivery without a flag
    ditem2_post($reviewer, $delivery, [ditem2_line($line, 50)])->assertSessionHasNoErrors();
    $wrong = DeliveryItem::query()->where('delivery_id', $delivery->id)->firstOrFail();

    // still taken
    ditem2_post($reviewer, $delivery, [ditem2_line($line, 40)])->assertSessionHasErrors('lines.0.po_item_id');

    $this->actingAs($reviewer)->post("/deliveries/{$delivery->id}/items/{$wrong->id}/void", ['reason' => 'Typed 50 instead of 40'])->assertSessionHasNoErrors();
    expect(ditem2_delivered($line))->toBe('0');

    // the voided row does not count: the corrected quantity can be entered
    ditem2_post($reviewer, $delivery, [ditem2_line($line, 40)])->assertSessionHasNoErrors();

    expect(DeliveryItem::query()->where('delivery_id', $delivery->id)->count())->toBe(2)
        ->and(DeliveryItem::query()->where('delivery_id', $delivery->id)->where('status', 'active')->count())->toBe(1)
        ->and(ditem2_delivered($line))->toBe('40');

    // and now it is taken again
    ditem2_post($reviewer, $delivery, [ditem2_line($line, 5)])->assertSessionHasErrors('lines.0.po_item_id');
});

// ---- more of a line arrives with a new delivery ----------------------------------------------------

it('gives totals 500 then 800 over two IARs, and "Fully delivered" after a third IAR of 200', function () {
    [$po, [$line]] = ditem2_po([1000]);
    $reviewer = ditem2_reviewer();

    $first = ditem2_delivery($po);
    $second = ditem2_delivery($po);
    $third = ditem2_delivery($po);

    // three different IARs
    expect(count(array_unique([$first->iar_number, $second->iar_number, $third->iar_number])))->toBe(3);

    ditem2_post($reviewer, $first, [ditem2_line($line, 500)])->assertSessionHasNoErrors();
    expect(ditem2_delivered($line))->toBe('500')
        ->and(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Partial)
        ->and(DeliveryTotals::progress($po->id)->label())->toBe('Partially delivered');

    ditem2_post($reviewer, $second, [ditem2_line($line, 300)])->assertSessionHasNoErrors();
    expect(ditem2_delivered($line))->toBe('800')
        ->and(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Partial);

    ditem2_post($reviewer, $third, [ditem2_line($line, 200)])->assertSessionHasNoErrors();
    expect(ditem2_delivered($line))->toBe('1000')
        ->and(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Full)
        ->and(DeliveryTotals::progress($po->id)->label())->toBe('Fully delivered');
});

// ---- the date comes from the delivery --------------------------------------------------------------

it('sets the row date to the date received of the delivery, and ignores a date sent by the client', function () {
    [$po, [$a, $b]] = ditem2_po([100, 100]);
    $delivery = ditem2_delivery($po, '2026-11-02');

    ditem2_post(ditem2_reviewer(), $delivery, [
        ditem2_line($a, 10, ['delivered_on' => '2026-11-20']),
        ditem2_line($b, 20),
    ])->assertSessionHasNoErrors();

    $rows = DeliveryItem::query()->where('delivery_id', $delivery->id)->get();

    expect($rows)->toHaveCount(2)
        ->and($rows->every(fn (DeliveryItem $row) => $row->delivered_on->toDateString() === '2026-11-02'))->toBeTrue()
        ->and($delivery->date_received->toDateString())->toBe('2026-11-02');
});

it('does not ask for a date per line: a save without any date works', function () {
    [$po, [$line]] = ditem2_po([100]);

    ditem2_post(ditem2_reviewer(), ditem2_delivery($po), [['po_item_id' => $line->id, 'quantity' => '5']])->assertSessionHasNoErrors();

    expect(DeliveryItem::query()->count())->toBe(1);
});

// ---- rows saved before the rule --------------------------------------------------------------------

it('leaves rows that already break the rule alone: they still count, and new saves for that line are blocked', function () {
    [$po, [$broken, $other]] = ditem2_po([1000, 50]);
    $delivery = ditem2_delivery($po);
    $reviewer = ditem2_reviewer();

    // like the dummy delivery 26-7017: 500 and 200 for one line in one delivery (made directly, as the old code allowed)
    DeliveryItem::factory()->create(['delivery_id' => $delivery->id, 'po_item_id' => $broken->id, 'quantity' => '500.000']);
    DeliveryItem::factory()->create(['delivery_id' => $delivery->id, 'po_item_id' => $broken->id, 'quantity' => '200.000']);

    expect(ditem2_delivered($broken))->toBe('700')
        ->and(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Partial);

    ditem2_post($reviewer, $delivery, [ditem2_line($broken, 10)])->assertSessionHasErrors('lines.0.po_item_id');
    ditem2_post($reviewer, $delivery, [ditem2_line($other, 10)])->assertSessionHasNoErrors();

    // nothing was migrated or voided
    expect(DeliveryItem::query()->where('po_item_id', $broken->id)->where('status', 'active')->count())->toBe(2)
        ->and(ditem2_delivered($broken))->toBe('700');

    // the panel lists both rows, says there are 2 active ones, and the form shows the line as taken
    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('deliveries/show')
            ->where('items.lines.0.delivered', '700')
            ->where('items.lines.0.active_here', 2)
            ->where('items.lines.0.entered_here', '700')
            ->has('items.lines.0.rows', 2));
});

// ---- the form and the panel ------------------------------------------------------------------------

it('sends the form the date received and marks the lines already entered on this IAR', function () {
    [$po, [$a, $b]] = ditem2_po([100, 100]);
    $delivery = ditem2_delivery($po, '2026-11-02');
    $reviewer = ditem2_reviewer();

    ditem2_post($reviewer, $delivery, [ditem2_line($a, 40)])->assertSessionHasNoErrors();

    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}/items/create")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('deliveries/items/create')
            ->where('sheet.date_received', '2026-11-02')
            ->missing('sheet.today')
            ->missing('sheet.min_date')
            ->where('sheet.lines.0.entered_here', '40')
            ->where('sheet.lines.1.entered_here', null));
});

it('shows "Record another delivery" only to people who hold delivery.encode (and only while the PO accepts a delivery)', function () {
    [$po] = ditem2_po([100]);
    $delivery = ditem2_delivery($po);

    $encoder = ditem2_encoder();
    $reviewer = ditem2_reviewer();
    $both = ditem2_user('MMS Staff', 'po-reviewer');
    $both->givePermissionTo(config('mms_access.job_presets.encoder.permissions'));

    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page->where('items.can_record_another', true)->where('items.record_blocked_reason', null));

    $this->actingAs($both)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page->where('items.can_record_another', true));

    // the PO Reviewer does not hold delivery.encode: no link, and no reason either ("Ask the Encoder")
    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page->where('items.can_record_another', false)->where('items.record_blocked_reason', null));

    // a closed PO accepts no delivery: the Encoder gets no link, but is told why
    $po->forceFill(['closed_at' => now()])->save();

    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.can_record_another', false)
            ->where('items.record_blocked_reason', fn ($reason) => str_contains((string) $reason, 'closed')));
});

// ---- the PO tab: history grouped by delivery -------------------------------------------------------

it('groups the PO tab history by delivery with the IAR, the status and the running total of the line', function () {
    [$po, [$line]] = ditem2_po([1000]);
    $reviewer = ditem2_reviewer();

    $first = ditem2_delivery($po);
    $second = ditem2_delivery($po);
    $third = ditem2_delivery($po);

    ditem2_post($reviewer, $first, [ditem2_line($line, 500)])->assertSessionHasNoErrors();
    ditem2_post($reviewer, $second, [ditem2_line($line, 300)])->assertSessionHasNoErrors();
    ditem2_post($reviewer, $third, [ditem2_line($line, 200)])->assertSessionHasNoErrors();

    $this->actingAs($reviewer)->get("/purchase-orders/{$po->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('purchaseOrder.deliveries.groups', 3)
            ->where('purchaseOrder.deliveries.groups.0.iar_number', $first->iar_number)
            ->where('purchaseOrder.deliveries.groups.0.status.value', 'complete')
            ->where('purchaseOrder.deliveries.groups.0.rows.0.quantity', '500')
            ->where('purchaseOrder.deliveries.groups.0.rows.0.running_total', '500')
            ->where('purchaseOrder.deliveries.groups.1.iar_number', $second->iar_number)
            ->where('purchaseOrder.deliveries.groups.1.rows.0.running_total', '800')
            ->where('purchaseOrder.deliveries.groups.2.iar_number', $third->iar_number)
            ->where('purchaseOrder.deliveries.groups.2.rows.0.running_total', '1000')
            // the last running total is the same number as the totals table
            ->where('purchaseOrder.deliveries.lines.0.delivered', '1000')
            ->where('purchaseOrder.deliveries.progress.value', 'full'));
});

it('shows a voided delivery in its group without a running total, and the next group carries on without it', function () {
    [$po, [$line]] = ditem2_po([1000]);
    $reviewer = ditem2_reviewer();

    $first = ditem2_delivery($po);
    $second = ditem2_delivery($po);
    $third = ditem2_delivery($po);

    ditem2_post($reviewer, $first, [ditem2_line($line, 500)])->assertSessionHasNoErrors();
    ditem2_post($reviewer, $second, [ditem2_line($line, 300)])->assertSessionHasNoErrors();
    ditem2_post($reviewer, $third, [ditem2_line($line, 200)])->assertSessionHasNoErrors();

    $this->actingAs(ditem2_encoder());
    app(DeliveryService::class)->void($second->fresh(), 'Entered by mistake');

    $this->actingAs($reviewer)->get("/purchase-orders/{$po->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrder.deliveries.groups.1.status.value', 'voided')
            ->where('purchaseOrder.deliveries.groups.1.rows.0.status.value', 'void')
            ->where('purchaseOrder.deliveries.groups.1.rows.0.running_total', null)
            ->where('purchaseOrder.deliveries.groups.2.rows.0.running_total', '700')
            ->where('purchaseOrder.deliveries.lines.0.delivered', '700'));
});

it('lists a delivery that has no quantities yet, with no rows', function () {
    [$po] = ditem2_po([100]);
    $delivery = ditem2_delivery($po);

    $this->actingAs(ditem2_reviewer())->get("/purchase-orders/{$po->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->has('purchaseOrder.deliveries.groups', 1)
            ->where('purchaseOrder.deliveries.groups.0.iar_number', $delivery->iar_number)
            ->has('purchaseOrder.deliveries.groups.0.rows', 0));
});