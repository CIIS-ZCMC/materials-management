<?php

use App\Enums\DeliveryStatus;
use App\Enums\IarNumberStatus;
use App\Enums\IarSeriesType;
use App\Enums\PurchaseOrderStatus;
use App\Models\Delivery;
use App\Models\DeliverySignatory;
use App\Models\IarNumber;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\DeliveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
| Step 5.2: delivery batches. Uses the real roles and job presets from config/mms_access.php
| (RolePermissionSeeder), so the tests follow the access rules and not a copy of them.
| The database is refreshed by tests/Pest.php (the kit's default for Feature tests).
| Step 5.2b (decision 58): inspectors and the end-user are typed by hand ({name, position}); no Personnel in these tests.
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
        // Step 5.2b: typed by hand, no directory.
        'inspectors' => [['name' => 'Ana Inspector', 'position' => 'Pharmacist II']],
        'end_user' => ['name' => 'Cora Enduser', 'position' => 'Nurse IV'],
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

it('saves the typed inspectors and end-user with no directory id', function () {
    $po = deliveryPo();

    $delivery = recordDelivery(deliveryEncoder(), $po, 7001, [
        // Spaces are collapsed and trimmed, the typed case is kept, an empty position becomes null.
        'inspectors' => [
            ['name' => '  Ana   Inspector ', 'position' => 'Pharmacist  II'],
            ['name' => 'Ben Inspector', 'position' => ''],
        ],
        // The same person may be inspector and end-user.
        'end_user' => ['name' => 'Ana Inspector', 'position' => 'Pharmacist II'],
    ]);

    $inspectors = DeliverySignatory::where('delivery_id', $delivery->id)->where('role', 'inspector')->orderBy('sort_order')->get();
    $endUser = DeliverySignatory::where('delivery_id', $delivery->id)->where('role', 'end_user')->firstOrFail();

    expect($inspectors)->toHaveCount(2)
        ->and($inspectors[0]->name)->toBe('Ana Inspector')
        ->and($inspectors[0]->position)->toBe('Pharmacist II')
        ->and($inspectors[0]->person_id)->toBeNull()
        ->and($inspectors[0]->employee_no)->toBeNull()
        ->and($inspectors[0]->sort_order)->toBe(0)
        ->and($inspectors[1]->name)->toBe('Ben Inspector')
        ->and($inspectors[1]->position)->toBeNull()
        ->and($inspectors[1]->sort_order)->toBe(1)
        ->and($endUser->name)->toBe('Ana Inspector')
        ->and($endUser->person_id)->toBeNull();

    // The audit entry lists "Name (Position)", just "Name" without a position.
    $entry = Activity::where('log_name', 'delivery')->where('event', 'created')->firstOrFail();
    expect(data_get($entry->properties, 'new.inspectors'))->toBe('Ana Inspector (Pharmacist II), Ben Inspector')
        ->and(data_get($entry->properties, 'new.end_user'))->toBe('Ana Inspector (Pharmacist II)');
});

it('ignores a blank extra inspector row and keeps the error under the row that caused it', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();

    $delivery = recordDelivery($encoder, $po, 7001, [
        'inspectors' => [
            ['name' => 'Ana Inspector', 'position' => ''],
            ['name' => '', 'position' => ''],
            ['name' => '   ', 'position' => '  '],
        ],
    ]);

    expect(DeliverySignatory::where('delivery_id', $delivery->id)->where('role', 'inspector')->count())->toBe(1);

    // A blank row BEFORE a bad one does not shift the error key: the bad row is the third row of the form.
    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7002, [
            'inspectors' => [
                ['name' => 'Ana Inspector', 'position' => ''],
                ['name' => '', 'position' => ''],
                ['name' => 'B', 'position' => ''],
            ],
        ]))
        ->assertSessionHasErrors('inspectors.2.name');
});

it('refuses an inspector or end-user name that is empty, too short or too long', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();

    // Every row empty = no inspector at all.
    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['inspectors' => [['name' => '', 'position' => '']]]))
        ->assertSessionHasErrors('inspectors');

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['inspectors' => [['name' => 'A', 'position' => '']]]))
        ->assertSessionHasErrors('inspectors.0.name');

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['inspectors' => [['name' => str_repeat('a', 151), 'position' => '']]]))
        ->assertSessionHasErrors('inspectors.0.name');

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['inspectors' => [['name' => 'Ana Inspector', 'position' => str_repeat('p', 151)]]]))
        ->assertSessionHasErrors('inspectors.0.position');

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['end_user' => ['name' => 'C', 'position' => '']]))
        ->assertSessionHasErrors('end_user.name');

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['end_user' => ['name' => 'Cora Enduser', 'position' => str_repeat('p', 151)]]))
        ->assertSessionHasErrors('end_user.position');

    expect(Delivery::count())->toBe(0);
});

it('refuses a position without a name, on that row', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, [
            'inspectors' => [
                ['name' => 'Ana Inspector', 'position' => ''],
                ['name' => '', 'position' => 'Engineer III'],
            ],
        ]))
        ->assertSessionHasErrors('inspectors.1.name');

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['end_user' => ['name' => '', 'position' => 'Nurse IV']]))
        ->assertSessionHasErrors('end_user.name');

    expect(Delivery::count())->toBe(0);
});

it('refuses four inspectors', function () {
    $po = deliveryPo();
    config(['mms.delivery.max_inspectors' => 3]);

    $this->actingAs(deliveryEncoder())
        ->post('/deliveries', deliveryPayload($po, 7001, [
            'inspectors' => [
                ['name' => 'Inspector One', 'position' => ''],
                ['name' => 'Inspector Two', 'position' => ''],
                ['name' => 'Inspector Three', 'position' => ''],
                ['name' => 'Inspector Four', 'position' => ''],
            ],
        ]))
        ->assertSessionHasErrors('inspectors');

    expect(Delivery::count())->toBe(0);
});

it('refuses the same inspector name twice, ignoring case, on the second row', function () {
    $po = deliveryPo();

    $this->actingAs(deliveryEncoder())
        ->post('/deliveries', deliveryPayload($po, 7001, [
            'inspectors' => [
                ['name' => 'Ana Inspector', 'position' => 'Pharmacist II'],
                ['name' => 'ANA  inspector', 'position' => 'Engineer III'],
            ],
        ]))
        ->assertSessionHasErrors('inspectors.1.name')
        ->assertSessionDoesntHaveErrors('inspectors.0.name');

    expect(Delivery::count())->toBe(0);
});

it('needs at least one inspector and one end-user', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['inspectors' => [], 'end_user' => ['name' => '', 'position' => '']]))
        ->assertSessionHasErrors(['inspectors', 'end_user']);

    // No end_user sent at all.
    $payload = deliveryPayload($po, 7001);
    unset($payload['end_user']);

    $this->actingAs($encoder)->post('/deliveries', $payload)->assertSessionHasErrors('end_user');
});

it('saves nothing and uses up no IAR number when the people are refused', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();

    $this->actingAs($encoder)
        ->post('/deliveries', deliveryPayload($po, 7001, ['inspectors' => [['name' => 'A', 'position' => '']]]))
        ->assertSessionHasErrors('inspectors.0.name');

    // The Service is the second line of defence: called directly, it refuses the same things with the same keys.
    $service = app(DeliveryService::class);

    foreach ([
        ['inspectors' => [['name' => '', 'position' => 'Engineer III']], 'key' => 'inspectors.0.name'],
        ['inspectors' => [['name' => 'Ana Inspector'], ['name' => 'ana inspector']], 'key' => 'inspectors.1.name'],
        ['inspectors' => [], 'key' => 'inspectors'],
        ['end_user' => ['name' => '', 'position' => ''], 'key' => 'end_user'],
        ['end_user' => ['name' => '', 'position' => 'Nurse IV'], 'key' => 'end_user.name'],
    ] as $case) {
        $key = $case['key'];
        unset($case['key']);

        try {
            $service->create($po, deliveryPayload($po, 7001, $case));
            $this->fail("The service accepted a bad body for {$key}.");
        } catch (ValidationException $e) {
            expect(array_key_exists($key, $e->errors()))->toBeTrue("No error under {$key}");
        }
    }

    expect(Delivery::count())->toBe(0)
        ->and(DeliverySignatory::count())->toBe(0)
        ->and(IarNumber::count())->toBe(0);
});

it('replaces the signatories on edit and logs the change, a position-only change too', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    $delivery = recordDelivery($encoder, $po, 7001); // Ana Inspector (Pharmacist II); end-user Cora Enduser (Nurse IV)

    $body = fn (array $inspectors, array $endUser) => [
        'invoice_no' => $delivery->invoice_no,
        'fund_cluster' => $delivery->fund_cluster,
        'amount' => '125000.50',
        'date_received' => deliveryToday(),
        'inspectors' => $inspectors,
        'end_user' => $endUser,
    ];

    // 1) A position-only change is logged on its own.
    $this->actingAs($encoder)
        ->put("/deliveries/{$delivery->id}", $body(
            [['name' => 'Ana Inspector', 'position' => 'Chief Pharmacist']],
            ['name' => 'Cora Enduser', 'position' => 'Nurse IV'],
        ))
        ->assertSessionHasNoErrors();

    $entry = Activity::where('log_name', 'delivery')->where('event', 'updated')->latest('id')->firstOrFail();
    expect(array_keys((array) data_get($entry->properties, 'new')))->toBe(['inspectors'])
        ->and(data_get($entry->properties, 'new.inspectors'))->toBe('Ana Inspector (Chief Pharmacist)');

    // 2) New people replace the old rows (the old ones are gone, not kept beside them).
    $this->actingAs($encoder)
        ->put("/deliveries/{$delivery->id}", $body(
            [['name' => 'Ben Inspector', 'position' => ''], ['name' => 'Dina Inspector', 'position' => 'Engineer III']],
            ['name' => 'Eli Enduser', 'position' => ''],
        ))
        ->assertSessionHasNoErrors();

    $rows = DeliverySignatory::where('delivery_id', $delivery->id)->orderBy('role')->orderBy('sort_order')->get();

    expect($rows->pluck('name')->all())->toBe(['Eli Enduser', 'Ben Inspector', 'Dina Inspector'])
        ->and($rows->pluck('person_id')->filter()->all())->toBe([])
        ->and(Activity::where('log_name', 'delivery')->where('event', 'updated')->count())->toBe(2);

    // 3) The same people again: nothing changes, nothing is logged.
    $this->actingAs($encoder)
        ->put("/deliveries/{$delivery->id}", $body(
            [['name' => 'Ben Inspector', 'position' => ''], ['name' => 'Dina Inspector', 'position' => 'Engineer III']],
            ['name' => 'Eli Enduser', 'position' => ''],
        ))
        ->assertSessionHasNoErrors();

    expect(Activity::where('log_name', 'delivery')->where('event', 'updated')->count())->toBe(2);
});

it('still shows a legacy signatory that came from the directory (person_id set) and lets it be edited', function () {
    $po = deliveryPo();
    $encoder = deliveryEncoder();
    $delivery = recordDelivery($encoder, $po, 7001);

    // What a delivery recorded before step 5.2b looks like: the row carries the directory's ids.
    $legacy = DeliverySignatory::where('delivery_id', $delivery->id)->where('role', 'inspector')->firstOrFail();
    $legacy->forceFill(['person_id' => 'UMIS-DUMMY-001', 'employee_no' => 'UMIS-DUMMY-001', 'name' => 'Legacy Sample', 'position' => 'Engineer III'])->save();

    $this->actingAs($encoder)
        ->get("/deliveries/{$delivery->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('delivery.signatories', 2)
            ->where('delivery.signatories', fn ($rows) => collect($rows)->contains(
                fn ($row) => $row['name'] === 'Legacy Sample' && $row['position'] === 'Engineer III' && $row['person_id'] === 'UMIS-DUMMY-001',
            ))
            // The typed end-user row has no directory id.
            ->where('delivery.signatories', fn ($rows) => collect($rows)->contains(
                fn ($row) => $row['role'] === 'end_user' && $row['person_id'] === null && $row['employee_no'] === null,
            )));

    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}/edit")->assertOk();

    // Saving the form (it sends only name and position) replaces the old row with a typed one.
    $this->actingAs($encoder)
        ->put("/deliveries/{$delivery->id}", [
            'invoice_no' => $delivery->invoice_no,
            'fund_cluster' => $delivery->fund_cluster,
            'amount' => '125000.50',
            'date_received' => deliveryToday(),
            'inspectors' => [['name' => 'Legacy Sample', 'position' => 'Engineer III']],
            'end_user' => ['name' => 'Cora Enduser', 'position' => 'Nurse IV'],
        ])
        ->assertSessionHasNoErrors();

    expect(DeliverySignatory::where('delivery_id', $delivery->id)->where('name', 'Legacy Sample')->first()->person_id)->toBeNull();
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
            'inspectors' => [['name' => $inspector->name, 'position' => $inspector->position]],
            'end_user' => ['name' => $endUser->name, 'position' => $endUser->position],
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
            'inspectors' => [['name' => $inspector->name, 'position' => $inspector->position]],
            'end_user' => ['name' => $endUser->name, 'position' => $endUser->position],
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
            'inspectors' => [['name' => $inspector->name, 'position' => $inspector->position]],
            'end_user' => ['name' => $endUser->name, 'position' => $endUser->position],
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
            'inspectors' => [['name' => $inspector->name, 'position' => $inspector->position]],
            'end_user' => ['name' => $endUser->name, 'position' => $endUser->position],
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