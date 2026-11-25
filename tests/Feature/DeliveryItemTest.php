<?php

use App\Enums\DeliveryItemStatus;
use App\Enums\DeliveryProgress;
use App\Enums\DeliveryStatus;
use App\Enums\IarSeriesType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Personnel;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\DeliveryItemService;
use App\Services\DeliveryService;
use App\Support\DeliveryTotals;
use App\Support\Quantity;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
| Step 6.1: delivered items (quantity per PO line and date) and the running totals.
| Helper names start with "ditem_" so they cannot clash with "delivery*", "ddoc_", "po_doc_" or "po_types_".
| A delivery is recorded the normal way (POST /deliveries, so its IAR number exists) and then set to "Documents complete"
| directly in the database: the completeness gate itself is tested in DeliveryDocumentTest.
| A rule that blocks an action (delivery not complete, closed PO, voided delivery, row already void) is refused by the
| Policy FIRST with a 403 and the reason; the Service checks the same rule again under the row locks (tested by calling it directly).
| First run: 24 of 29 passed; the 5 failures were wrong expectations ("form" error instead of 403), fixed here.
*/

beforeEach(function () {
    Storage::fake('local');
    Process::fake();
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

// ---- helpers ---------------------------------------------------------------------------------------

function ditem_user(string $role, ?string $preset = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    if ($preset !== null) {
        $user->givePermissionTo(config("mms_access.job_presets.{$preset}.permissions"));
    }

    return $user;
}

function ditem_reviewer(): User
{
    return ditem_user('MMS Staff', 'po-reviewer');
}

function ditem_encoder(): User
{
    return ditem_user('MMS Staff', 'encoder');
}

function ditem_today(): string
{
    return now()->setTimezone('Asia/Manila')->toDateString();
}

/** A verified PO with one PO line per number given (the quantity ordered). @param  list<int>  $ordered */
function ditem_po(array $ordered = [1000]): array
{
    $po = PurchaseOrder::factory()->create(['po_date' => '2026-01-05']);
    $po->forceFill(['status' => PurchaseOrderStatus::Verified])->save();

    $lines = array_map(fn (int $quantity) => PoItem::factory()->create(['purchase_order_id' => $po->id, 'quantity_ordered' => $quantity]), $ordered);

    return [$po->fresh(), $lines];
}

/** A delivery recorded the normal way, then set to "Documents complete" (or left "For uploading"). */
function ditem_delivery(PurchaseOrder $po, bool $complete = true): Delivery
{
    static $sequence = 9000;
    $sequence++;

    $year = (int) now()->setTimezone('Asia/Manila')->format('Y');
    $number = IarSeriesType::Regular->format($year, $sequence);

    test()->actingAs(ditem_encoder())->post('/deliveries', [
        'purchase_order_id' => $po->id,
        'invoice_no' => 'INV-'.$sequence,
        'iar_series' => 'regular',
        'iar_number' => $number,
        'fund_cluster' => '01',
        'amount' => '1000.00',
        'date_received' => ditem_today(),
        'inspectors' => [(string) Personnel::factory()->create(['is_inspector' => true, 'is_end_user' => false, 'is_active' => true])->id],
        'end_user' => (string) Personnel::factory()->create(['is_inspector' => false, 'is_end_user' => true, 'is_active' => true])->id,
    ])->assertSessionHasNoErrors();

    $delivery = Delivery::query()->where('iar_number', $number)->firstOrFail();

    if ($complete) {
        $delivery->forceFill(['status' => DeliveryStatus::Complete])->save();
    }

    return $delivery->fresh();
}

function ditem_line(PoItem $line, string|int $quantity, ?string $date = null, array $extra = []): array
{
    return ['po_item_id' => $line->id, 'quantity' => (string) $quantity, 'delivered_on' => $date ?? ditem_today(), ...$extra];
}

/** @param  list<array<string, mixed>>  $lines */
function ditem_post(User $user, Delivery $delivery, array $lines)
{
    return test()->actingAs($user)->post("/deliveries/{$delivery->id}/items", ['lines' => $lines]);
}

/** The figures the pages show for one line (computed from the rows, never stored). @return array<string, mixed> */
function ditem_totals(PurchaseOrder $po, PoItem $line): array
{
    return collect(app(DeliveryItemService::class)->poSummary($po->fresh())['lines'])->firstWhere('po_item_id', $line->id);
}

function ditem_rows(Delivery $delivery): int
{
    return DeliveryItem::query()->where('delivery_id', $delivery->id)->count();
}

// ---- totals ----------------------------------------------------------------------------------------

it('shows the right totals and progress after each save of 500, 300 and 200 pieces of 1,000', function () {
    [$po, [$line]] = ditem_po([1000]);
    $delivery = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    expect(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::NotDelivered);

    ditem_post($reviewer, $delivery, [ditem_line($line, 500)])->assertSessionHasNoErrors();
    expect(ditem_totals($po, $line))->toMatchArray(['ordered' => '1000', 'delivered' => '500', 'undelivered' => '500', 'over_by' => null])
        ->and(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Partial);

    ditem_post($reviewer, $delivery, [ditem_line($line, 300)])->assertSessionHasNoErrors();
    expect(ditem_totals($po, $line))->toMatchArray(['delivered' => '800', 'undelivered' => '200'])
        ->and(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Partial);

    ditem_post($reviewer, $delivery, [ditem_line($line, 200)])->assertSessionHasNoErrors();
    expect(ditem_totals($po, $line))->toMatchArray(['delivered' => '1000', 'undelivered' => '0', 'over_by' => null])
        ->and(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Full)
        ->and(ditem_rows($delivery))->toBe(3);
});

it('counts every delivery of the PO in the running total of a line', function () {
    [$po, [$line]] = ditem_po([1000]);
    $first = ditem_delivery($po);
    $second = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    ditem_post($reviewer, $first, [ditem_line($line, 500)])->assertSessionHasNoErrors();
    ditem_post($reviewer, $second, [ditem_line($line, 300)])->assertSessionHasNoErrors();

    expect(ditem_totals($po, $line))->toMatchArray(['delivered' => '800', 'undelivered' => '200']);
});

it('accepts fractional quantities and adds them without drift', function () {
    [$po, [$kg, $liter]] = ditem_po([5, 1]);
    $delivery = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    ditem_post($reviewer, $delivery, [ditem_line($kg, '2.5')])->assertSessionHasNoErrors();
    expect(ditem_totals($po, $kg))->toMatchArray(['delivered' => '2.5', 'undelivered' => '2.5']);

    ditem_post($reviewer, $delivery, [ditem_line($kg, '2.5')])->assertSessionHasNoErrors();
    expect(ditem_totals($po, $kg))->toMatchArray(['delivered' => '5', 'undelivered' => '0']);

    ditem_post($reviewer, $delivery, [ditem_line($liter, '0.1')])->assertSessionHasNoErrors();
    ditem_post($reviewer, $delivery, [ditem_line($liter, '0.2')])->assertSessionHasNoErrors();
    expect(ditem_totals($po, $liter))->toMatchArray(['delivered' => '0.3', 'undelivered' => '0.7']);
});

it('refuses a quantity that is zero, negative, has too many decimals or is not a number', function () {
    [$po, [$line]] = ditem_po([10]);
    $delivery = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    foreach (['0', '-1', '1.2345', 'abc', ''] as $bad) {
        ditem_post($reviewer, $delivery, [ditem_line($line, $bad)])->assertSessionHasErrors('lines.0.quantity');
    }

    expect(ditem_rows($delivery))->toBe(0);
});

it('refuses a delivery date in the future or before the PO date', function () {
    [$po, [$line]] = ditem_po([10]);
    $delivery = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    ditem_post($reviewer, $delivery, [ditem_line($line, 1, now()->addDays(3)->toDateString())])->assertSessionHasErrors('lines.0.delivered_on');
    ditem_post($reviewer, $delivery, [ditem_line($line, 1, '2026-01-04')])->assertSessionHasErrors('lines.0.delivered_on');

    expect(ditem_rows($delivery))->toBe(0);
});

it('keeps each line on its own date in one save', function () {
    [$po, [$a, $b]] = ditem_po([100, 50]);
    $delivery = ditem_delivery($po);

    ditem_post(ditem_reviewer(), $delivery, [ditem_line($a, 40, '2026-11-02'), ditem_line($b, 50, '2026-11-05')])->assertSessionHasNoErrors();

    expect(DeliveryItem::query()->where('po_item_id', $a->id)->first()->delivered_on->toDateString())->toBe('2026-11-02')
        ->and(DeliveryItem::query()->where('po_item_id', $b->id)->first()->delivered_on->toDateString())->toBe('2026-11-05');
});

// ---- over-delivery ---------------------------------------------------------------------------------

it('refuses an over-delivery without the flag and the reason, and accepts it with both', function () {
    [$po, [$line]] = ditem_po([10]);
    $delivery = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    ditem_post($reviewer, $delivery, [ditem_line($line, 4)])->assertSessionHasNoErrors();

    // 4 + 7 = 11 of 10: no flag
    ditem_post($reviewer, $delivery, [ditem_line($line, 7)])->assertSessionHasErrors('lines.0.quantity');
    // flag but no reason, and a too short reason
    ditem_post($reviewer, $delivery, [ditem_line($line, 7, null, ['over_delivery' => true])])->assertSessionHasErrors('lines.0.over_delivery_reason');
    ditem_post($reviewer, $delivery, [ditem_line($line, 7, null, ['over_delivery' => true, 'over_delivery_reason' => 'abc'])])->assertSessionHasErrors('lines.0.over_delivery_reason');

    expect(ditem_rows($delivery))->toBe(1);

    ditem_post($reviewer, $delivery, [ditem_line($line, 7, null, ['over_delivery' => true, 'over_delivery_reason' => 'Supplier added a free extra box'])])->assertSessionHasNoErrors();

    $row = DeliveryItem::query()->where('delivery_id', $delivery->id)->latest('id')->first();

    expect($row->over_delivery)->toBeTrue()
        ->and($row->over_delivery_reason)->toBe('Supplier added a free extra box')
        ->and(ditem_totals($po, $line))->toMatchArray(['delivered' => '11', 'undelivered' => '0', 'over_by' => '1']);

    $entry = Activity::query()->where('log_name', 'delivery')->where('event', 'delivery_items_recorded')->latest('id')->first();

    expect($entry->properties['over_delivery'][0])->toContain('Supplier added a free extra box');
});

it('ignores an over-delivery tick on a line that does not go over', function () {
    [$po, [$line]] = ditem_po([10]);
    $delivery = ditem_delivery($po);

    ditem_post(ditem_reviewer(), $delivery, [ditem_line($line, 5, null, ['over_delivery' => true, 'over_delivery_reason' => 'Not needed here'])])->assertSessionHasNoErrors();

    $row = DeliveryItem::query()->where('delivery_id', $delivery->id)->first();

    expect($row->over_delivery)->toBeFalse()->and($row->over_delivery_reason)->toBeNull();
});

// ---- all or nothing, and the PO of each line -------------------------------------------------------

it('saves two lines in one save, and nothing when one of them is refused', function () {
    [$po, [$a, $b]] = ditem_po([100, 10]);
    $delivery = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    ditem_post($reviewer, $delivery, [ditem_line($a, 40), ditem_line($b, 11)])->assertSessionHasErrors('lines.1.quantity');
    expect(ditem_rows($delivery))->toBe(0)
        ->and(ditem_totals($po, $a)['delivered'])->toBe('0');

    ditem_post($reviewer, $delivery, [ditem_line($a, 40), ditem_line($b, 10)])->assertSessionHasNoErrors();
    expect(ditem_rows($delivery))->toBe(2)
        ->and(Activity::query()->where('log_name', 'delivery')->where('event', 'delivery_items_recorded')->count())->toBe(1);
});

it('refuses the same line twice in one save', function () {
    [$po, [$line]] = ditem_po([100]);
    $delivery = ditem_delivery($po);

    ditem_post(ditem_reviewer(), $delivery, [ditem_line($line, 10), ditem_line($line, 20)])->assertSessionHasErrors('lines.1.po_item_id');

    expect(ditem_rows($delivery))->toBe(0);
});

it('refuses a line of another PO, and saves nothing from the same request', function () {
    [$po, [$mine]] = ditem_po([100]);
    [, [$foreign]] = ditem_po([100]);
    $delivery = ditem_delivery($po);

    ditem_post(ditem_reviewer(), $delivery, [ditem_line($mine, 10), ditem_line($foreign, 10)])->assertSessionHasErrors('lines.1.po_item_id');

    expect(ditem_rows($delivery))->toBe(0)->and(DeliveryItem::query()->count())->toBe(0);
});

// ---- which deliveries accept items -----------------------------------------------------------------

it('refuses items for a delivery that is not "Documents complete"', function () {
    [$po, [$line]] = ditem_po([100]);
    $delivery = ditem_delivery($po, complete: false);

    // The Policy refuses first: 403 with the reason.
    ditem_post(ditem_reviewer(), $delivery, [ditem_line($line, 10)])->assertForbidden();

    // The Service checks the same rule again under the locks (the second line of defence).
    $reviewer = ditem_reviewer();
    $this->actingAs($reviewer);

    expect(fn () => app(DeliveryItemService::class)->record($delivery, [ditem_line($line, 10)], $reviewer))->toThrow(ValidationException::class)
        ->and(ditem_rows($delivery))->toBe(0);
});

it('keeps items counted when the delivery is reopened, but accepts no new item and no void until it is complete again', function () {
    [$po, [$line]] = ditem_po([100]);
    $delivery = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    ditem_post($reviewer, $delivery, [ditem_line($line, 60)])->assertSessionHasNoErrors();
    $row = DeliveryItem::query()->where('delivery_id', $delivery->id)->firstOrFail();

    // reopened (the same state the 5.3 auto-reopen leaves)
    $delivery->forceFill(['status' => DeliveryStatus::ForUploading])->save();

    expect(ditem_totals($po, $line)['delivered'])->toBe('60');

    ditem_post($reviewer, $delivery, [ditem_line($line, 10)])->assertForbidden();
    $this->actingAs($reviewer)->post("/deliveries/{$delivery->id}/items/{$row->id}/void", ['reason' => 'Wrong quantity typed'])->assertForbidden();

    expect(ditem_rows($delivery))->toBe(1)->and(ditem_totals($po, $line)['delivered'])->toBe('60');

    $delivery->forceFill(['status' => DeliveryStatus::Complete])->save();

    ditem_post($reviewer, $delivery, [ditem_line($line, 10)])->assertSessionHasNoErrors();
    expect(ditem_totals($po, $line)['delivered'])->toBe('70');
});

it('refuses items for a closed PO or a PO that is no longer verified', function () {
    [$po, [$line]] = ditem_po([100]);
    $delivery = ditem_delivery($po);

    $po->forceFill(['closed_at' => now()])->save();
    ditem_post(ditem_reviewer(), $delivery, [ditem_line($line, 10)])->assertForbidden();

    $po->forceFill(['closed_at' => null, 'status' => PurchaseOrderStatus::Cancelled])->save();
    ditem_post(ditem_reviewer(), $delivery, [ditem_line($line, 10)])->assertForbidden();

    expect(ditem_rows($delivery))->toBe(0);
})->skip(fn () => ! defined(PurchaseOrderStatus::class.'::Cancelled'), 'PurchaseOrderStatus has no Cancelled case.');

// ---- a voided delivery -----------------------------------------------------------------------------

it('drops the rows of a voided delivery out of every total and refuses new items for it', function () {
    [$po, [$line]] = ditem_po([1000]);
    $first = ditem_delivery($po);
    $second = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    ditem_post($reviewer, $first, [ditem_line($line, 500)])->assertSessionHasNoErrors();
    ditem_post($reviewer, $second, [ditem_line($line, 300)])->assertSessionHasNoErrors();
    expect(ditem_totals($po, $line)['delivered'])->toBe('800');

    $this->actingAs(ditem_encoder());
    app(DeliveryService::class)->void($first->fresh(), 'Entered by mistake');

    // the rows stay on record, marked void with the delivery's reason, and no longer count
    $row = DeliveryItem::query()->where('delivery_id', $first->id)->firstOrFail();

    expect($row->status)->toBe(DeliveryItemStatus::Voided)
        ->and($row->void_reason)->toBe('Delivery voided: Entered by mistake')
        ->and($row->voided_by)->not->toBeNull()
        ->and(ditem_totals($po, $line))->toMatchArray(['delivered' => '300', 'undelivered' => '700'])
        ->and(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Partial)
        ->and(Activity::query()->where('log_name', 'delivery')->where('event', 'delivery_items_voided')->count())->toBe(1);

    ditem_post($reviewer, $first->fresh(), [ditem_line($line, 10)])->assertForbidden();
    expect(ditem_rows($first))->toBe(1);
});

it('does not count the rows of a voided delivery even if a row is still active', function () {
    [$po, [$line]] = ditem_po([100]);
    $delivery = ditem_delivery($po);

    DeliveryItem::factory()->create(['delivery_id' => $delivery->id, 'po_item_id' => $line->id, 'quantity' => '40.000']);
    expect(ditem_totals($po, $line)['delivered'])->toBe('40');

    $delivery->forceFill(['status' => DeliveryStatus::Voided])->save();
    expect(ditem_totals($po, $line)['delivered'])->toBe('0');
});

// ---- voiding one row -------------------------------------------------------------------------------

it('voids a row with a reason and updates the totals; the row stays on record', function () {
    [$po, [$line]] = ditem_po([1000]);
    $delivery = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    ditem_post($reviewer, $delivery, [ditem_line($line, 500)])->assertSessionHasNoErrors();
    ditem_post($reviewer, $delivery, [ditem_line($line, 300)])->assertSessionHasNoErrors();
    $wrong = DeliveryItem::query()->where('delivery_id', $delivery->id)->orderBy('id')->firstOrFail();

    $this->actingAs($reviewer)->post("/deliveries/{$delivery->id}/items/{$wrong->id}/void", ['reason' => 'Typed 500 instead of 50'])->assertSessionHasNoErrors();

    $wrong->refresh();

    expect($wrong->status)->toBe(DeliveryItemStatus::Voided)
        ->and($wrong->void_reason)->toBe('Typed 500 instead of 50')
        ->and($wrong->voided_by)->toBe($reviewer->id)
        ->and($wrong->voided_at)->not->toBeNull()
        ->and(ditem_totals($po, $line))->toMatchArray(['delivered' => '300', 'undelivered' => '700'])
        ->and(ditem_rows($delivery))->toBe(2);

    $entry = Activity::query()->where('log_name', 'delivery')->where('event', 'status_changed')->where('subject_type', (new DeliveryItem)->getMorphClass())->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['new']['status'])->toBe('Voided')
        ->and($entry->properties['reason'])->toBe('Typed 500 instead of 50')
        ->and($entry->properties['delivered_total_after'])->toBe('300');

    // entered again after the void
    ditem_post($reviewer, $delivery, [ditem_line($line, 50)])->assertSessionHasNoErrors();
    expect(ditem_totals($po, $line)['delivered'])->toBe('350');
});

it('needs a reason of 5 to 500 characters to void a row, and a row cannot be voided twice', function () {
    [$po, [$line]] = ditem_po([100]);
    $delivery = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    ditem_post($reviewer, $delivery, [ditem_line($line, 10)])->assertSessionHasNoErrors();
    $row = DeliveryItem::query()->where('delivery_id', $delivery->id)->firstOrFail();

    $this->actingAs($reviewer)->post("/deliveries/{$delivery->id}/items/{$row->id}/void", ['reason' => 'no'])->assertSessionHasErrors('reason');
    $this->actingAs($reviewer)->post("/deliveries/{$delivery->id}/items/{$row->id}/void", ['reason' => str_repeat('x', 501)])->assertSessionHasErrors('reason');
    expect($row->fresh()->status)->toBe(DeliveryItemStatus::Active);

    $this->actingAs($reviewer)->post("/deliveries/{$delivery->id}/items/{$row->id}/void", ['reason' => 'Entered by mistake'])->assertSessionHasNoErrors();
    $this->actingAs($reviewer)->post("/deliveries/{$delivery->id}/items/{$row->id}/void", ['reason' => 'Entered by mistake'])->assertForbidden();
});

it('gives 404 for a row that belongs to another delivery', function () {
    [$po, [$line]] = ditem_po([100]);
    $mine = ditem_delivery($po);
    $other = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    ditem_post($reviewer, $other, [ditem_line($line, 10)])->assertSessionHasNoErrors();
    $row = DeliveryItem::query()->where('delivery_id', $other->id)->firstOrFail();

    $this->actingAs($reviewer)->post("/deliveries/{$mine->id}/items/{$row->id}/void", ['reason' => 'Entered by mistake'])->assertNotFound();

    expect($row->fresh()->status)->toBe(DeliveryItemStatus::Active);
});

// ---- who may ---------------------------------------------------------------------------------------

it('gives 403 to everyone without delivery.update-items, for saving, voiding and the form', function () {
    [$po, [$line]] = ditem_po([100]);
    $delivery = ditem_delivery($po);

    ditem_post(ditem_reviewer(), $delivery, [ditem_line($line, 10)])->assertSessionHasNoErrors();
    $row = DeliveryItem::query()->where('delivery_id', $delivery->id)->firstOrFail();

    $blocked = [
        'Encoder' => ditem_encoder(),
        'MMS Staff (no job)' => ditem_user('MMS Staff'),
        'Inventory Team' => ditem_user('MMS Staff', 'inventory-team'),
        'Procurement' => ditem_user('Procurement'),
        'Admin' => ditem_user('Admin'),
        'MMS Head' => ditem_user('MMS Head'),
        'Finance' => ditem_user('Finance'),
    ];

    foreach ($blocked as $who => $user) {
        $this->actingAs($user)->post("/deliveries/{$delivery->id}/items", ['lines' => [ditem_line($line, 1)]])->assertForbidden();
        $this->actingAs($user)->post("/deliveries/{$delivery->id}/items/{$row->id}/void", ['reason' => 'Entered by mistake'])->assertForbidden();
        $this->actingAs($user)->get("/deliveries/{$delivery->id}/items/create")->assertForbidden();
    }

    expect(ditem_rows($delivery))->toBe(1)->and($row->fresh()->status)->toBe(DeliveryItemStatus::Active);
});

it('keeps the Encoder rules of step 5.3 unchanged', function () {
    [$po] = ditem_po([100]);
    $forUploading = ditem_delivery($po, complete: false);
    $complete = ditem_delivery($po);
    $encoder = ditem_encoder();
    $reviewer = ditem_reviewer();

    // the Encoder still uploads while "For uploading" and still cannot while "Documents complete"
    expect(Gate::forUser($encoder)->allows('uploadDocuments', $forUploading))->toBeTrue()
        ->and(Gate::forUser($encoder)->allows('uploadDocuments', $complete))->toBeFalse()
        ->and(Gate::forUser($encoder)->allows('voidDocument', $forUploading))->toBeTrue()
        ->and(Gate::forUser($encoder)->allows('completeDocuments', $forUploading))->toBeTrue()
        // and still has no part in the items
        ->and(Gate::forUser($encoder)->allows('updateItems', $complete))->toBeFalse()
        // the PO Reviewer has no part in the files
        ->and(Gate::forUser($reviewer)->allows('uploadDocuments', $forUploading))->toBeFalse()
        ->and(Gate::forUser($reviewer)->allows('voidDocument', $forUploading))->toBeFalse()
        ->and(Gate::forUser($reviewer)->allows('updateItems', $complete))->toBeTrue()
        ->and(Gate::forUser($reviewer)->allows('updateItems', $forUploading))->toBeFalse();
});

it('adds no permission: delivery.update-items belongs to the PO Reviewer job only', function () {
    expect(config('mms_access.job_presets.po-reviewer.permissions'))->toContain('delivery.update-items')
        ->and(config('mms_access.job_presets.encoder.permissions'))->not->toContain('delivery.update-items')
        ->and(config('mms_access.permissions'))->toContain('delivery.update-items');
})->skip(fn () => ! in_array('delivery.update-items', (array) config('mms_access.job_presets.po-reviewer.permissions'), true), 'The PO Reviewer preset does not hold delivery.update-items.');

// ---- progress is derived, never stored -------------------------------------------------------------

it('derives not delivered, partial and full at the right moments, and never stores them', function () {
    [$po, [$a, $b]] = ditem_po([10, 10]);
    $delivery = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    expect(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::NotDelivered);

    ditem_post($reviewer, $delivery, [ditem_line($a, 10)])->assertSessionHasNoErrors();
    expect(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Partial);

    ditem_post($reviewer, $delivery, [ditem_line($b, 4)])->assertSessionHasNoErrors();
    expect(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Partial);

    ditem_post($reviewer, $delivery, [ditem_line($b, 6)])->assertSessionHasNoErrors();
    expect(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Full);

    // the stored status never moved, and no total is stored on the PO or its lines
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Verified)
        ->and(Schema::hasColumn('po_items', 'quantity_delivered'))->toBeFalse()
        ->and(Schema::hasColumn('po_items', 'delivered'))->toBeFalse()
        ->and(Schema::hasColumn('purchase_orders', 'progress'))->toBeFalse();
});

it('does not let one over-delivered line hide a line that is still missing', function () {
    [$po, [$a, $b]] = ditem_po([10, 10]);
    $delivery = ditem_delivery($po);

    ditem_post(ditem_reviewer(), $delivery, [ditem_line($a, 20, null, ['over_delivery' => true, 'over_delivery_reason' => 'Double shipment'])])->assertSessionHasNoErrors();

    expect(DeliveryTotals::progress($po->id))->toBe(DeliveryProgress::Partial)
        ->and(ditem_totals($po, $a)['over_by'])->toBe('10')
        ->and(ditem_totals($po, $b)['delivered'])->toBe('0');
});

it('gives the progress of a whole page of POs with the same answers', function () {
    [$one, [$a]] = ditem_po([10]);
    [$two] = ditem_po([10]);
    $delivery = ditem_delivery($one);

    ditem_post(ditem_reviewer(), $delivery, [ditem_line($a, 10)])->assertSessionHasNoErrors();

    $progress = DeliveryTotals::progressFor([$one->id, $two->id]);

    expect($progress[$one->id])->toBe(DeliveryProgress::Full)->and($progress[$two->id])->toBe(DeliveryProgress::NotDelivered);
});

// ---- audit and the quantity helper -----------------------------------------------------------------

it('writes one readable audit entry per save, in the delivery log', function () {
    [$po, [$a, $b]] = ditem_po([100, 100]);
    $delivery = ditem_delivery($po);

    ditem_post(ditem_reviewer(), $delivery, [ditem_line($a, 40), ditem_line($b, 25)])->assertSessionHasNoErrors();

    $entries = Activity::query()->where('log_name', 'delivery')->where('event', 'delivery_items_recorded')->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()->subject_id)->toBe($delivery->id)
        ->and($entries->first()->properties->new->delivered_items)->toContain($a->description)->toContain($b->description)
        ->and($entries->first()->properties->purchase_order_id)->toBe($po->id);
});

it('turns quantities into whole thousandths and back', function () {
    expect(Quantity::thousandths('2.5'))->toBe(2500)
        ->and(Quantity::thousandths('0.1') + Quantity::thousandths('0.2'))->toBe(300)
        ->and(Quantity::decimal(2500))->toBe('2.500')
        ->and(Quantity::format(2500))->toBe('2.5')
        ->and(Quantity::format(500000))->toBe('500')
        ->and(Quantity::format(0))->toBe('0')
        ->and(Quantity::isValidInput('2.5'))->toBeTrue()
        ->and(Quantity::isValidInput('0'))->toBeFalse()
        ->and(Quantity::isValidInput('1.2345'))->toBeFalse()
        ->and(Quantity::isValidInput('-3'))->toBeFalse();
});

// ---- the pages -------------------------------------------------------------------------------------

it('sends the update form, the delivery panel, the PO tab and the PO list badge their numbers', function () {
    [$po, [$a, $b]] = ditem_po([1000, 10]);
    $delivery = ditem_delivery($po);
    $reviewer = ditem_reviewer();

    ditem_post($reviewer, $delivery, [ditem_line($a, 500)])->assertSessionHasNoErrors();

    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}/items/create")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('deliveries/items/create')
            ->has('sheet.lines', 2)
            ->where('sheet.lines.0.delivered', '500')
            ->where('sheet.lines.0.undelivered', '500')
            ->where('sheet.progress.value', 'partial'));

    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('deliveries/show')
            ->where('items.can_update', true)
            ->has('items.lines', 2)
            ->has('items.lines.0.rows', 1)
            ->where('items.lines.0.rows.0.quantity', '500')
            ->where('items.lines.0.rows.0.can_void', true));

    $this->actingAs($reviewer)->get("/purchase-orders/{$po->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrder.deliveries.progress.value', 'partial')
            ->has('purchaseOrder.deliveries.lines', 2)
            ->where('purchaseOrder.status.value', 'verified'));

    $this->actingAs($reviewer)->get('/purchase-orders')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrders.data.0.progress.value', 'partial')
            ->where('purchaseOrders.data.0.status.value', 'verified'));
});

it('hides the Update button and says why when the delivery is not complete, only for people who could act', function () {
    [$po] = ditem_po([10]);
    $delivery = ditem_delivery($po, complete: false);

    $this->actingAs(ditem_reviewer())->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.can_update', false)
            ->where('items.locked_reason', fn ($reason) => str_contains((string) $reason, 'complete')));

    // a person who cannot update items is not told why
    $this->actingAs(ditem_encoder())->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page->where('items.can_update', false)->where('items.locked_reason', null));
});