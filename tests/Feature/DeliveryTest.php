<?php

use App\Enums\DeliveryStatus;
use App\Enums\IarNumberStatus;
use App\Enums\IarSeriesType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Delivery;
use App\Models\DeliverySignatory;
use App\Models\IarNumber;
use App\Models\Personnel;
use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
| Step 5.2: delivery batches. Uses the real roles and job presets from config/mms_access.php
| (RolePermissionSeeder), so the tests follow the access rules and not a copy of them.
| The database is refreshed by tests/Pest.php (the kit's default for Feature tests).
| NOT run when written (no PHP in that session): run `php artisan test --filter=DeliveryTest`.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

// ---- helpers (prefixed so they cannot clash with helpers in other test files) ---------------------

function deliveryUser(string $role, ?string $preset = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    if ($preset !== null) {
        $user->givePermissionTo(config("mms_access.job_presets.{$preset}.permissions"));
    }

    return $user;
}

function deliveryEncoder(): User
{
    return deliveryUser('MMS Staff', 'encoder');
}

function deliveryToday(): string
{
    return now()->setTimezone('Asia/Manila')->toDateString();
}

/** A number in the regular (or another) series for the year of today's Manila date. */
function deliveryNumber(int $sequence, string $series = 'regular'): string
{
    return IarSeriesType::from($series)->format((int) substr(deliveryToday(), 0, 4), $sequence);
}

function deliveryPo(string $status = 'verified', array $attributes = []): PurchaseOrder
{
    $po = PurchaseOrder::factory()->create(['po_date' => '2026-01-05', ...$attributes]);
    $po->forceFill(['status' => PurchaseOrderStatus::from($status)])->save();

    return $po->fresh();
}

function deliveryInspector(array $attributes = []): Personnel
{
    return Personnel::factory()->create([
        'is_inspector' => true,
        'is_end_user' => false,
        'is_active' => true,
        ...$attributes,
    ]);
}

function deliveryEndUser(array $attributes = []): Personnel
{
    return Personnel::factory()->create([
        'is_inspector' => false,
        'is_end_user' => true,
        'is_active' => true,
        ...$attributes,
    ]);
}

/** A valid request body for POST /deliveries. */
function deliveryPayload(PurchaseOrder $po, int $sequence = 7001, array $overrides = []): array
{
    return [
        'purchase_order_id' => $po->id,
        'invoice_no' => 'INV-'.$sequence,
        'iar_series' => 'regular',
        'iar_number' => deliveryNumber($sequence),
        'fund_cluster' => '01',
        'amount' => '125000.50',
        'date_received' => deliveryToday(),
        'inspectors' => [(string) deliveryInspector()->id],
        'end_user' => (string) deliveryEndUser()->id,
        ...$overrides,
    ];
}

function recordDelivery(User $encoder, PurchaseOrder $po, int $sequence = 7001, array $overrides = []): Delivery
{
    test()->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, $sequence, $overrides))
        ->assertSessionHasNoErrors();

    return Delivery::query()->where('iar_number', $overrides['iar_number'] ?? deliveryNumber($sequence))->firstOrFail();
}

// ---- the PO status rules (Q2) -----------------------------------------------------------------------

it('records a delivery for a verified PO that is not closed', function () {
    $po = deliveryPo();

    $this->actingAs(deliveryEncoder())
        ->post('/deliveries', deliveryPayload($po))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $delivery = Delivery::query()->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::ForUploading)
        ->and($delivery->purchase_order_id)->toBe($po->id)
        ->and($delivery->amount_cents)->toBe(12500050)
        ->and($delivery->iar_series)->toBe(IarSeriesType::Regular)
        ->and($delivery->created_by)->not->toBeNull();
});

it('refuses a delivery for a PO that is not verified', function (string $status) {
    $po = deliveryPo($status);
    $encoder = deliveryEncoder();

    $this->actingAs($encoder)
        ->get("/deliveries/create?purchase_order={$po->id}")
        ->assertForbidden();

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po))
        ->assertForbidden();

    expect(Delivery::count())->toBe(0)
        ->and(IarNumber::count())->toBe(0);
})->with([
    'draft',
    'uploaded',
    'escalated',
    'for_termination_approval',
    'for_termination',
    'cancelled',
    'terminated',
]);

it('refuses a delivery for a closed PO', function () {
    $po = deliveryPo();
    $po->forceFill(['closed_at' => now()])->save();

    $this->actingAs(deliveryEncoder())
        ->post('/deliveries', deliveryPayload($po))
        ->assertForbidden();

    expect(Delivery::count())->toBe(0);
});

// ---- many deliveries on one PO ----------------------------------------------------------------------

it('records three deliveries on one PO with three different IAR numbers', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();

    $first = recordDelivery($encoder, $po, 7001);
    $second = recordDelivery($encoder, $po, 7002);
    $third = recordDelivery($encoder, $po, 7003);

    expect(Delivery::where('purchase_order_id', $po->id)->count())->toBe(3);

    foreach ([$first, $second, $third] as $delivery) {
        $number = IarNumber::query()->where('delivery_id', $delivery->id)->first();

        expect($number)->not->toBeNull()
            ->and($number->number)->toBe($delivery->iar_number)
            ->and($number->status)->toBe(IarNumberStatus::Used)
            ->and($delivery->status)->toBe(DeliveryStatus::ForUploading);
    }

    expect(Delivery::pluck('iar_number')->unique()->count())->toBe(3);

    // One "created" entry per delivery in the delivery log.
    expect(Activity::where('log_name', 'delivery')->where('event', 'created')->count())->toBe(3);
});

// ---- duplicate and voided IAR numbers ----------------------------------------------------------------

it('rejects an IAR number that is already in use, with the IAR service message', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    recordDelivery($encoder, $po, 7001);

    $number = deliveryNumber(7001);

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7002, ['iar_number' => $number]))
        ->assertSessionHasErrors(['iar_number' => "IAR number {$number} is already in use."]);

    expect(Delivery::count())->toBe(1)
        ->and(IarNumber::count())->toBe(1);
});

it('rejects an IAR number that was voided earlier', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    $voided = recordDelivery($encoder, $po, 7001);

    $this->actingAs($encoder)->post("/deliveries/{$voided->id}/void", ['reason' => 'Entered by mistake'])->assertSessionHasNoErrors();

    $number = deliveryNumber(7001);

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7002, ['iar_number' => $number]))
        ->assertSessionHasErrors(['iar_number' => "IAR number {$number} was voided earlier and can never be used again."]);

    expect(Delivery::count())->toBe(1);
});

it('rejects a badly written IAR number and saves nothing', function () {
    $po = deliveryPo();

    $this->actingAs(deliveryEncoder())
        ->post('/deliveries', deliveryPayload($po, 7001, ['iar_number' => '26-01398']))
        ->assertSessionHasErrors('iar_number');

    expect(Delivery::count())->toBe(0)
        ->and(DeliverySignatory::count())->toBe(0);
});

it('suggests the next IAR number without using it up', function () {
    $encoder = deliveryEncoder();
    $year = (int) substr(deliveryToday(), 0, 4);

    $this->actingAs($encoder)
        ->getJson('/deliveries/iar-suggestion?series=regular&date='.deliveryToday())
        ->assertOk()
        ->assertJsonPath('number', IarSeriesType::Regular->format($year, 1));

    // Asking again gives the same number, because nothing was reserved.
    $this->actingAs($encoder)
        ->getJson('/deliveries/iar-suggestion?series=regular&date='.deliveryToday())
        ->assertJsonPath('number', IarSeriesType::Regular->format($year, 1));

    expect(IarNumber::count())->toBe(0);
});

// ---- void ------------------------------------------------------------------------------------------

it('voids a delivery, voids its IAR number with the same reason and logs the change', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    $delivery = recordDelivery($encoder, $po, 7001);

    $this->actingAs($encoder)
        ->post("/deliveries/{$delivery->id}/void", ['reason' => 'Entered by mistake'])
        ->assertSessionHasNoErrors()
        ->assertRedirect("/deliveries/{$delivery->id}");

    $delivery->refresh();
    $number = IarNumber::query()->where('delivery_id', $delivery->id)->firstOrFail();

    expect($delivery->status)->toBe(DeliveryStatus::Voided)
        ->and($delivery->void_reason)->toBe('Entered by mistake')
        ->and($delivery->voided_by)->toBe($encoder->id)
        ->and($delivery->voided_at)->not->toBeNull()
        // The number is kept (never deleted, never reused) and carries the same reason.
        ->and($number->status)->toBe(IarNumberStatus::Void)
        ->and($number->void_reason)->toBe('Entered by mistake');

    $status = Activity::where('log_name', 'delivery')->where('event', 'status_changed')->latest('id')->firstOrFail();
    expect(data_get($status->properties, 'reason'))->toBe('Entered by mistake');

    expect(Activity::where('log_name', 'iar')->where('event', 'iar_number_voided')->count())->toBe(1);
});

it('needs a reason to void a delivery', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    $delivery = recordDelivery($encoder, $po, 7001);

    $this->actingAs($encoder)
        ->post("/deliveries/{$delivery->id}/void", ['reason' => 'no'])
        ->assertSessionHasErrors('reason');

    expect($delivery->refresh()->status)->toBe(DeliveryStatus::ForUploading)
        ->and(IarNumber::where('delivery_id', $delivery->id)->first()->status)->toBe(IarNumberStatus::Used);
});

it('does not void a delivery twice or on a closed PO', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    $delivery = recordDelivery($encoder, $po, 7001);
    $other = recordDelivery($encoder, $po, 7002);

    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/void", ['reason' => 'Entered by mistake'])->assertSessionHasNoErrors();
    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/void", ['reason' => 'Entered by mistake'])->assertForbidden();

    $po->forceFill(['closed_at' => now()])->save();

    $this->actingAs($encoder)->post("/deliveries/{$other->id}/void", ['reason' => 'Entered by mistake'])->assertForbidden();
    expect($other->refresh()->status)->toBe(DeliveryStatus::ForUploading);
});

// ---- signatories from the directory ---------------------------------------------------------------

it('saves inspectors and the end-user from the directory as a snapshot', function () {
    $po = deliveryPo();
    $inspectorA = deliveryInspector(['name' => 'Ana Inspector', 'position' => 'Pharmacist II']);
    $inspectorB = deliveryInspector(['name' => 'Ben Inspector', 'position' => 'Engineer III']);
    $endUser = deliveryEndUser(['name' => 'Cora Enduser', 'position' => 'Nurse IV']);

    $delivery = recordDelivery(deliveryEncoder(), $po, 7001, [
        'inspectors' => [(string) $inspectorA->id, (string) $inspectorB->id],
        'end_user' => (string) $endUser->id,
    ]);

    $rows = DeliverySignatory::where('delivery_id', $delivery->id)->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->where('person_id', (string) $inspectorA->id)->first()->name)->toBe('Ana Inspector')
        ->and($rows->where('person_id', (string) $inspectorA->id)->first()->position)->toBe('Pharmacist II')
        ->and($rows->where('person_id', (string) $endUser->id)->first()->employee_no)->toBe($endUser->employee_no);

    // The snapshot does not change when the directory does.
    $inspectorA->forceFill(['name' => 'Renamed Person'])->save();

    $this->actingAs(deliveryEncoder())
        ->put("/deliveries/{$delivery->id}", [
            'invoice_no' => 'INV-CHANGED',
            'fund_cluster' => '01',
            'amount' => '125000.50',
            'date_received' => deliveryToday(),
            'inspectors' => [(string) $inspectorA->id, (string) $inspectorB->id],
            'end_user' => (string) $endUser->id,
        ])
        ->assertSessionHasNoErrors();

    expect(DeliverySignatory::where('delivery_id', $delivery->id)->where('person_id', (string) $inspectorA->id)->first()->name)->toBe('Ana Inspector');
});

it('rejects a person who does not have the role, an inactive person and an unknown id', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();

    $notInspector = deliveryEndUser(); // is_inspector = false
    $inactive = deliveryInspector(['is_active' => false]);

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['inspectors' => [(string) $notInspector->id]]))
        ->assertSessionHasErrors('inspectors.0');

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['inspectors' => [(string) $inactive->id]]))
        ->assertSessionHasErrors('inspectors.0');

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['inspectors' => ['999999']]))
        ->assertSessionHasErrors('inspectors.0');

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['end_user' => (string) deliveryInspector()->id]))
        ->assertSessionHasErrors('end_user');

    // Nothing was saved, and no IAR number was used up.
    expect(Delivery::count())->toBe(0)
        ->and(IarNumber::count())->toBe(0);
});

it('needs at least one inspector and one end-user', function () {
    $po = deliveryPo();

    $this->actingAs(deliveryEncoder())
        ->post('/deliveries', deliveryPayload($po, 7001, ['inspectors' => [], 'end_user' => '']))
        ->assertSessionHasErrors(['inspectors', 'end_user']);
});

// ---- editing ---------------------------------------------------------------------------------------

it('edits the header and logs what changed, but never the IAR number or series', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    $delivery = recordDelivery($encoder, $po, 7001);
    $inspector = $delivery->signatories()->where('role', 'inspector')->first();
    $endUser = $delivery->signatories()->where('role', 'end_user')->first();

    $this->actingAs($encoder)
        ->put("/deliveries/{$delivery->id}", [
            'invoice_no' => 'INV-NEW',
            'fund_cluster' => '05',
            'amount' => '99,000.00',
            'date_received' => deliveryToday(),
            'inspectors' => [$inspector->person_id],
            'end_user' => $endUser->person_id,
            // Ignored on purpose:
            'iar_number' => deliveryNumber(9999),
            'iar_series' => 'donation',
        ])
        ->assertSessionHasNoErrors();

    $delivery->refresh();

    expect($delivery->invoice_no)->toBe('INV-NEW')
        ->and($delivery->fund_cluster)->toBe('05')
        ->and($delivery->amount_cents)->toBe(9900000)
        ->and($delivery->iar_number)->toBe(deliveryNumber(7001))
        ->and($delivery->iar_series)->toBe(IarSeriesType::Regular);

    $entry = Activity::where('log_name', 'delivery')->where('event', 'updated')->firstOrFail();
    expect(array_keys((array) data_get($entry->properties, 'new')))->toEqualCanonicalizing(['invoice_no', 'fund_cluster', 'amount']);
});

it('writes no audit entry when an edit changes nothing', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    $delivery = recordDelivery($encoder, $po, 7001);
    $inspector = $delivery->signatories()->where('role', 'inspector')->first();
    $endUser = $delivery->signatories()->where('role', 'end_user')->first();

    $this->actingAs($encoder)
        ->put("/deliveries/{$delivery->id}", [
            'invoice_no' => $delivery->invoice_no,
            'fund_cluster' => $delivery->fund_cluster,
            'amount' => '125000.50',
            'date_received' => deliveryToday(),
            'inspectors' => [$inspector->person_id],
            'end_user' => $endUser->person_id,
        ])
        ->assertSessionHasNoErrors();

    expect(Activity::where('log_name', 'delivery')->where('event', 'updated')->count())->toBe(0);
});

it('does not edit a voided delivery', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    $delivery = recordDelivery($encoder, $po, 7001);
    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/void", ['reason' => 'Entered by mistake']);

    $inspector = $delivery->signatories()->where('role', 'inspector')->first();
    $endUser = $delivery->signatories()->where('role', 'end_user')->first();

    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}/edit")->assertForbidden();

    // A complete, valid body, so the answer comes from the rule (403) and not from validation.
    $this->actingAs($encoder)
        ->put("/deliveries/{$delivery->id}", [
            'invoice_no' => 'INV-NEW',
            'fund_cluster' => '01',
            'amount' => '125000.50',
            'date_received' => deliveryToday(),
            'inspectors' => [$inspector->person_id],
            'end_user' => $endUser->person_id,
        ])
        ->assertForbidden();

    expect($delivery->refresh()->invoice_no)->not->toBe('INV-NEW');
});

// ---- date and amount rules -------------------------------------------------------------------------

it('rejects a date received in the future or before the PO date', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['date_received' => now()->setTimezone('Asia/Manila')->addDay()->toDateString()]))
        ->assertSessionHasErrors('date_received');

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['date_received' => '2026-01-04']))
        ->assertSessionHasErrors('date_received');

    expect(Delivery::count())->toBe(0);
});

it('keeps the date received in the year of the IAR number when editing', function () {
    $po = deliveryPo('verified', ['po_date' => '2020-01-01']);
    $encoder = deliveryEncoder();
    $delivery = recordDelivery($encoder, $po, 7001);
    $inspector = $delivery->signatories()->where('role', 'inspector')->first();
    $endUser = $delivery->signatories()->where('role', 'end_user')->first();
    $otherYear = ((int) substr(deliveryToday(), 0, 4) - 1).'-12-31';

    $this->actingAs($encoder)
        ->put("/deliveries/{$delivery->id}", [
            'invoice_no' => $delivery->invoice_no,
            'fund_cluster' => '01',
            'amount' => '125000.50',
            'date_received' => $otherYear,
            'inspectors' => [$inspector->person_id],
            'end_user' => $endUser->person_id,
        ])
        ->assertSessionHasErrors('date_received');
});

it('rejects an amount that is zero, negative or has too many decimals', function (string $amount) {
    $po = deliveryPo();

    $this->actingAs(deliveryEncoder())
        ->post('/deliveries', deliveryPayload($po, 7001, ['amount' => $amount]))
        ->assertSessionHasErrors('amount');

    expect(Delivery::count())->toBe(0);
})->with(['0', '0.00', '-5', '10.999', 'abc']);

// ---- who may do what -------------------------------------------------------------------------------

it('gives 403 to every role without delivery.encode', function (string $role, ?string $preset) {
    $po = deliveryPo();
    $delivery = recordDelivery(deliveryEncoder(), $po, 7001);
    $user = deliveryUser($role, $preset);

    $this->actingAs($user)->get("/deliveries/create?purchase_order={$po->id}")->assertForbidden();
    $this->actingAs($user)->post('/deliveries', deliveryPayload($po, 7002))->assertForbidden();
    $this->actingAs($user)->get("/deliveries/{$delivery->id}/edit")->assertForbidden();
    $this->actingAs($user)->put("/deliveries/{$delivery->id}", ['invoice_no' => 'X'])->assertForbidden();
    $this->actingAs($user)->post("/deliveries/{$delivery->id}/void", ['reason' => 'Entered by mistake'])->assertForbidden();

    expect(Delivery::count())->toBe(1)
        ->and($delivery->refresh()->status)->toBe(DeliveryStatus::ForUploading);
})->with([
    'Admin' => ['Admin', null],
    'Procurement' => ['Procurement', null],
    'MMS Head' => ['MMS Head', null],
    'Finance' => ['Finance', null],
    'PO Reviewer' => ['MMS Staff', 'po-reviewer'],
    'Inventory Team' => ['MMS Staff', 'inventory-team'],
]);

it('lets the Encoder open the list and a delivery', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    $delivery = recordDelivery($encoder, $po, 7001);

    $this->actingAs($encoder)->get('/deliveries')->assertOk();
    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}")->assertOk();
    $this->actingAs($encoder)->get('/deliveries?search='.urlencode($delivery->iar_number))->assertOk();
});

it('lists the deliveries on the PO detail page and says whether a delivery can be recorded', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    recordDelivery($encoder, $po, 7001);
    recordDelivery($encoder, $po, 7002);

    $this->actingAs($encoder)
        ->get("/purchase-orders/{$po->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('purchaseOrder.deliveries.rows', 2)
            ->where('purchaseOrder.deliveries.can_record', true));

    // A plain PO viewer sees the rows but not the button.
    $this->actingAs(deliveryUser('Finance'))
        ->get("/purchase-orders/{$po->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('purchaseOrder.deliveries.rows', 2)
            ->where('purchaseOrder.deliveries.can_record', false));
});
