<?php

use App\Enums\DeliveryItemStatus;
use App\Enums\DeliveryStatus;
use App\Enums\IarSeriesType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Personnel;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\DeliveryAmounts;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

/*
| Step 6.1d: the money checks around a delivery (DeliveryAmounts). A CHECK, NEVER A BLOCK:
|   - the delivery page shows the value of the quantities entered (quantity x unit price) next to the typed Amount;
|   - the PO's Deliveries tab shows the total of the typed Amounts of the deliveries that are not voided next to the PO total.
| Helper names start with "damt_" so they cannot clash with the helpers of the other delivery test files.
| Written without running PHP in the writing session: if the first run shows a small error in a test, paste it.
*/

beforeEach(function () {
    Storage::fake('local');
    Process::fake();
    config(['mms.delivery.amount_tolerance_cents' => 0]);
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

// ---- helpers ---------------------------------------------------------------------------------------

function damt_user(string $preset): User
{
    $user = User::factory()->create();
    $user->assignRole('MMS Staff');
    $user->givePermissionTo(config("mms_access.job_presets.{$preset}.permissions"));

    return $user;
}

function damt_reviewer(): User
{
    return damt_user('po-reviewer');
}

function damt_encoder(): User
{
    return damt_user('encoder');
}

/** A verified PO with ONE line: 1,000 ordered at 10.00, so the PO total is 10,000.00. @return array{0: PurchaseOrder, 1: PoItem} */
function damt_po(): array
{
    $po = PurchaseOrder::factory()->create(['po_date' => '2026-01-05']);
    $po->forceFill(['status' => PurchaseOrderStatus::Verified])->save();

    $line = PoItem::factory()->create(['purchase_order_id' => $po->id, 'quantity_ordered' => 1000, 'unit_price' => '10.00']);

    return [$po->fresh(), $line];
}

/**
 * A delivery recorded the normal way (POST /deliveries), then set to "Documents complete" with the typed Amount given in
 * centavos (the form always sends 1000.00; the check reads the saved amount_cents).
 */
function damt_delivery(PurchaseOrder $po, int $amountCents = 100000): Delivery
{
    static $sequence = 7000;
    $sequence++;

    $year = (int) now()->setTimezone('Asia/Manila')->format('Y');
    $number = IarSeriesType::Regular->format($year, $sequence);

    test()->actingAs(damt_encoder())->post('/deliveries', [
        'purchase_order_id' => $po->id,
        'invoice_no' => 'INV-'.$sequence,
        'iar_series' => 'regular',
        'iar_number' => $number,
        'fund_cluster' => '01',
        'amount' => '1000.00',
        'date_received' => now()->setTimezone('Asia/Manila')->toDateString(),
        'inspectors' => [(string) Personnel::factory()->create(['is_inspector' => true, 'is_end_user' => false, 'is_active' => true])->id],
        'end_user' => (string) Personnel::factory()->create(['is_inspector' => false, 'is_end_user' => true, 'is_active' => true])->id,
    ])->assertSessionHasNoErrors();

    $delivery = Delivery::query()->where('iar_number', $number)->firstOrFail();
    $delivery->forceFill(['status' => DeliveryStatus::Complete, 'amount_cents' => $amountCents])->save();

    return $delivery->fresh();
}

/** @param  list<array<string, mixed>>  $lines */
function damt_post(User $user, Delivery $delivery, array $lines)
{
    return test()->actingAs($user)->post("/deliveries/{$delivery->id}/items", ['lines' => $lines]);
}

function damt_line(PoItem $line, string|int $quantity): array
{
    return ['po_item_id' => $line->id, 'quantity' => (string) $quantity];
}

// ---- the maths -------------------------------------------------------------------------------------

it('works out the value of one row in whole centavos, rounded half up', function () {
    expect(DeliveryAmounts::valueCents('500', '10.00'))->toBe(500000)
        ->and(DeliveryAmounts::valueCents('2.5', '10.50'))->toBe(2625)
        ->and(DeliveryAmounts::valueCents('0.333', '10.00'))->toBe(333)
        ->and(DeliveryAmounts::valueCents('0.002', '12.50'))->toBe(3)
        ->and(DeliveryAmounts::valueCents('0', '10.00'))->toBe(0);
});

it('compares the typed Amount with the value, with the tolerance in centavos', function () {
    expect(DeliveryAmounts::compare(500000, null, 0))->toBe(['state' => 'none', 'difference_cents' => null])
        ->and(DeliveryAmounts::compare(500000, 500000, 0))->toBe(['state' => 'matches', 'difference_cents' => 0])
        ->and(DeliveryAmounts::compare(100000, 500000, 0))->toBe(['state' => 'differs', 'difference_cents' => -400000])
        ->and(DeliveryAmounts::compare(100000, 500000, 400000))->toBe(['state' => 'matches', 'difference_cents' => -400000])
        ->and(DeliveryAmounts::compare(100000, 500000, 399999)['state'])->toBe('differs');
});

// ---- the delivery page -----------------------------------------------------------------------------

it('shows no value and the state "none" before any quantity is entered', function () {
    [$po] = damt_po();
    $delivery = damt_delivery($po);

    $this->actingAs(damt_reviewer())->get("/deliveries/{$delivery->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.amount_check.state', 'none')
            ->where('items.amount_check.value', null)
            ->where('items.amount_check.amount', '1000.00')
            ->where('items.amount_check.difference', null)
            ->where('items.amount_check.direction', null));
});

it('shows the value, the difference and the direction when the typed Amount is LOWER, and blocks nothing', function () {
    [$po, $line] = damt_po();
    $delivery = damt_delivery($po, 100000); // typed 1,000.00
    $reviewer = damt_reviewer();

    damt_post($reviewer, $delivery, [damt_line($line, 500)])->assertSessionHasNoErrors(); // 500 x 10.00 = 5,000.00

    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.amount_check.state', 'differs')
            ->where('items.amount_check.value', '5000.00')
            ->where('items.amount_check.difference', '4000.00')
            ->where('items.amount_check.direction', 'lower'));

    // a check only: the typed Amount is untouched and the row was saved
    expect($delivery->fresh()->amount_cents)->toBe(100000)
        ->and(DeliveryItem::query()->where('delivery_id', $delivery->id)->count())->toBe(1);
});

it('says HIGHER when the typed Amount is more than the value', function () {
    [$po, $line] = damt_po();
    $delivery = damt_delivery($po, 700000); // typed 7,000.00
    $reviewer = damt_reviewer();

    damt_post($reviewer, $delivery, [damt_line($line, 500)])->assertSessionHasNoErrors();

    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.amount_check.state', 'differs')
            ->where('items.amount_check.difference', '2000.00')
            ->where('items.amount_check.direction', 'higher'));
});

it('matches when the typed Amount equals the value to the centavo', function () {
    [$po, $line] = damt_po();
    $delivery = damt_delivery($po, 500000);
    $reviewer = damt_reviewer();

    damt_post($reviewer, $delivery, [damt_line($line, 500)])->assertSessionHasNoErrors();

    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.amount_check.state', 'matches')
            ->where('items.amount_check.value', '5000.00')
            ->where('items.amount_check.difference', null)
            ->where('items.amount_check.direction', null));
});

it('adds up every active line of the delivery, each at its own unit price', function () {
    [$po, $line] = damt_po();
    $second = PoItem::factory()->create(['purchase_order_id' => $po->id, 'quantity_ordered' => 100, 'unit_price' => '2.50']);
    $delivery = damt_delivery($po, 510000);
    $reviewer = damt_reviewer();

    damt_post($reviewer, $delivery, [damt_line($line, 500), damt_line($second, 40)])->assertSessionHasNoErrors(); // 5,000.00 + 100.00

    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.amount_check.value', '5100.00')
            ->where('items.amount_check.state', 'matches'));
});

it('follows the tolerance setting', function () {
    [$po, $line] = damt_po();
    $delivery = damt_delivery($po, 500400); // 5,004.00 against 5,000.00
    $reviewer = damt_reviewer();

    damt_post($reviewer, $delivery, [damt_line($line, 500)])->assertSessionHasNoErrors();

    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page->where('items.amount_check.state', 'differs'));

    config(['mms.delivery.amount_tolerance_cents' => 500]);

    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page->where('items.amount_check.state', 'matches'));
});

it('does not count a voided row', function () {
    [$po, $line] = damt_po();
    $delivery = damt_delivery($po, 500000);
    $reviewer = damt_reviewer();

    damt_post($reviewer, $delivery, [damt_line($line, 500)])->assertSessionHasNoErrors();

    DeliveryItem::query()->where('delivery_id', $delivery->id)->update(['status' => DeliveryItemStatus::Voided->value]);

    $this->actingAs($reviewer)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.amount_check.state', 'none')
            ->where('items.amount_check.value', null));
});

// ---- the PO's Deliveries tab -----------------------------------------------------------------------

it('adds the typed Amounts of the deliveries next to the PO total and warns only when they are over it', function () {
    [$po] = damt_po(); // PO total 10,000.00
    damt_delivery($po, 600000);
    $second = damt_delivery($po, 500000);
    $reviewer = damt_reviewer();

    $this->actingAs($reviewer)->get("/purchase-orders/{$po->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrder.deliveries.amounts.deliveries_total', '11000.00')
            ->where('purchaseOrder.deliveries.amounts.po_total', '10000.00')
            ->where('purchaseOrder.deliveries.amounts.over_by', '1000.00')
            ->where('purchaseOrder.deliveries.amounts.counted', 2));

    // a voided delivery is not counted, so the warning goes away
    $second->forceFill(['status' => DeliveryStatus::Voided])->save();

    $this->actingAs($reviewer)->get("/purchase-orders/{$po->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('purchaseOrder.deliveries.amounts.deliveries_total', '6000.00')
            ->where('purchaseOrder.deliveries.amounts.over_by', null)
            ->where('purchaseOrder.deliveries.amounts.counted', 1));
});

it('does not warn when the deliveries add up to exactly the PO total, and a smaller total is normal', function () {
    [$po] = damt_po();
    damt_delivery($po, 400000);
    damt_delivery($po, 600000);

    expect(DeliveryAmounts::forPo($po->id))->toBe([
        'deliveries_total' => '10000.00',
        'po_total' => '10000.00',
        'over_by' => null,
        'counted' => 2,
    ]);
});

it('follows the tolerance setting for the PO warning', function () {
    [$po] = damt_po();
    damt_delivery($po, 1000400); // 10,004.00 against 10,000.00

    expect(DeliveryAmounts::forPo($po->id)['over_by'])->toBe('4.00');

    config(['mms.delivery.amount_tolerance_cents' => 500]);

    expect(DeliveryAmounts::forPo($po->id)['over_by'])->toBeNull();
});