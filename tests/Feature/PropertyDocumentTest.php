<?php

use App\Enums\DeliveryStatus;
use App\Enums\PropertyBand;
use App\Enums\PropertyDecisionStatus;
use App\Enums\PropertyDocumentStatus;
use App\Enums\PropertyDocumentType;
use App\Models\Delivery;
use App\Models\DeliveryItem;
use App\Models\Personnel;
use App\Models\PoItem;
use App\Models\PropertyDecision;
use App\Models\PropertyDocument;
use App\Models\PropertySeries;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PropertyDecisionService;
use App\Services\PropertyDocumentService;
use App\Services\PropertyNumberService;
use App\Support\Money;
use App\Support\PropertyDocumentRule;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
| Step 6.2: equipment decision and property records (PAR / Sub-PAR / ICS). PROVISIONAL rule (PHASE-6-PLAN Q4).
| Helper names start with "prop_" so they cannot clash with "ditem_", "ditem2_", "damt_" and the others.
| Lines are made straight from the factories (a PO line, a delivery and one delivered-item row) because the delivery
| flow itself is tested in DeliveryTest and DeliveryItemTest. Not run when written (no Laravel project in that session):
| expect small mistakes in the first run.
*/

beforeEach(function () {
    Storage::fake('local');
    Process::fake();
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

// ---- helpers ---------------------------------------------------------------------------------------

/** The Inventory Team job: equipment.decide, par.generate, ics.generate. */
function prop_user(?string $preset = 'inventory-team'): User
{
    $user = User::factory()->create();
    $user->assignRole('MMS Staff');

    if ($preset !== null) {
        $user->givePermissionTo(config("mms_access.job_presets.{$preset}.permissions"));
    }

    return $user;
}

/** A user with only the named permissions (and the MMS Staff role, which has none of them by default). */
function prop_user_with(array $permissions): User
{
    $user = User::factory()->create();
    $user->assignRole('MMS Staff');
    $user->givePermissionTo($permissions);

    return $user;
}

function prop_admin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Admin');

    return $user;
}

/** One delivered row whose PO line has the given unit price; the category says equipment or not. */
function prop_line(string $price = '60000.00', bool $categoryEquipment = true, string $quantity = '1'): DeliveryItem
{
    $po = PurchaseOrder::factory()->create();
    $line = PoItem::factory()->create(['purchase_order_id' => $po->id, 'unit_price' => $price]);
    $line->category->forceFill(['is_equipment' => $categoryEquipment])->save();
    $delivery = Delivery::factory()->create(['purchase_order_id' => $po->id]);

    return DeliveryItem::factory()->create(['delivery_id' => $delivery->id, 'po_item_id' => $line->id, 'quantity' => $quantity]);
}

function prop_year(): int
{
    return app(PropertyNumberService::class)->currentYear();
}

function prop_number(PropertyDocumentType $type, int $sequence): string
{
    return $type->format(prop_year(), $sequence);
}

function prop_person(array $extra = []): Personnel
{
    static $n = 0;
    $n++;

    return Personnel::query()->forceCreate([
        'employee_no' => 'EMP-'.str_pad((string) (7000 + $n), 5, '0', STR_PAD_LEFT),
        'name' => "End User {$n}",
        'position' => 'Nurse II',
        'office' => 'Ward '.$n,
        'is_inspector' => false,
        'is_end_user' => true,
        'is_property_custodian' => false,
        'is_active' => true,
        ...$extra,
    ]);
}

/** Records the decision through the route and returns it. */
function prop_decide(User $user, DeliveryItem $item, bool $equipment = true, array $extra = []): PropertyDecision
{
    test()->actingAs($user)
        ->post("/property-documents/items/{$item->id}/decision", ['is_equipment' => $equipment, ...$extra])
        ->assertSessionHasNoErrors();

    return PropertyDecision::query()->where('delivery_item_id', $item->id)->where('status', 'active')->firstOrFail();
}

function prop_issue_par(User $user, PropertyDecision $decision, int $par = 1, int $subPar = 1, ?Personnel $person = null)
{
    $person ??= prop_person();

    return test()->actingAs($user)->post("/property-documents/decisions/{$decision->id}/documents", [
        'type' => 'par',
        'number' => prop_number(PropertyDocumentType::Par, $par),
        'sub_par_number' => prop_number(PropertyDocumentType::SubPar, $subPar),
        'person_id' => $person->id,
    ]);
}

function prop_issue_ics(User $user, PropertyDecision $decision, int $number = 1)
{
    return test()->actingAs($user)->post("/property-documents/decisions/{$decision->id}/documents", [
        'type' => 'ics',
        'number' => prop_number(PropertyDocumentType::Ics, $number),
    ]);
}

// ---- the rule and its boundaries --------------------------------------------------------------------

it('puts equipment into the right band at every boundary price', function (string $price, string $band) {
    $line = prop_line($price);
    $decision = prop_decide(prop_user(), $line, true, $band === 'below' ? ['remarks' => 'Low value, no document needed'] : []);

    expect($decision->band)->toBe(PropertyBand::from($band))
        ->and($decision->unit_price_cents)->toBe(Money::cents($price))
        ->and($decision->par_threshold_cents)->toBe(5000000)
        ->and($decision->ics_min_cents)->toBe(100000)
        ->and($decision->rule_provisional)->toBeTrue();
})->with([
    'just below 1,000' => ['999.99', 'below'],
    'exactly 1,000' => ['1000.00', 'ics'],
    'exactly 50,000' => ['50000.00', 'ics'],
    'just above 50,000' => ['50000.01', 'par'],
]);

it('does not ask for a document when the line is not equipment, whatever the price', function () {
    $line = prop_line('90000.00', false);
    $decision = prop_decide(prop_user(), $line, false);

    expect($decision->band)->toBe(PropertyBand::NotEquipment)
        ->and($decision->band->documents())->toBe([])
        ->and(PropertyDocumentRule::state($decision, []))->toBe(PropertyDocumentRule::STATE_DONE);
});

it('requires remarks below the ICS minimum and accepts them', function () {
    $line = prop_line('999.99');
    $user = prop_user();

    $this->actingAs($user)->post("/property-documents/items/{$line->id}/decision", ['is_equipment' => true])->assertSessionHasErrors('remarks');
    expect(PropertyDecision::query()->count())->toBe(0);

    $this->actingAs($user)->post("/property-documents/items/{$line->id}/decision", ['is_equipment' => true, 'remarks' => 'abc'])->assertSessionHasErrors('remarks');
    $this->actingAs($user)->post("/property-documents/items/{$line->id}/decision", ['is_equipment' => true, 'remarks' => 'Consumable accessory, no ICS'])->assertSessionHasNoErrors();

    expect(PropertyDecision::query()->first()->remarks)->toBe('Consumable accessory, no ICS');
});

it('defaults to the category and needs a reason to differ from it', function () {
    $user = prop_user();
    $line = prop_line('60000.00', false);

    $this->actingAs($user)->post("/property-documents/items/{$line->id}/decision", ['is_equipment' => true])->assertSessionHasErrors('override_reason');
    $this->actingAs($user)->post("/property-documents/items/{$line->id}/decision", ['is_equipment' => true, 'override_reason' => 'Server rack, tagged as equipment'])->assertSessionHasNoErrors();

    $decision = PropertyDecision::query()->firstOrFail();
    expect($decision->category_default)->toBeFalse()
        ->and($decision->is_equipment)->toBeTrue()
        ->and($decision->override_reason)->toBe('Server rack, tagged as equipment');

    // Agreeing with the category stores no reason, even if one is typed.
    $other = prop_line('60000.00', true);
    $this->actingAs($user)->post("/property-documents/items/{$other->id}/decision", ['is_equipment' => true, 'override_reason' => 'not needed'])->assertSessionHasNoErrors();
    expect(PropertyDecision::query()->where('delivery_item_id', $other->id)->first()->override_reason)->toBeNull();
});

it('keeps the rule a decision was made with when the thresholds change later', function () {
    $user = prop_user();
    $old = prop_line('45000.00');
    $decisionOld = prop_decide($user, $old);
    expect($decisionOld->band)->toBe(PropertyBand::Ics);

    config(['mms.property.par_threshold' => 40000]);

    $new = prop_line('45000.00');
    $decisionNew = prop_decide($user, $new);

    expect($decisionNew->band)->toBe(PropertyBand::Par)
        ->and($decisionNew->par_threshold_cents)->toBe(4000000)
        ->and($decisionOld->fresh()->band)->toBe(PropertyBand::Ics)
        ->and($decisionOld->fresh()->par_threshold_cents)->toBe(5000000);
});

// ---- decisions: one active per line, void and decide again ------------------------------------------

it('allows one active decision per line and refuses a second', function () {
    $user = prop_user();
    $line = prop_line('2000.00');
    prop_decide($user, $line);

    $this->actingAs($user)->post("/property-documents/items/{$line->id}/decision", ['is_equipment' => true])->assertForbidden();

    // The Service checks again under the lock, even if the Policy were skipped.
    $this->actingAs($user);
    expect(fn () => app(PropertyDecisionService::class)->decide($line, true, null, null, $user))->toThrow(ValidationException::class);
    expect(PropertyDecision::query()->where('delivery_item_id', $line->id)->count())->toBe(1);
});

it('voids a decision with a reason, keeps it on record and lets staff decide again', function () {
    $user = prop_user();
    $line = prop_line('2000.00');
    $decision = prop_decide($user, $line);

    $this->actingAs($user)->post("/property-documents/decisions/{$decision->id}/void", ['reason' => 'abc'])->assertSessionHasErrors('reason');
    $this->actingAs($user)->post("/property-documents/decisions/{$decision->id}/void", ['reason' => 'Wrong category chosen'])->assertSessionHasNoErrors();

    $decision->refresh();
    expect($decision->status)->toBe(PropertyDecisionStatus::Voided)
        ->and($decision->void_reason)->toBe('Wrong category chosen')
        ->and($decision->voided_by)->toBe($user->id);

    prop_decide($user, $line, false, ['override_reason' => 'It is a consumable']);
    expect(PropertyDecision::query()->where('delivery_item_id', $line->id)->count())->toBe(2);

    $entry = Activity::query()->where('log_name', 'property')->where('event', 'status_changed')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->properties['reason'])->toBe('Wrong category chosen');
});

it('refuses to void a decision while it still has an issued document', function () {
    $user = prop_user();
    $decision = prop_decide($user, prop_line('2000.00'));
    prop_issue_ics($user, $decision)->assertSessionHasNoErrors();

    $this->actingAs($user)->post("/property-documents/decisions/{$decision->id}/void", ['reason' => 'Changed my mind'])->assertForbidden();
    expect($decision->fresh()->status)->toBe(PropertyDecisionStatus::Active);
});

// ---- issuing ----------------------------------------------------------------------------------------

it('issues a PAR and its Sub-PAR together with the user from the personnel list', function () {
    $user = prop_user();
    $decision = prop_decide($user, prop_line('60000.00'));
    $person = prop_person();

    prop_issue_par($user, $decision, 1, 1, $person)->assertSessionHasNoErrors();

    $par = PropertyDocument::query()->where('type', 'par')->firstOrFail();
    $sub = PropertyDocument::query()->where('type', 'sub_par')->firstOrFail();

    expect($par->number)->toBe(prop_number(PropertyDocumentType::Par, 1))
        ->and($sub->number)->toBe(prop_number(PropertyDocumentType::SubPar, 1))
        ->and($sub->parent_id)->toBe($par->id)
        ->and($sub->user_employee_no)->toBe($person->employee_no)
        ->and($sub->user_name)->toBe($person->name)
        ->and($par->status)->toBe(PropertyDocumentStatus::Issued)
        ->and(PropertySeries::query()->where('type', 'par')->value('next_number'))->toBe(2)
        ->and(Activity::query()->where('log_name', 'property')->where('event', 'property_document_issued')->count())->toBe(2);
});

it('issues an ICS for the middle band and nothing else for it', function () {
    $user = prop_user();
    $decision = prop_decide($user, prop_line('5000.00'));

    prop_issue_ics($user, $decision)->assertSessionHasNoErrors();
    expect(PropertyDocument::query()->where('type', 'ics')->count())->toBe(1);

    // A PAR is not what this decision calls for.
    prop_issue_par($user, $decision, 1, 1)->assertForbidden();
    // A second ICS is refused while the first is issued.
    prop_issue_ics($user, $decision, 2)->assertForbidden();
});

it('refuses a Sub-PAR user who is not an active end-user', function () {
    $user = prop_user();
    $decision = prop_decide($user, prop_line('60000.00'));

    foreach ([prop_person(['is_end_user' => false]), prop_person(['is_active' => false])] as $bad) {
        prop_issue_par($user, $decision, 1, 1, $bad)->assertSessionHasErrors('person_id');
    }

    $this->actingAs($user)->post("/property-documents/decisions/{$decision->id}/documents", [
        'type' => 'par',
        'number' => prop_number(PropertyDocumentType::Par, 1),
        'sub_par_number' => prop_number(PropertyDocumentType::SubPar, 1),
    ])->assertSessionHasErrors('person_id');

    expect(PropertyDocument::query()->count())->toBe(0);
});

it('rejects a malformed number and a spelling that would dodge the unique index', function () {
    $user = prop_user();
    $decision = prop_decide($user, prop_line('5000.00'));
    $year = prop_year() % 100;

    foreach (['ICS-1', "ICS-{$year}-1", "ics {$year}-0001", "ICS-{$year}-00001", 'ICS-99-0001', ''] as $bad) {
        $this->actingAs($user)->post("/property-documents/decisions/{$decision->id}/documents", ['type' => 'ics', 'number' => $bad])
            ->assertSessionHasErrors('number');
    }

    expect(PropertyDocument::query()->count())->toBe(0);
});

it('refuses a number that is already used and never reuses a voided one', function () {
    $user = prop_user();
    $first = prop_decide($user, prop_line('5000.00'));
    prop_issue_ics($user, $first, 1)->assertSessionHasNoErrors();

    $second = prop_decide($user, prop_line('5000.00'));
    prop_issue_ics($user, $second, 1)->assertSessionHasErrors('number');

    $document = PropertyDocument::query()->firstOrFail();
    $this->actingAs($user)->post("/property-documents/documents/{$document->id}/void", ['reason' => 'Printed on the wrong form'])->assertSessionHasNoErrors();

    prop_issue_ics($user, $second, 1)->assertSessionHasErrors('number');
    prop_issue_ics($user, $second, 2)->assertSessionHasNoErrors();

    expect(PropertyDocument::query()->pluck('number')->sort()->values()->all())
        ->toBe([prop_number(PropertyDocumentType::Ics, 1), prop_number(PropertyDocumentType::Ics, 2)]);
});

it('suggests the next number, skipping what is recorded, and does not use it up', function () {
    $user = prop_user();
    $suggest = fn () => $this->actingAs($user)->getJson('/property-documents/number-suggestion?type=ics')->assertOk()->json('number');

    expect($suggest())->toBe(prop_number(PropertyDocumentType::Ics, 1))
        ->and($suggest())->toBe(prop_number(PropertyDocumentType::Ics, 1));

    prop_issue_ics($user, prop_decide($user, prop_line('5000.00')), 1)->assertSessionHasNoErrors();

    expect($suggest())->toBe(prop_number(PropertyDocumentType::Ics, 2));
});

// ---- voiding documents ------------------------------------------------------------------------------

it('voids a PAR together with its Sub-PAR and keeps both numbers on record', function () {
    $user = prop_user();
    $decision = prop_decide($user, prop_line('60000.00'));
    prop_issue_par($user, $decision, 1, 1)->assertSessionHasNoErrors();
    $par = PropertyDocument::query()->where('type', 'par')->firstOrFail();

    $this->actingAs($user)->post("/property-documents/documents/{$par->id}/void", ['reason' => 'abc'])->assertSessionHasErrors('reason');
    $this->actingAs($user)->post("/property-documents/documents/{$par->id}/void", ['reason' => 'Wrong end user named'])->assertSessionHasNoErrors();

    expect(PropertyDocument::query()->where('status', 'void')->count())->toBe(2)
        ->and(PropertyDocument::query()->where('type', 'sub_par')->first()->void_reason)->toContain('Wrong end user named');

    // The decision stands, so a new PAR with new numbers can be issued; the old numbers are refused.
    prop_issue_par($user, $decision, 1, 1)->assertSessionHasErrors('number');
    prop_issue_par($user, $decision, 2, 2)->assertSessionHasNoErrors();
    expect(PropertyDocument::query()->where('status', 'issued')->count())->toBe(2);
});

it('can void only the Sub-PAR and issue a new one for another user', function () {
    $user = prop_user();
    $decision = prop_decide($user, prop_line('60000.00'));
    prop_issue_par($user, $decision, 1, 1)->assertSessionHasNoErrors();
    $sub = PropertyDocument::query()->where('type', 'sub_par')->firstOrFail();

    $this->actingAs($user)->post("/property-documents/documents/{$sub->id}/void", ['reason' => 'Moved to another ward'])->assertSessionHasNoErrors();
    expect(PropertyDocument::query()->where('type', 'par')->first()->status)->toBe(PropertyDocumentStatus::Issued);

    $other = prop_person();
    $this->actingAs($user)->post("/property-documents/decisions/{$decision->id}/documents", [
        'type' => 'sub_par',
        'number' => prop_number(PropertyDocumentType::SubPar, 2),
        'person_id' => $other->id,
    ])->assertSessionHasNoErrors();

    $new = PropertyDocument::query()->where('type', 'sub_par')->where('status', 'issued')->firstOrFail();
    expect($new->user_employee_no)->toBe($other->employee_no)
        ->and($new->parent_id)->toBe(PropertyDocument::query()->where('type', 'par')->value('id'));
});

// ---- permissions ------------------------------------------------------------------------------------

it('lets each action through only with its own permission', function () {
    $decider = prop_user_with(['equipment.decide']);
    $parOnly = prop_user_with(['par.generate']);
    $icsOnly = prop_user_with(['ics.generate']);

    $line = prop_line('5000.00');
    $this->actingAs($parOnly)->post("/property-documents/items/{$line->id}/decision", ['is_equipment' => true])->assertForbidden();

    $decision = prop_decide($decider, $line);
    prop_issue_ics($decider, $decision)->assertForbidden();
    prop_issue_ics($parOnly, $decision)->assertForbidden();
    prop_issue_ics($icsOnly, $decision)->assertSessionHasNoErrors();

    $parLine = prop_decide($decider, prop_line('60000.00'));
    prop_issue_par($icsOnly, $parLine)->assertForbidden();
    prop_issue_par($parOnly, $parLine)->assertSessionHasNoErrors();

    $par = PropertyDocument::query()->where('type', 'par')->firstOrFail();
    $this->actingAs($icsOnly)->post("/property-documents/documents/{$par->id}/void", ['reason' => 'Not allowed to do this'])->assertForbidden();
    $this->actingAs($decider)->post("/property-documents/documents/{$par->id}/void", ['reason' => 'Not allowed to do this'])->assertForbidden();
});

it('keeps the property pages behind the section gate', function () {
    $line = prop_line('5000.00');
    $noAccess = prop_user(null);

    $this->actingAs($noAccess)->get('/property-documents')->assertForbidden();
    $this->actingAs($noAccess)->get("/property-documents/items/{$line->id}")->assertForbidden();
    $this->actingAs($noAccess)->getJson('/property-documents/number-suggestion?type=par')->assertForbidden();
    $this->actingAs(prop_user())->get('/property-documents')->assertOk();
});

// ---- source of the line -----------------------------------------------------------------------------

it('does not offer a decision for a voided delivery or a voided row', function () {
    $user = prop_user();

    $voidedDelivery = prop_line('5000.00');
    $voidedDelivery->delivery->forceFill(['status' => DeliveryStatus::Voided])->save();
    $this->actingAs($user)->post("/property-documents/items/{$voidedDelivery->id}/decision", ['is_equipment' => true])->assertForbidden();

    $voidedRow = prop_line('5000.00');
    $voidedRow->forceFill(['status' => 'void'])->save();
    $this->actingAs($user)->post("/property-documents/items/{$voidedRow->id}/decision", ['is_equipment' => true])->assertForbidden();

    expect(PropertyDecision::query()->count())->toBe(0);
});

it('allows property documents on a closed purchase order', function () {
    $user = prop_user();
    $line = prop_line('5000.00');

    // The closed state of the PO does not enter the rule (default of step 6.2).
    expect(PropertyDocumentRule::sourceBlockReason($line->load('delivery')))->toBeNull();
    prop_decide($user, $line);
});

it('lists a line under "Needs attention" when its delivery is voided after a decision, and changes nothing by itself', function () {
    $user = prop_user();
    $line = prop_line('5000.00');
    $decision = prop_decide($user, $line);
    prop_issue_ics($user, $decision)->assertSessionHasNoErrors();

    $line->delivery->forceFill(['status' => DeliveryStatus::Voided])->save();

    $this->actingAs($user)->get('/property-documents?tab=attention')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('property-documents/index')
            ->where('counts.attention', 1)->where('counts.pending', 0)->where('counts.done', 0));

    expect($decision->fresh()->status)->toBe(PropertyDecisionStatus::Active)
        ->and(PropertyDocument::query()->first()->status)->toBe(PropertyDocumentStatus::Issued);

    // Staff can still void by hand.
    $document = PropertyDocument::query()->firstOrFail();
    $this->actingAs($user)->post("/property-documents/documents/{$document->id}/void", ['reason' => 'Delivery was voided'])->assertSessionHasNoErrors();
});

// ---- lists ------------------------------------------------------------------------------------------

it('computes pending and done from the decisions and documents', function () {
    $user = prop_user();
    $undecided = prop_line('5000.00');
    $needsDocument = prop_decide($user, prop_line('5000.00'));
    $notEquipment = prop_decide($user, prop_line('5000.00', false), false);
    $finished = prop_decide($user, prop_line('5000.00'));
    prop_issue_ics($user, $finished, 1)->assertSessionHasNoErrors();

    $this->actingAs($user)->get('/property-documents')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('property-documents/index')
            ->where('tab', 'pending')
            ->where('counts.pending', 2)->where('counts.done', 2)->where('counts.attention', 0)
            ->where('rule.provisional', true));

    // Voiding the ICS moves that line back to pending, with nothing stored about "pending".
    $this->actingAs($user)->post('/property-documents/documents/'.PropertyDocument::query()->value('id').'/void', ['reason' => 'Printed twice'])->assertSessionHasNoErrors();

    $this->actingAs($user)->get('/property-documents?tab=voided')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('counts.voided', 1)->where('counts.pending', 3)->where('counts.done', 1));

    expect([$undecided->id, $needsDocument->delivery_item_id, $notEquipment->delivery_item_id])->each->toBeInt();
});

it('shows the sheet of one line with what the user may do', function () {
    $user = prop_user();
    $line = prop_line('60000.00');

    $this->actingAs($user)->get("/property-documents/items/{$line->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('property-documents/show')
            ->where('item.category_is_equipment', true)
            ->where('can.decide.allowed', true)
            ->where('decision', null)
            ->where('preview.equipment.value', 'par'));

    $decision = prop_decide($user, $line);

    $this->actingAs($user)->get("/property-documents/items/{$line->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('decision.id', $decision->id)
            ->where('can.issue.par.allowed', true)->where('can.issue.ics.allowed', false)
            ->where('missing', ['par', 'sub_par']));
});

// ---- Admin counters ---------------------------------------------------------------------------------

it('lets only Admin open the property numbers screen and set a counter', function () {
    $this->actingAs(prop_user())->get('/admin/property-numbers')->assertForbidden();
    $this->actingAs(prop_user())->put('/admin/property-numbers/counter', ['type' => 'par', 'year' => prop_year(), 'next_number' => 40])->assertForbidden();

    $this->actingAs(prop_admin())->get('/admin/property-numbers')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/property-numbers/index')->where('provisional', true));

    $this->actingAs(prop_admin())->put('/admin/property-numbers/counter', ['type' => 'par', 'year' => prop_year(), 'next_number' => 40])->assertSessionHasNoErrors();

    $user = prop_user();
    expect($this->actingAs($user)->getJson('/property-documents/number-suggestion?type=par')->json('number'))
        ->toBe(prop_number(PropertyDocumentType::Par, 40));
});

it('keeps the counter above the highest recorded number', function () {
    $user = prop_user();
    prop_issue_ics($user, prop_decide($user, prop_line('5000.00')), 7)->assertSessionHasNoErrors();

    $this->actingAs(prop_admin())->put('/admin/property-numbers/counter', ['type' => 'ics', 'year' => prop_year(), 'next_number' => 7])
        ->assertSessionHasErrors('next_number');
    // Issuing number 7 already moved the counter to 8, so 8 is "already that value": accepted, nothing changes, no audit entry.
    $this->actingAs(prop_admin())->put('/admin/property-numbers/counter', ['type' => 'ics', 'year' => prop_year(), 'next_number' => 8])
        ->assertSessionHasNoErrors();
    expect(Activity::query()->where('log_name', 'property')->where('event', 'property_counter_set')->count())->toBe(0);

    $this->actingAs(prop_admin())->put('/admin/property-numbers/counter', ['type' => 'ics', 'year' => prop_year(), 'next_number' => 20])
        ->assertSessionHasNoErrors();

    expect(PropertySeries::query()->where('type', 'ics')->value('next_number'))->toBe(20)
        ->and(Activity::query()->where('log_name', 'property')->where('event', 'property_counter_set')->count())->toBe(1);
});

it('records the decision and the issue in the audit log named property', function () {
    $user = prop_user();
    $decision = prop_decide($user, prop_line('60000.00'));
    prop_issue_par($user, $decision)->assertSessionHasNoErrors();

    expect(Activity::query()->where('log_name', 'property')->where('event', 'property_decided')->count())->toBe(1)
        ->and(Activity::query()->where('log_name', 'property')->where('event', 'property_document_issued')->count())->toBe(2);

    // Called directly, the service is also safe against a number typed twice in one request.
    $this->actingAs($user);
    expect(fn () => app(PropertyDocumentService::class)->issue(
        $decision->fresh(), PropertyDocumentType::Par, prop_number(PropertyDocumentType::Par, 5), prop_number(PropertyDocumentType::SubPar, 5), prop_person()->id, $user,
    ))->toThrow(ValidationException::class);
});