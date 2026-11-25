<?php

use App\Enums\DeliveryStatus;
use App\Enums\IarSeriesType;
use App\Enums\PoDocumentStatus;
use App\Enums\PoDocumentType;
use App\Enums\PurchaseOrderStatus;
use App\Events\DeliveryIarWindowExceeded;
use App\Models\Delivery;
use App\Models\Personnel;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PoDocumentService;
use App\Support\DeliveryDocumentRule;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/*
| Step 5.3: DR, OR and IAR files of a delivery, and the completeness gate.
| Helper names start with "ddoc_" so they cannot clash with the "delivery*" helpers of DeliveryTest.php or the
| "po_doc_*" / "po_types_*" helpers of the PO document tests. qpdf is faked: a stand-in file has 2 pages, a merged
| file has the sum of its parts, so "the PDF grows" and "voiding removes pages" can be counted without qpdf.
| NOT run when written (no project vendor tree in that session): run `php artisan test --filter=DeliveryDocumentTest`.
*/

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    config(['mms.pdf.qpdf_path' => 'qpdf-for-tests']);
    ddoc_fake_qpdf();
});

// ---- helpers ---------------------------------------------------------------------------------------

function ddoc_fake_qpdf(): void
{
    Process::fake(['*' => function (PendingProcess $process) {
        $command = (array) $process->command;

        if (in_array('--show-npages', $command, true)) {
            $file = (string) end($command);
            $content = is_file($file) ? (string) file_get_contents($file) : '';

            return Process::result(output: (str_starts_with($content, 'MERGED:') ? substr($content, 7) : '2')."\n");
        }

        if (in_array('--pages', $command, true)) {
            $parts = array_search('--', $command, true) - array_search('--pages', $command, true) - 1;
            file_put_contents((string) end($command), 'MERGED:'.($parts * 2));

            return Process::result(exitCode: 0);
        }

        return Process::result(exitCode: 0); // --check
    }]);
}

function ddoc_user(string $role, ?string $preset = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    if ($preset !== null) {
        $user->givePermissionTo(config("mms_access.job_presets.{$preset}.permissions"));
    }

    return $user;
}

function ddoc_encoder(): User
{
    return ddoc_user('MMS Staff', 'encoder');
}

function ddoc_pdf(string $name = 'scan.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

function ddoc_po(string $status = 'verified'): PurchaseOrder
{
    $po = PurchaseOrder::factory()->create(['po_date' => '2026-01-05']);
    $po->forceFill(['status' => PurchaseOrderStatus::from($status)])->save();

    return $po->fresh();
}

/** The PO Bundle already on the PO (2 pages for the fake qpdf). */
function ddoc_bundle(PurchaseOrder $po): PoDocument
{
    $stored = "po-documents/{$po->id}/".Str::uuid().'.pdf';
    Storage::disk('local')->put($stored, "%PDF-1.4\n%%EOF\n");

    return PoDocument::create([
        'purchase_order_id' => $po->id,
        'type' => PoDocumentType::PoBundle,
        'original_name' => 'bundle.pdf',
        'stored_path' => $stored,
        'original_path' => null,
        'mime' => 'application/pdf',
        'size_bytes' => 1000,
        'uploaded_by' => User::factory()->create()->id,
        'sort_order' => 1,
    ]);
}

/** A delivery recorded the normal way (POST /deliveries) so its IAR number exists too. */
function ddoc_delivery(PurchaseOrder $po, int $sequence = 8001, ?User $encoder = null): Delivery
{
    $year = (int) now()->setTimezone('Asia/Manila')->format('Y');
    $number = IarSeriesType::Regular->format($year, $sequence);

    test()->actingAs($encoder ?? ddoc_encoder())->post('/deliveries', [
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

    return Delivery::query()->where('iar_number', $number)->firstOrFail();
}

/** @param  list<string>  $types */
function ddoc_upload(User $user, Delivery $delivery, array $types)
{
    $groups = [];

    foreach ($types as $type) {
        $groups[] = ['type' => $type, 'files' => [ddoc_pdf("{$type}.pdf")]];
    }

    return test()->actingAs($user)->post("/deliveries/{$delivery->id}/documents", ['groups' => $groups]);
}

function ddoc_file(Delivery $delivery, string $type): PoDocument
{
    return PoDocument::query()->where('delivery_id', $delivery->id)->where('type', $type)->where('status', 'active')->firstOrFail();
}

function ddoc_complete_set(User $encoder, Delivery $delivery): void
{
    ddoc_upload($encoder, $delivery, ['dr', 'or', 'iar'])->assertSessionHasNoErrors();
}

function ddoc_pages(PurchaseOrder $po): ?int
{
    return $po->fresh()->consolidated_pages;
}

// ---- the completeness gate ------------------------------------------------------------------------

it('cannot complete with a missing file and names the missing ones', function () {
    $po = ddoc_po();
    ddoc_bundle($po);
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8001, $encoder);

    ddoc_upload($encoder, $delivery, ['dr']);

    $this->actingAs($encoder)
        ->post("/deliveries/{$delivery->id}/documents/complete")
        ->assertSessionHasErrors('form');

    $message = session('errors')->first('form');

    expect($message)->toContain('IAR')->toContain('OR')->not->toContain('DR,')
        ->and($delivery->fresh()->status)->toBe(DeliveryStatus::ForUploading);
});

it('completes once DR, OR and IAR all have an active file, through StatusTransition', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8002, $encoder);

    ddoc_complete_set($encoder, $delivery);

    $this->actingAs($encoder)
        ->post("/deliveries/{$delivery->id}/documents/complete")
        ->assertSessionHasNoErrors();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Complete);

    $entry = Activity::query()->where('log_name', 'delivery')->where('event', 'status_changed')->where('subject_id', $delivery->id)->latest('id')->first();

    expect($entry)->not->toBeNull()
        ->and($entry->properties['new']['status'])->toBe('Documents complete');
});

it('does not count an optional type as a required file', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8003, $encoder);

    ddoc_upload($encoder, $delivery, ['dr', 'or', 'supporting_document'])->assertSessionHasNoErrors();

    expect(DeliveryDocumentRule::missing($delivery))->toBe(['IAR']);

    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/documents/complete")->assertSessionHasErrors('form');
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::ForUploading);
});

it('reads the required list from config, so a new required type blocks completion', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8004, $encoder);

    ddoc_complete_set($encoder, $delivery);

    $types = collect(config('mms.documents.types'))
        ->map(fn (array $row) => $row['key'] === 'supporting_document' ? [...$row, 'required' => true] : $row)
        ->all();
    config(['mms.documents.types' => $types]);

    expect(DeliveryDocumentRule::missing($delivery))->toBe(['Supporting Document']);

    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/documents/complete")->assertSessionHasErrors('form');
});

it('lists the checklist for the page with the missing names', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8005, $encoder);

    ddoc_upload($encoder, $delivery, ['or']);

    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}")
        ->assertInertia(fn ($page) => $page
            ->where('documents.checklist.required_total', 3)
            ->where('documents.checklist.required_done', 1)
            ->where('documents.checklist.missing', ['DR', 'IAR'])
            ->where('documents.can_upload', true)
            ->where('documents.can_complete', true));
});

// ---- the combined PDF -----------------------------------------------------------------------------

it('grows the PO\'s combined PDF with each upload and shrinks it when a delivery file is voided', function () {
    $po = ddoc_po();
    ddoc_bundle($po);
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8010, $encoder);

    ddoc_upload($encoder, $delivery, ['dr']);
    expect(ddoc_pages($po))->toBe(4);

    ddoc_upload($encoder, $delivery, ['or']);
    expect(ddoc_pages($po))->toBe(6);

    ddoc_upload($encoder, $delivery, ['iar']);
    expect(ddoc_pages($po))->toBe(8);

    $dr = ddoc_file($delivery, 'dr');

    $this->actingAs($encoder)
        ->post("/deliveries/{$delivery->id}/documents/{$dr->id}/void", ['reason' => 'Wrong supplier DR'])
        ->assertSessionHasNoErrors();

    expect(ddoc_pages($po))->toBe(6)
        ->and($dr->fresh()->status)->toBe(PoDocumentStatus::Void)
        ->and($dr->fresh()->void_reason)->toBe('Wrong supplier DR');
});

it('puts the PO files first, then each delivery with its files in type order, and numbers the pages', function () {
    $po = ddoc_po();
    ddoc_bundle($po);
    $encoder = ddoc_encoder();
    $first = ddoc_delivery($po, 8011, $encoder);
    $second = ddoc_delivery($po, 8012, $encoder);

    ddoc_upload($encoder, $second, ['iar']);
    ddoc_upload($encoder, $first, ['or', 'dr']);

    $rows = collect(app(PoDocumentService::class)->rowsFor($po->fresh()));

    expect($rows->pluck('type_key')->all())->toBe(['po_bundle', 'dr', 'or', 'iar'])
        ->and($rows->pluck('delivery_id')->all())->toBe([null, $first->id, $first->id, $second->id])
        ->and($rows->pluck('page_from')->all())->toBe([1, 3, 5, 7])
        ->and($rows->pluck('page_to')->all())->toBe([2, 4, 6, 8]);
});

it('keeps the delivery files out of the PDF until the delivery is complete when the switch says so', function () {
    config(['mms.documents.delivery_in_pdf' => 'when_complete']);

    $po = ddoc_po();
    ddoc_bundle($po);
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8013, $encoder);

    ddoc_complete_set($encoder, $delivery);
    expect(ddoc_pages($po))->toBe(2);

    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/documents/complete")->assertSessionHasNoErrors();
    expect(ddoc_pages($po))->toBe(8);
});

// ---- voiding, replacing, reopening ---------------------------------------------------------------

it('sends a complete delivery back to for_uploading when a required file is voided without a replacement', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8020, $encoder);

    ddoc_complete_set($encoder, $delivery);
    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/documents/complete");

    $or = ddoc_file($delivery, 'or');

    $this->actingAs($encoder)
        ->post("/deliveries/{$delivery->id}/documents/{$or->id}/void", ['reason' => 'Scanned upside down'])
        ->assertSessionHasNoErrors();

    $entry = Activity::query()->where('log_name', 'delivery')->where('event', 'status_changed')->where('subject_id', $delivery->id)->latest('id')->first();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::ForUploading)
        ->and($entry->properties['reason'])->toContain('Scanned upside down')->toContain('OR');
});

it('keeps a complete delivery complete when an optional file is voided or a file is replaced', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8021, $encoder);

    ddoc_upload($encoder, $delivery, ['dr', 'or', 'iar', 'supporting_document']);
    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/documents/complete");

    $this->actingAs($encoder)
        ->post("/deliveries/{$delivery->id}/documents/".ddoc_file($delivery, 'supporting_document')->id.'/void', ['reason' => 'Not needed here'])
        ->assertSessionHasNoErrors();

    $dr = ddoc_file($delivery, 'dr');

    $this->actingAs($encoder)
        ->post("/deliveries/{$delivery->id}/documents/{$dr->id}/replace", ['reason' => 'Better scan', 'file' => ddoc_pdf('dr2.pdf')])
        ->assertSessionHasNoErrors();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Complete)
        ->and($dr->fresh()->status)->toBe(PoDocumentStatus::Void)
        ->and(ddoc_file($delivery, 'dr')->original_name)->toBe('dr2.pdf');
});

it('needs a reason to reopen, blocks editing while complete and accepts uploads again after the reopen', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8022, $encoder);

    ddoc_complete_set($encoder, $delivery);
    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/documents/complete");

    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}/edit")->assertForbidden();
    ddoc_upload($encoder, $delivery, ['supporting_document'])->assertForbidden();

    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/documents/reopen", [])->assertSessionHasErrors('reason');
    expect($delivery->fresh()->status)->toBe(DeliveryStatus::Complete);

    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/documents/reopen", ['reason' => 'Add a supporting file'])->assertSessionHasNoErrors();

    expect($delivery->fresh()->status)->toBe(DeliveryStatus::ForUploading);

    ddoc_upload($encoder, $delivery, ['supporting_document'])->assertSessionHasNoErrors();
});

it('voids the files of a delivery with the delivery and drops their pages', function () {
    $po = ddoc_po();
    ddoc_bundle($po);
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8023, $encoder);

    ddoc_complete_set($encoder, $delivery);
    expect(ddoc_pages($po))->toBe(8);

    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/void", ['reason' => 'Entered by mistake'])->assertSessionHasNoErrors();

    $files = PoDocument::query()->where('delivery_id', $delivery->id)->get();

    expect($files)->toHaveCount(3)
        ->and($files->every(fn (PoDocument $file) => $file->status === PoDocumentStatus::Void && $file->void_reason === 'Entered by mistake'))->toBeTrue()
        ->and(ddoc_pages($po))->toBe(2);
});

// ---- who may do what ------------------------------------------------------------------------------

it('keeps the Encoder away from the PO Bundle', function () {
    $po = ddoc_po('uploaded');
    $bundle = ddoc_bundle($po);
    $encoder = ddoc_encoder();

    $this->actingAs($encoder)->post("/purchase-orders/{$po->id}/documents", ['groups' => [['type' => 'po_bundle', 'files' => [ddoc_pdf()]]]])->assertForbidden();
    $this->actingAs($encoder)->post("/purchase-orders/{$po->id}/documents/{$bundle->id}/void", ['reason' => 'Trying it'])->assertForbidden();

    expect($bundle->fresh()->status)->toBe(PoDocumentStatus::Active)
        ->and(PoDocument::count())->toBe(1);
});

it('refuses a PO Bundle type on a delivery and a delivery type on the PO', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8030, $encoder);

    ddoc_upload($encoder, $delivery, ['po_bundle'])->assertSessionHasErrors('groups.0.type');

    expect(PoDocument::count())->toBe(0)
        ->and(app(PoDocumentService::class)->rowsFor($po))->toBe([]);

    // A verified PO accepts no PO uploads at all (403), so the PO-side type check needs a draft PO.
    $draft = ddoc_po('draft');
    $procurement = ddoc_user('Procurement');

    $this->actingAs($procurement)
        ->post("/purchase-orders/{$draft->id}/documents", ['groups' => [['type' => 'dr', 'files' => [ddoc_pdf()]]]])
        ->assertSessionHasErrors('groups.0.type');

    expect(PoDocument::count())->toBe(0);
});

it('ignores a delivery_id sent to the PO upload: a PO file never carries one', function () {
    $po = ddoc_po('draft');
    $delivery = Delivery::factory()->create(['purchase_order_id' => $po->id]);

    $this->actingAs(ddoc_user('Procurement'))
        ->post("/purchase-orders/{$po->id}/documents", [
            'delivery_id' => $delivery->id,
            'groups' => [['type' => 'po_bundle', 'files' => [ddoc_pdf()]]],
        ])
        ->assertSessionHasNoErrors();

    expect(PoDocument::query()->firstOrFail()->delivery_id)->toBeNull();
});

it('refuses a delivery file from another PO and PO-level actions on a delivery file', function () {
    $po = ddoc_po();
    $other = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8031, $encoder);
    $otherDelivery = ddoc_delivery($other, 8032, $encoder);

    ddoc_upload($encoder, $delivery, ['dr']);
    $dr = ddoc_file($delivery, 'dr');

    // The file is addressed through the wrong delivery.
    $this->actingAs($encoder)->post("/deliveries/{$otherDelivery->id}/documents/{$dr->id}/void", ['reason' => 'Wrong place'])->assertNotFound();
    $this->actingAs($encoder)->get("/deliveries/{$otherDelivery->id}/documents/{$dr->id}/file")->assertNotFound();

    // A PO-level action never reaches it, even for the people who may void PO documents.
    $procurement = ddoc_user('Procurement');
    $po->forceFill(['status' => PurchaseOrderStatus::Uploaded])->save();

    $this->actingAs($procurement)->post("/purchase-orders/{$po->id}/documents/{$dr->id}/void", ['reason' => 'Not yours'])->assertNotFound();
    expect($dr->fresh()->status)->toBe(PoDocumentStatus::Active);
});

it('keeps Procurement away from delivery files', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8033, $encoder);
    ddoc_upload($encoder, $delivery, ['dr']);
    $dr = ddoc_file($delivery, 'dr');
    $procurement = ddoc_user('Procurement');

    expect(Gate::forUser($procurement)->denies('uploadDocuments', $delivery))->toBeTrue()
        ->and(Gate::forUser($procurement)->denies('voidDocument', $delivery))->toBeTrue()
        ->and(Gate::forUser($procurement)->denies('replaceDocument', $delivery))->toBeTrue()
        ->and(Gate::forUser($procurement)->denies('completeDocuments', $delivery))->toBeTrue();

    $this->actingAs($procurement)->post("/deliveries/{$delivery->id}/documents", ['groups' => [['type' => 'or', 'files' => [ddoc_pdf()]]]])->assertForbidden();
    $this->actingAs($procurement)->post("/deliveries/{$delivery->id}/documents/{$dr->id}/void", ['reason' => 'Not allowed'])->assertForbidden();

    expect(PoDocument::query()->where('delivery_id', $delivery->id)->count())->toBe(1);
});

it('gives 403 to a staff member without delivery.upload-docs, for every document action', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8034, $encoder);
    ddoc_upload($encoder, $delivery, ['dr']);
    $dr = ddoc_file($delivery, 'dr');

    $plain = ddoc_user('MMS Staff'); // the base role only, no job preset

    $this->actingAs($plain)->post("/deliveries/{$delivery->id}/documents", ['groups' => [['type' => 'or', 'files' => [ddoc_pdf()]]]])->assertForbidden();
    $this->actingAs($plain)->post("/deliveries/{$delivery->id}/documents/complete")->assertForbidden();
    $this->actingAs($plain)->post("/deliveries/{$delivery->id}/documents/reopen", ['reason' => 'No right'])->assertForbidden();
    $this->actingAs($plain)->post("/deliveries/{$delivery->id}/documents/{$dr->id}/void", ['reason' => 'No right'])->assertForbidden();
    $this->actingAs($plain)->post("/deliveries/{$delivery->id}/documents/{$dr->id}/replace", ['reason' => 'No right', 'file' => ddoc_pdf()])->assertForbidden();
    $this->actingAs($plain)->get("/deliveries/{$delivery->id}/documents/{$dr->id}/file")->assertForbidden();

    expect(PoDocument::query()->where('delivery_id', $delivery->id)->count())->toBe(1)
        ->and($dr->fresh()->status)->toBe(PoDocumentStatus::Active);
});

it('lets the Encoder open one delivery file, and only that delivery\'s file', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8035, $encoder);
    ddoc_upload($encoder, $delivery, ['dr']);

    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}/documents/".ddoc_file($delivery, 'dr')->id.'/file')->assertOk();
});

it('accepts no upload for a voided delivery or a closed PO', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8040, $encoder);

    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/void", ['reason' => 'Entered by mistake'])->assertSessionHasNoErrors();
    ddoc_upload($encoder, $delivery, ['dr'])->assertForbidden();

    $closedPo = ddoc_po();
    $open = ddoc_delivery($closedPo, 8041, $encoder);
    $closedPo->forceFill(['closed_at' => now()])->save();

    ddoc_upload($encoder, $open, ['dr'])->assertForbidden();

    expect(PoDocument::count())->toBe(0);
});

// ---- the IAR window event ------------------------------------------------------------------------

it('writes the IAR window event once per delivery, when it is loaded, and fires the Laravel event once', function () {
    Event::fake([DeliveryIarWindowExceeded::class]);

    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8050, $encoder);

    // Not overdue yet: nothing is written.
    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}")->assertOk();
    expect(Activity::query()->where('event', 'iar_window_exceeded')->count())->toBe(0);

    $delivery->forceFill(['date_received' => now()->setTimezone('Asia/Manila')->subDays(30)->toDateString()])->save();

    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}")->assertOk();
    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}")->assertOk();
    $this->actingAs($encoder)->get('/deliveries')->assertOk();
    ddoc_upload($encoder, $delivery, ['dr'])->assertSessionHasNoErrors();

    expect(Activity::query()->where('log_name', 'delivery')->where('event', 'iar_window_exceeded')->where('subject_id', $delivery->id)->count())->toBe(1);

    Event::assertDispatchedTimes(DeliveryIarWindowExceeded::class, 1);
});

it('writes the window event once for each of two late deliveries', function () {
    Event::fake([DeliveryIarWindowExceeded::class]);

    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $a = ddoc_delivery($po, 8051, $encoder);
    $b = ddoc_delivery($po, 8052, $encoder);

    foreach ([$a, $b] as $late) {
        $late->forceFill(['date_received' => now()->setTimezone('Asia/Manila')->subDays(20)->toDateString()])->save();
    }

    $this->actingAs($encoder)->get('/deliveries')->assertOk();
    $this->actingAs($encoder)->get('/deliveries')->assertOk();

    expect(Activity::query()->where('event', 'iar_window_exceeded')->count())->toBe(2);
});

it('does not write the window event for a complete or voided delivery', function () {
    $po = ddoc_po();
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8053, $encoder);

    ddoc_complete_set($encoder, $delivery);
    $this->actingAs($encoder)->post("/deliveries/{$delivery->id}/documents/complete");
    $delivery->forceFill(['date_received' => now()->setTimezone('Asia/Manila')->subDays(30)->toDateString()])->save();

    $this->actingAs($encoder)->get("/deliveries/{$delivery->id}")->assertOk();

    expect(Activity::query()->where('event', 'iar_window_exceeded')->count())->toBe(0);
});

it('keeps delivery files out of the PO checklist and the PO Bundle count', function () {
    $po = ddoc_po();
    ddoc_bundle($po);
    $encoder = ddoc_encoder();
    $delivery = ddoc_delivery($po, 8060, $encoder);

    ddoc_complete_set($encoder, $delivery);

    $progress = \App\Support\PoDocumentChecklist::progress($po->fresh());

    expect($progress['required_total'])->toBe(1)
        ->and($progress['required_done'])->toBe(1)
        ->and(collect($progress['items'])->pluck('key')->all())->not->toContain('dr')
        ->and(app(PoDocumentService::class)->hasActive($po))->toBeTrue();
});