<?php

use App\Enums\DeliveryStatus;
use App\Enums\IarSeriesType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\IarNumber;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\DeliveryService;
use App\Support\DeliveryRule;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
| Step 6.1e: recording a delivery on a PO that is already fully delivered.
|   config mms.delivery.allow_after_full = true  (default): nothing is blocked, the screens only warn;
|   config mms.delivery.allow_after_full = false: DeliveryRule refuses a NEW delivery for a Full PO (Policy 403 + reason,
|   and DeliveryService::create() again under the PO row lock). "Full" is DeliveryProgress::Full, derived by DeliveryTotals.
| Helper names start with "dfull_" so they cannot clash with the helpers of the other delivery test files.
| Written without running PHP in the writing session: if the first run shows a small error in a test, paste it.
*/

const DFULL_SENTENCE = 'This PO is already fully delivered, so no new delivery can be recorded.';

beforeEach(function () {
    Storage::fake('local');
    Process::fake();
    config(['mms.delivery.allow_after_full' => true]);
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

// ---- helpers ---------------------------------------------------------------------------------------

function dfull_user(string $preset): User
{
    $user = User::factory()->create();
    $user->assignRole('MMS Staff');
    $user->givePermissionTo(config("mms_access.job_presets.{$preset}.permissions"));

    return $user;
}

function dfull_encoder(): User
{
    return dfull_user('encoder');
}

function dfull_reviewer(): User
{
    return dfull_user('po-reviewer');
}

function dfull_sequence(): int
{
    static $sequence = 8000;

    return ++$sequence;
}

/** A verified PO with ONE line: 1,000 ordered at 10.00. @return array{0: PurchaseOrder, 1: PoItem} */
function dfull_po(string $status = 'verified'): array
{
    $po = PurchaseOrder::factory()->create(['po_date' => '2026-01-05']);
    $po->forceFill(['status' => PurchaseOrderStatus::from($status)])->save();

    $line = PoItem::factory()->create(['purchase_order_id' => $po->id, 'quantity_ordered' => 1000, 'unit_price' => '10.00']);

    return [$po->fresh(), $line];
}

/** A valid request body for POST /deliveries (also used for DeliveryService::create()). */
function dfull_payload(PurchaseOrder $po): array
{
    $sequence = dfull_sequence();
    $year = (int) now()->setTimezone('Asia/Manila')->format('Y');

    return [
        'purchase_order_id' => $po->id,
        'invoice_no' => 'INV-'.$sequence,
        'iar_series' => 'regular',
        'iar_number' => IarSeriesType::Regular->format($year, $sequence),
        'fund_cluster' => '01',
        'amount' => '1000.00',
        'date_received' => now()->setTimezone('Asia/Manila')->toDateString(),
        'inspectors' => [['name' => 'Ana Inspector', 'position' => 'Pharmacist II']],
        'end_user' => ['name' => 'Cora Enduser', 'position' => 'Nurse IV'],
    ];
}

/** A delivery recorded the normal way (POST /deliveries). It starts "For uploading"; pass $complete to set "Documents complete". */
function dfull_record(PurchaseOrder $po, bool $complete = false, ?User $encoder = null): Delivery
{
    $payload = dfull_payload($po);

    test()->actingAs($encoder ?? dfull_encoder())->post('/deliveries', $payload)->assertSessionHasNoErrors();

    $delivery = Delivery::query()->where('iar_number', $payload['iar_number'])->firstOrFail();

    if ($complete) {
        $delivery->forceFill(['status' => DeliveryStatus::Complete])->save();
    }

    return $delivery->fresh();
}

/** The reviewer enters a quantity for one line of a "Documents complete" delivery. */
function dfull_enter(Delivery $delivery, PoItem $line, int $quantity): void
{
    test()->actingAs(dfull_reviewer())
        ->post("/deliveries/{$delivery->id}/items", ['lines' => [['po_item_id' => $line->id, 'quantity' => (string) $quantity]]])
        ->assertSessionHasNoErrors();
}

/** A PO that shows "Fully delivered" (1,000 of 1,000) through one complete delivery. @return array{0: PurchaseOrder, 1: PoItem, 2: Delivery} */
function dfull_full_po(): array
{
    [$po, $line] = dfull_po();
    $delivery = dfull_record($po, complete: true);
    dfull_enter($delivery, $line, 1000);

    return [$po, $line, $delivery];
}

/** A PO that is only partly delivered (500 of 1,000). @return array{0: PurchaseOrder, 1: PoItem, 2: Delivery} */
function dfull_partial_po(): array
{
    [$po, $line] = dfull_po();
    $delivery = dfull_record($po, complete: true);
    dfull_enter($delivery, $line, 500);

    return [$po, $line, $delivery];
}

// ---- default (switch on): nothing is blocked, the screens warn --------------------------------------

it('allows recording on a fully delivered PO by default and sends the fully delivered flag', function () {
    [$po, , $delivery] = dfull_full_po();
    $encoder = dfull_encoder();

    // PO page, Deliveries tab
    $this->actingAs($encoder)->get("/purchase-orders/{$po->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrder.deliveries.progress.value', 'full')
            ->where('purchaseOrder.deliveries.fully_delivered', true)
            ->where('purchaseOrder.deliveries.can_record', true)
            ->where('purchaseOrder.deliveries.blocked_reason', null));

    // Delivery page, Delivered items panel
    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.fully_delivered', true)
            ->where('items.can_record_another', true)
            ->where('items.record_blocked_reason', null));

    // The record form
    $this->actingAs($encoder)->get("/deliveries/create?purchase_order={$po->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('fullyDelivered', true));

    // And a second delivery really can be recorded
    $second = dfull_record($po, encoder: $encoder);

    expect(Delivery::query()->where('purchase_order_id', $po->id)->count())->toBe(2)
        ->and($second->status)->toBe(DeliveryStatus::ForUploading);
});

it('sends the fully delivered flag as false for a PO that is not fully delivered', function () {
    [$po, , $delivery] = dfull_partial_po();
    $encoder = dfull_encoder();

    $this->actingAs($encoder)->get("/purchase-orders/{$po->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrder.deliveries.progress.value', 'partial')
            ->where('purchaseOrder.deliveries.fully_delivered', false)
            ->where('purchaseOrder.deliveries.can_record', true));

    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.fully_delivered', false)
            ->where('items.can_record_another', true));

    $this->actingAs($encoder)->get("/deliveries/create?purchase_order={$po->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('fullyDelivered', false));

    // a PO with no delivery at all is not full either
    [$empty] = dfull_po();

    $this->actingAs($encoder)->get("/deliveries/create?purchase_order={$empty->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('fullyDelivered', false));
});

it('blocks nothing in the rule while the switch is on', function () {
    [$po] = dfull_full_po();

    expect(DeliveryRule::allowAfterFull())->toBeTrue()
        ->and(DeliveryRule::addBlockReason($po))->toBeNull();
});

// ---- switch off: a Full PO takes no new delivery -----------------------------------------------------

it('with the switch off refuses a new delivery for a fully delivered PO with 403 and the reason', function () {
    [$po, , $delivery] = dfull_full_po();
    $encoder = dfull_encoder();
    $reviewer = dfull_reviewer();

    config(['mms.delivery.allow_after_full' => false]);

    $this->actingAs($encoder)->get("/deliveries/create?purchase_order={$po->id}")->assertForbidden();
    $this->actingAs($encoder)->post('/deliveries', dfull_payload($po))->assertForbidden();

    $inspect = Gate::forUser($encoder)->inspect('create', [Delivery::class, $po]);

    expect($inspect->allowed())->toBeFalse()
        ->and($inspect->message())->toBe(DFULL_SENTENCE)
        ->and(DeliveryRule::addBlockReason($po))->toBe(DFULL_SENTENCE);

    // PO page: button hidden, the Encoder is told why, the flag is still true
    $this->actingAs($encoder)->get("/purchase-orders/{$po->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrder.deliveries.fully_delivered', true)
            ->where('purchaseOrder.deliveries.can_record', false)
            ->where('purchaseOrder.deliveries.blocked_reason', DFULL_SENTENCE));

    // Delivery page: same, and a person without delivery.encode is not given the reason
    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.can_record_another', false)
            ->where('items.record_blocked_reason', DFULL_SENTENCE));

    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.can_record_another', false)
            ->where('items.record_blocked_reason', null));

    // nothing was saved
    expect(Delivery::query()->where('purchase_order_id', $po->id)->count())->toBe(1);
});

it('with the switch off the Service also refuses, under the PO lock, and saves nothing', function () {
    [$po] = dfull_full_po();
    $encoder = dfull_encoder();

    config(['mms.delivery.allow_after_full' => false]);
    $this->actingAs($encoder);

    $message = null;

    try {
        app(DeliveryService::class)->create($po, dfull_payload($po));
    } catch (ValidationException $e) {
        $message = $e->errors()['form'][0] ?? null;
    }

    expect($message)->toBe(DFULL_SENTENCE)
        ->and(Delivery::query()->where('purchase_order_id', $po->id)->count())->toBe(1)
        ->and(IarNumber::query()->count())->toBe(1)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Verified);
});

it('with the switch off still lets people edit, void and enter items on the existing deliveries', function () {
    [$po, , $complete] = dfull_full_po();
    $encoder = dfull_encoder();
    $reviewer = dfull_reviewer();

    // a second delivery recorded while the switch was on; it is still "For uploading"
    $open = dfull_record($po, encoder: $encoder);

    config(['mms.delivery.allow_after_full' => false]);

    expect(Gate::forUser($encoder)->allows('update', $open))->toBeTrue()
        ->and(Gate::forUser($encoder)->allows('void', $open))->toBeTrue()
        ->and(Gate::forUser($encoder)->allows('void', $complete))->toBeTrue()
        ->and(Gate::forUser($reviewer)->allows('updateItems', $complete))->toBeTrue();

    $this->actingAs($encoder)->get("/deliveries/{$open->id}/edit")->assertOk();

    $this->actingAs($encoder)
        ->post("/deliveries/{$open->id}/void", ['reason' => 'Entered by mistake'])
        ->assertSessionHasNoErrors();

    expect($open->fresh()->status)->toBe(DeliveryStatus::Voided);
});

it('with the switch off makes recording available again when the delivery that made the PO full is voided', function () {
    [$po, , $delivery] = dfull_full_po();
    $encoder = dfull_encoder();

    config(['mms.delivery.allow_after_full' => false]);

    expect(Gate::forUser($encoder)->allows('create', [Delivery::class, $po]))->toBeFalse();

    $this->actingAs($encoder)
        ->post("/deliveries/{$delivery->id}/void", ['reason' => 'Wrong delivery recorded'])
        ->assertSessionHasNoErrors();

    expect(Gate::forUser($encoder)->allows('create', [Delivery::class, $po->fresh()]))->toBeTrue();

    $this->actingAs($encoder)->get("/purchase-orders/{$po->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrder.deliveries.progress.value', 'not_delivered')
            ->where('purchaseOrder.deliveries.fully_delivered', false)
            ->where('purchaseOrder.deliveries.can_record', true));

    $this->actingAs($encoder)->get("/deliveries/create?purchase_order={$po->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('fullyDelivered', false));

    // and a new delivery can really be recorded
    dfull_record($po, encoder: $encoder);

    expect(Delivery::query()->where('purchase_order_id', $po->id)->count())->toBe(2);
});

it('with the switch off makes recording available again when the quantity row that made the PO full is voided', function () {
    [$po, , $delivery] = dfull_full_po();
    $encoder = dfull_encoder();

    config(['mms.delivery.allow_after_full' => false]);

    expect(Gate::forUser($encoder)->allows('create', [Delivery::class, $po]))->toBeFalse();

    $row = DeliveryItem::query()->where('delivery_id', $delivery->id)->firstOrFail();

    $this->actingAs(dfull_reviewer())
        ->post("/deliveries/{$delivery->id}/items/{$row->id}/void", ['reason' => 'Quantity was wrong'])
        ->assertSessionHasNoErrors();

    expect(Gate::forUser($encoder)->allows('create', [Delivery::class, $po->fresh()]))->toBeTrue();
});

// ---- a PO that is not full is never affected ---------------------------------------------------------

it('never affects a PO that is not fully delivered, with the switch on or off', function (bool $allowAfterFull) {
    config(['mms.delivery.allow_after_full' => $allowAfterFull]);

    [$partial] = dfull_partial_po();
    [$empty] = dfull_po();
    $encoder = dfull_encoder();

    foreach ([$partial, $empty] as $po) {
        expect(DeliveryRule::addBlockReason($po))->toBeNull()
            ->and(Gate::forUser($encoder)->allows('create', [Delivery::class, $po]))->toBeTrue();

        $this->actingAs($encoder)->get("/deliveries/create?purchase_order={$po->id}")->assertOk();

        $this->actingAs($encoder)->get("/purchase-orders/{$po->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('purchaseOrder.deliveries.fully_delivered', false)
                ->where('purchaseOrder.deliveries.can_record', true)
                ->where('purchaseOrder.deliveries.blocked_reason', null));

        dfull_record($po, encoder: $encoder);
    }
})->with([
    'switch on' => [true],
    'switch off' => [false],
]);

it('keeps the older PO block reasons first, with the switch off', function () {
    config(['mms.delivery.allow_after_full' => false]);

    [$draft] = dfull_po('draft');

    expect(DeliveryRule::addBlockReason($draft))->toContain('only for a verified PO')
        ->and(DeliveryRule::addBlockReason($draft))->not->toBe(DFULL_SENTENCE);
});