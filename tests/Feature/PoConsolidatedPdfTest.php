<?php

use App\Enums\PoDocumentStatus;
use App\Enums\PoDocumentType;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\Qpdf;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Step 4.3: the consolidated PDF, the page index, void and replace.
 *
 * Two kinds of test:
 *  - Most use a FAKE qpdf (Process::fake). po_cons_fake() answers the three commands the app uses:
 *    "--check" (exit 0), "--show-npages" (every stand-in file has 2 pages; a "merged" file has the sum)
 *    and "--pages ... -- out" (writes a small stand-in output file, or returns the exit code you choose).
 *    So the tests need no program, and can also test exit 3 and exit 2.
 *  - The last tests use the REAL qpdf and the two real office scans in tests/Fixtures/pdf
 *    (4 pages + 14 pages = 18). They are skipped when qpdf cannot be found: set MMS_QPDF_PATH in .env
 *    (or have "qpdf" on the PATH on Linux/macOS).
 */

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
    config(['mms.pdf.qpdf_path' => 'qpdf-for-tests']);
});

function po_cons_user(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

/**
 * Every command the fake qpdf was asked to run, in order. (This Laravel version has no Process::recorded(),
 * so the fake writes them down itself.)
 *
 * @return list<list<string>>
 */
function po_cons_commands(?array $add = null, bool $reset = false): array
{
    static $commands = [];

    if ($reset) {
        $commands = [];
    }

    if ($add !== null) {
        $commands[] = $add;
    }

    return $commands;
}

/** A fake qpdf. $mergeExit is the exit code of the merge command (0, 3 = output written; 2 = failure). */
function po_cons_fake(int $mergeExit = 0): void
{
    po_cons_commands(reset: true);

    Process::fake(['*' => function (PendingProcess $process) use ($mergeExit) {
        $command = (array) $process->command;
        po_cons_commands($command);

        if (in_array('--show-npages', $command, true)) {
            $file = (string) end($command);
            $content = is_file($file) ? (string) file_get_contents($file) : '';

            return Process::result(output: (str_starts_with($content, 'MERGED:') ? substr($content, 7) : '2')."\n");
        }

        if (in_array('--pages', $command, true)) {
            $parts = array_search('--', $command, true) - array_search('--pages', $command, true) - 1;

            if (in_array($mergeExit, [0, 3], true)) {
                file_put_contents((string) end($command), 'MERGED:'.($parts * 2));
            }

            return Process::result(errorOutput: $mergeExit === 3 ? 'warning: test warning' : '', exitCode: $mergeExit);
        }

        return Process::result(exitCode: 0); // --check
    }]);
}

function po_cons_pdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

function po_cons_url(PurchaseOrder $po): string
{
    return "/purchase-orders/{$po->id}/documents";
}

/** A document already on the PO, with a file on the fake disk and NO page count (like a PO from before step 4.3). */
function po_cons_make(PurchaseOrder $po, string $name, int $order): PoDocument
{
    $stored = "po-documents/{$po->id}/".Str::uuid().'.pdf';
    Storage::disk('local')->put($stored, "%PDF-1.4\n%%EOF\n");

    return PoDocument::create([
        'purchase_order_id' => $po->id,
        'type' => PoDocumentType::PoBundle,
        'original_name' => $name,
        'stored_path' => $stored,
        'original_path' => null,
        'mime' => 'application/pdf',
        'size_bytes' => 1000,
        'uploaded_by' => User::factory()->create()->id,
        'sort_order' => $order,
    ]);
}

/** @return list<string> the files in the consolidated folder of the fake disk */
function po_cons_files(PurchaseOrder $po): array
{
    return collect(Storage::disk('local')->allFiles("po-documents/{$po->id}"))
        ->filter(fn (string $file) => str_contains($file, 'consolidated-'))
        ->values()
        ->all();
}

/* ---------------------------------------------------------------- building it */

it('builds the consolidated PDF after an upload and appends the next upload as the next pages', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $user = po_cons_user('Procurement');

    $this->actingAs($user)->post(po_cons_url($po), ['file' => po_cons_pdf('first.pdf')])->assertSessionHasNoErrors();

    $po->refresh();
    $firstPath = $po->consolidated_path;

    expect($firstPath)->not->toBeNull();
    expect($po->consolidated_pages)->toBe(2)
        ->and($po->consolidated_stale)->toBeFalse()
        ->and($po->consolidated_built_at)->not->toBeNull();
    Storage::disk('local')->assertExists($firstPath);

    $this->actingAs($user)->post(po_cons_url($po), ['file' => po_cons_pdf('second.pdf')])->assertSessionHasNoErrors();

    $po->refresh();

    expect($po->consolidated_pages)->toBe(4)
        ->and($po->consolidated_path)->not->toBe($firstPath)
        ->and(PoDocument::orderBy('sort_order')->pluck('page_count')->all())->toBe([2, 2]);

    // The old file is gone once the new one is in place: exactly one consolidated file remains.
    Storage::disk('local')->assertMissing($firstPath);
    expect(po_cons_files($po))->toBe([$po->consolidated_path]);

    // The page index comes from our records: first upload = first pages.
    $this->actingAs($user)
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.consolidated.exists', true)
            ->where('purchaseOrder.documents.consolidated.pages', 4)
            ->where('purchaseOrder.documents.consolidated.stale', false)
            ->where('purchaseOrder.documents.rows.0.original_name', 'first.pdf')
            ->where('purchaseOrder.documents.rows.0.page_from', 1)
            ->where('purchaseOrder.documents.rows.0.page_to', 2)
            ->where('purchaseOrder.documents.rows.1.original_name', 'second.pdf')
            ->where('purchaseOrder.documents.rows.1.page_from', 3)
            ->where('purchaseOrder.documents.rows.1.page_to', 4));
});

it('gives qpdf the files in page order and changes nothing about the pages', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $user = po_cons_user('Procurement');

    $this->actingAs($user)->post(po_cons_url($po), ['file' => po_cons_pdf('first.pdf')]);
    $this->actingAs($user)->post(po_cons_url($po), ['file' => po_cons_pdf('second.pdf')]);

    [$first, $second] = PoDocument::orderBy('sort_order')->get()->all();

    // Move the second file up: the next merge must list it first.
    $this->actingAs($user)->post(po_cons_url($po)."/{$second->id}/move", ['direction' => 'up'])->assertSessionHasNoErrors();

    $merges = array_values(array_filter(po_cons_commands(), fn (array $command) => in_array('--pages', $command, true)));
    $merge = end($merges);
    $start = array_search('--pages', $merge, true) + 1;
    $files = array_slice($merge, $start, array_search('--', $merge, true) - $start);

    expect($merge[1])->toBe('--empty')
        ->and($merge[2])->toBe('--pages')
        ->and(array_map('basename', $files))->toBe([basename($second->stored_path), basename($first->stored_path)])
        ->and($merge)->not->toContain('--rotate')
        ->and($merge)->not->toContain('--stream-data=compress');

    // And the page index follows the new order.
    $this->actingAs($user)
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.rows.0.original_name', 'second.pdf')
            ->where('purchaseOrder.documents.rows.0.page_from', 1)
            ->where('purchaseOrder.documents.rows.1.original_name', 'first.pdf')
            ->where('purchaseOrder.documents.rows.1.page_from', 3));
});

it('counts the pages of documents that were uploaded before step 4.3', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    po_cons_make($po, 'old-a.pdf', 1);
    po_cons_make($po, 'old-b.pdf', 2);

    $this->actingAs(po_cons_user('Procurement'))->post(po_cons_url($po).'/rebuild')->assertSessionHasNoErrors();

    expect($po->fresh()->consolidated_pages)->toBe(4)
        ->and(PoDocument::orderBy('sort_order')->pluck('page_count')->all())->toBe([2, 2]);
});

it('rebuilds after a file is removed from a draft, and clears the combined PDF when none is left', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $user = po_cons_user('Procurement');

    $this->actingAs($user)->post(po_cons_url($po), ['file' => po_cons_pdf('a.pdf')]);
    $this->actingAs($user)->post(po_cons_url($po), ['file' => po_cons_pdf('b.pdf')]);

    $a = PoDocument::orderBy('sort_order')->first();
    $this->actingAs($user)->delete(po_cons_url($po)."/{$a->id}")->assertSessionHasNoErrors();

    expect($po->fresh()->consolidated_pages)->toBe(2)
        ->and(po_cons_files($po))->toHaveCount(1);

    $b = PoDocument::sole();
    $this->actingAs($user)->delete(po_cons_url($po)."/{$b->id}")->assertSessionHasNoErrors();

    $po->refresh();

    expect($po->consolidated_path)->toBeNull()
        ->and($po->consolidated_pages)->toBeNull()
        ->and($po->consolidated_stale)->toBeFalse()
        ->and(po_cons_files($po))->toBe([]);
});

/* ---------------------------------------------------------------- failure and warnings */

it('keeps the previous consolidated PDF when the merge fails (qpdf exit 2), says so, and a retry fixes it', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $user = po_cons_user('Procurement');

    po_cons_fake();
    $this->actingAs($user)->post(po_cons_url($po), ['file' => po_cons_pdf('first.pdf')]);
    $previous = $po->fresh()->consolidated_path;

    // The second upload is saved, but the merge fails.
    po_cons_fake(mergeExit: 2);
    $this->actingAs($user)
        ->post(po_cons_url($po), ['file' => po_cons_pdf('second.pdf')])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', fn (string $message) => str_contains($message, 'could not be rebuilt') && str_contains($message, 'previous version'));

    $po->refresh();

    expect(PoDocument::count())->toBe(2)
        ->and($po->consolidated_path)->toBe($previous)
        ->and($po->consolidated_pages)->toBe(2)
        ->and($po->consolidated_stale)->toBeTrue()
        ->and($po->consolidated_error)->not->toBeNull()
        ->and(po_cons_files($po))->toBe([$previous])
        ->and(collect(Storage::disk('local')->allFiles("po-documents/{$po->id}"))->contains(fn (string $file) => str_ends_with($file, '.tmp')))->toBeFalse();

    // Everybody who opens the tab is told the PDF is out of date.
    $this->actingAs(po_cons_user('MMS Staff'))
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.consolidated.stale', true)
            ->where('purchaseOrder.documents.can_rebuild', false));

    // The retry (qpdf works again) builds all four pages and removes the old file.
    po_cons_fake();
    $this->actingAs($user)->post(po_cons_url($po).'/rebuild')->assertSessionHasNoErrors();

    $po->refresh();

    expect($po->consolidated_stale)->toBeFalse()
        ->and($po->consolidated_error)->toBeNull()
        ->and($po->consolidated_pages)->toBe(4)
        ->and(po_cons_files($po))->toBe([$po->consolidated_path]);
    Storage::disk('local')->assertMissing($previous);
});

it('treats qpdf exit 3 (warnings) as success and logs the warning', function () {
    Log::spy();
    po_cons_fake(mergeExit: 3);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_cons_user('Procurement'))->post(po_cons_url($po), ['file' => po_cons_pdf('scan.pdf')])->assertSessionHasNoErrors();

    $po->refresh();

    expect($po->consolidated_pages)->toBe(2)
        ->and($po->consolidated_stale)->toBeFalse();
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'warnings'))->once();
});

it('marks the PDF as out of date, and never crashes, when qpdf cannot be started', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    po_cons_make($po, 'a.pdf', 1);
    config(['mms.pdf.qpdf_path' => '']);

    $this->actingAs(po_cons_user('Procurement'))->post(po_cons_url($po).'/rebuild')->assertSessionHasNoErrors();

    expect($po->fresh()->consolidated_stale)->toBeTrue()
        ->and($po->fresh()->consolidated_path)->toBeNull();
});

/* ---------------------------------------------------------------- void */

it('voids a document with a reason: its pages leave the combined PDF and the originals stay', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    $a = po_cons_make($po, 'a.pdf', 1);
    $b = po_cons_make($po, 'b.pdf', 2);
    $c = po_cons_make($po, 'c.pdf', 3);
    $user = po_cons_user('Procurement');

    $this->actingAs($user)->post(po_cons_url($po).'/rebuild');
    $before = $po->fresh()->consolidated_path;

    expect($po->fresh()->consolidated_pages)->toBe(6);

    $this->actingAs($user)
        ->post(po_cons_url($po)."/{$b->id}/void", ['reason' => 'Wrong supplier invoice'])
        ->assertRedirect(route('purchase-orders.show', $po))
        ->assertSessionHasNoErrors();

    $b->refresh();
    $po->refresh();

    expect($b->status)->toBe(PoDocumentStatus::Void)
        ->and($b->void_reason)->toBe('Wrong supplier invoice')
        ->and($b->voided_by)->toBe($user->id)
        ->and($b->voided_at)->not->toBeNull()
        ->and($po->consolidated_pages)->toBe(4)
        ->and($po->consolidated_path)->not->toBe($before);

    // Never deleted: the row and the file are still there, and the numbers are unchanged.
    Storage::disk('local')->assertExists($b->stored_path);
    expect(PoDocument::orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2, 3]);

    $activity = Activity::where('event', 'document_voided')->where('log_name', 'po')->sole();
    expect(data_get($activity->properties, 'reason'))->toBe('Wrong supplier invoice');

    $this->actingAs($user)
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.rows.0.page_from', 1)
            ->where('purchaseOrder.documents.rows.0.page_to', 2)
            ->where('purchaseOrder.documents.rows.1.status', 'void')
            ->where('purchaseOrder.documents.rows.1.page_from', null)
            ->where('purchaseOrder.documents.rows.1.void_reason', 'Wrong supplier invoice')
            ->where('purchaseOrder.documents.rows.2.page_from', 3)
            ->where('purchaseOrder.documents.rows.2.page_to', 4));

    expect($a->fresh()->status)->toBe(PoDocumentStatus::Active)
        ->and($c->fresh()->status)->toBe(PoDocumentStatus::Active);
});

it('needs a reason to void and refuses the last active document', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    $a = po_cons_make($po, 'a.pdf', 1);
    $b = po_cons_make($po, 'b.pdf', 2);
    $user = po_cons_user('Procurement');

    $this->actingAs($user)->post(po_cons_url($po)."/{$a->id}/void", [])->assertSessionHasErrors('reason');
    $this->actingAs($user)->post(po_cons_url($po)."/{$a->id}/void", ['reason' => 'x'])->assertSessionHasErrors('reason');
    expect($a->fresh()->status)->toBe(PoDocumentStatus::Active);

    $this->actingAs($user)->post(po_cons_url($po)."/{$a->id}/void", ['reason' => 'Scanned upside down'])->assertSessionHasNoErrors();

    // b is now the only active document: replace it instead.
    $this->actingAs($user)
        ->post(po_cons_url($po)."/{$b->id}/void", ['reason' => 'Also wrong'])
        ->assertSessionHasErrors('form');
    expect($b->fresh()->status)->toBe(PoDocumentStatus::Active);

    // A document that is already void cannot be voided again.
    $this->actingAs($user)->post(po_cons_url($po)."/{$a->id}/void", ['reason' => 'Again'])->assertSessionHasErrors('form');
});

it('allows void only on uploaded and escalated POs, and only for documents.void with po.upload-files', function () {
    po_cons_fake();
    $user = po_cons_user('Procurement');

    foreach (['draft', 'verified', 'for_termination'] as $status) {
        $po = PurchaseOrder::factory()->create(['status' => $status]);
        po_cons_make($po, 'a.pdf', 1);
        $b = po_cons_make($po, 'b.pdf', 2);

        $this->actingAs($user)->post(po_cons_url($po)."/{$b->id}/void", ['reason' => 'Wrong file'])->assertForbidden();
        expect($b->fresh()->status)->toBe(PoDocumentStatus::Active);
    }

    $escalated = PurchaseOrder::factory()->create(['status' => 'escalated']);
    po_cons_make($escalated, 'a.pdf', 1);
    $e = po_cons_make($escalated, 'b.pdf', 2);
    $this->actingAs($user)->post(po_cons_url($escalated)."/{$e->id}/void", ['reason' => 'Wrong file'])->assertSessionHasNoErrors();
    expect($e->fresh()->status)->toBe(PoDocumentStatus::Void);

    $uploaded = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    po_cons_make($uploaded, 'a.pdf', 1);
    $u = po_cons_make($uploaded, 'b.pdf', 2);

    // Without documents.void, or without po.upload-files (an Encoder-only user), or with neither.
    $encoderOnly = po_cons_user('MMS Staff')->givePermissionTo('documents.void');

    foreach ([po_cons_user('MMS Staff'), po_cons_user('Finance'), po_cons_user('Admin'), $encoderOnly] as $outsider) {
        $this->actingAs($outsider)->post(po_cons_url($uploaded)."/{$u->id}/void", ['reason' => 'Wrong file'])->assertForbidden();
    }

    expect($u->fresh()->status)->toBe(PoDocumentStatus::Active);
});

it('gives 404 when the document belongs to another PO', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    $other = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    $document = po_cons_make($other, 'a.pdf', 1);

    $this->actingAs(po_cons_user('Procurement'))
        ->post(po_cons_url($po)."/{$document->id}/void", ['reason' => 'Wrong file'])
        ->assertNotFound();
});

/* ---------------------------------------------------------------- replace */

it('replaces a document: the old one is voided and the new file takes its place in the page order', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'escalated']);
    po_cons_make($po, 'a.pdf', 1);
    $b = po_cons_make($po, 'b.pdf', 2);
    po_cons_make($po, 'c.pdf', 3);
    $user = po_cons_user('Procurement');

    $this->actingAs($user)
        ->post(po_cons_url($po)."/{$b->id}/replace", ['reason' => 'Page 2 was missing', 'file' => po_cons_pdf('b-complete.pdf')])
        ->assertRedirect(route('purchase-orders.show', $po))
        ->assertSessionHasNoErrors();

    $po->refresh();

    // a(1) b(2, void) b-complete(3) c(4): the new file sits right after the one it replaces.
    expect(PoDocument::orderBy('sort_order')->pluck('original_name')->all())->toBe(['a.pdf', 'b.pdf', 'b-complete.pdf', 'c.pdf'])
        ->and(PoDocument::orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2, 3, 4])
        ->and($b->fresh()->status)->toBe(PoDocumentStatus::Void)
        ->and($b->fresh()->void_reason)->toBe('Page 2 was missing')
        ->and(PoDocument::where('original_name', 'b-complete.pdf')->sole()->type)->toBe(PoDocumentType::PoBundle)
        ->and($po->consolidated_pages)->toBe(6);

    $activity = Activity::where('event', 'document_replaced')->where('log_name', 'po')->sole();
    expect(data_get($activity->properties, 'reason'))->toBe('Page 2 was missing')
        ->and(Activity::where('event', 'document_voided')->count())->toBe(0);

    $this->actingAs($user)
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.rows.2.original_name', 'b-complete.pdf')
            ->where('purchaseOrder.documents.rows.2.page_from', 3)
            ->where('purchaseOrder.documents.rows.3.original_name', 'c.pdf')
            ->where('purchaseOrder.documents.rows.3.page_from', 5));
});

it('can replace the only document of a PO, and refuses a bad file or a missing reason without changing anything', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    $only = po_cons_make($po, 'only.pdf', 1);
    $user = po_cons_user('Procurement');
    $url = po_cons_url($po)."/{$only->id}/replace";

    $this->actingAs($user)->post($url, ['reason' => 'Bad', 'file' => po_cons_pdf('new.pdf')])->assertSessionHasErrors('reason');
    $this->actingAs($user)->post($url, ['reason' => 'Wrong scan'])->assertSessionHasErrors('file');
    $this->actingAs($user)
        ->post($url, ['reason' => 'Wrong scan', 'file' => UploadedFile::fake()->createWithContent('notes.pdf', 'this is plain text')])
        ->assertSessionHasErrors('file');

    expect(PoDocument::count())->toBe(1)
        ->and($only->fresh()->status)->toBe(PoDocumentStatus::Active)
        ->and(Storage::disk('local')->allFiles("po-documents/{$po->id}"))->toHaveCount(1);

    $this->actingAs($user)->post($url, ['reason' => 'Wrong scan', 'file' => po_cons_pdf('new.pdf')])->assertSessionHasNoErrors();

    expect(PoDocument::orderBy('sort_order')->pluck('original_name')->all())->toBe(['only.pdf', 'new.pdf'])
        ->and($po->fresh()->consolidated_pages)->toBe(2);
});

it('does not allow replace on a draft, a verified PO, or without the permissions', function () {
    po_cons_fake();

    foreach (['draft', 'verified'] as $status) {
        $po = PurchaseOrder::factory()->create(['status' => $status]);
        $document = po_cons_make($po, 'a.pdf', 1);

        $this->actingAs(po_cons_user('Procurement'))
            ->post(po_cons_url($po)."/{$document->id}/replace", ['reason' => 'Wrong scan', 'file' => po_cons_pdf('new.pdf')])
            ->assertForbidden();
    }

    $po = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    $document = po_cons_make($po, 'a.pdf', 1);

    foreach (['MMS Staff', 'Finance', 'Admin'] as $role) {
        $this->actingAs(po_cons_user($role))
            ->post(po_cons_url($po)."/{$document->id}/replace", ['reason' => 'Wrong scan', 'file' => po_cons_pdf('new.pdf')])
            ->assertForbidden();
    }

    expect(PoDocument::count())->toBe(3);
});

/* ---------------------------------------------------------------- the viewer route */

it('streams the consolidated PDF inline, or as a download, to everyone who may see the PO', function (string $role) {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'uploaded', 'po_number' => '26-11-0042']);
    po_cons_make($po, 'a.pdf', 1);
    $this->actingAs(po_cons_user('Procurement'))->post(po_cons_url($po).'/rebuild');

    $inline = $this->actingAs(po_cons_user($role))->get(po_cons_url($po).'/consolidated');
    $inline->assertOk();

    expect($inline->headers->get('content-type'))->toContain('application/pdf')
        ->and($inline->headers->get('content-disposition'))->toContain('inline')
        ->and($inline->headers->get('x-content-type-options'))->toBe('nosniff');

    $download = $this->actingAs(po_cons_user($role))->get(po_cons_url($po).'/consolidated?download=1');
    $download->assertOk();

    expect($download->headers->get('content-disposition'))->toContain('attachment')
        ->and($download->headers->get('content-disposition'))->toContain('PO-26-11-0042.pdf');
})->with(['Procurement', 'MMS Staff', 'MMS Head', 'Finance', 'Admin']);

it('keeps a draft PO\'s combined PDF from people who do not handle the files, and closes the route to everyone else', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    po_cons_make($po, 'a.pdf', 1);
    $this->actingAs(po_cons_user('Procurement'))->post(po_cons_url($po).'/rebuild');

    // The PO Reviewer (an MMS Staff job) and Finance see it only after "Submit to Reviewer".
    $this->actingAs(po_cons_user('MMS Staff'))->get(po_cons_url($po).'/consolidated')->assertForbidden();
    $this->actingAs(po_cons_user('Finance'))->get(po_cons_url($po).'/consolidated')->assertForbidden();
    $this->actingAs(po_cons_user('Procurement'))->get(po_cons_url($po).'/consolidated')->assertOk();
    $this->actingAs(po_cons_user('Admin'))->get(po_cons_url($po).'/consolidated')->assertOk();

    // Not logged in, or no menu access at all.
    auth()->logout();
    $this->get(po_cons_url($po).'/consolidated')->assertRedirect();
    $this->actingAs(User::factory()->create())->get(po_cons_url($po).'/consolidated')->assertForbidden();
});

it('gives 404 when no combined PDF exists yet, or when its file is gone from the disk', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    $user = po_cons_user('Procurement');

    $this->actingAs($user)->get(po_cons_url($po).'/consolidated')->assertNotFound();

    po_cons_make($po, 'a.pdf', 1);
    $this->actingAs($user)->post(po_cons_url($po).'/rebuild');
    Storage::disk('local')->delete($po->fresh()->consolidated_path);

    $this->actingAs($user)->get(po_cons_url($po).'/consolidated')->assertNotFound();
});

it('lets only the people who handle the files retry a rebuild', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'verified']);
    po_cons_make($po, 'a.pdf', 1);

    $this->actingAs(po_cons_user('MMS Staff'))->post(po_cons_url($po).'/rebuild')->assertForbidden();
    $this->actingAs(po_cons_user('Finance'))->post(po_cons_url($po).'/rebuild')->assertForbidden();

    foreach (['Procurement', 'Admin', 'MMS Head'] as $role) {
        $this->actingAs(po_cons_user($role))->post(po_cons_url($po).'/rebuild')->assertSessionHasNoErrors();
    }

    expect($po->fresh()->consolidated_pages)->toBe(2);
});

/* ---------------------------------------------------------------- originals and the new permission */

it('gives documents.view-originals to the Admin and the MMS Head only', function () {
    expect(po_cons_user('Admin')->can('documents.view-originals'))->toBeTrue()
        ->and(po_cons_user('MMS Head')->can('documents.view-originals'))->toBeTrue()
        ->and(po_cons_user('Procurement')->can('documents.view-originals'))->toBeFalse()
        ->and(po_cons_user('MMS Staff')->can('documents.view-originals'))->toBeFalse()
        ->and(po_cons_user('Finance')->can('documents.view-originals'))->toBeFalse();
});

it('no longer opens original files for audit.view alone, but does for documents.view-originals', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'verified']);
    $document = po_cons_make($po, 'a.pdf', 1);
    $url = po_cons_url($po)."/{$document->id}/file";

    // audit.view used to be the stand-in; it is not enough any more.
    $this->actingAs(po_cons_user('MMS Staff')->givePermissionTo('audit.view'))->get($url)->assertForbidden();
    $this->actingAs(po_cons_user('MMS Staff')->givePermissionTo('documents.view-originals'))->get($url)->assertOk();
});

it('tells the detail page who may do what, and hides voided rows from people who only read', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    po_cons_make($po, 'a.pdf', 1);
    $b = po_cons_make($po, 'b.pdf', 2);
    $this->actingAs(po_cons_user('Procurement'))->post(po_cons_url($po)."/{$b->id}/void", ['reason' => 'Wrong file']);

    $this->actingAs(po_cons_user('Procurement'))
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.can_view_consolidated', true)
            ->where('purchaseOrder.documents.can_void', true)
            ->where('purchaseOrder.documents.can_replace', true)
            ->where('purchaseOrder.documents.can_rebuild', true)
            ->where('purchaseOrder.documents.can_view', true)
            ->has('purchaseOrder.documents.rows', 2));

    $this->actingAs(po_cons_user('MMS Staff'))
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.can_view_consolidated', true)
            ->where('purchaseOrder.documents.can_void', false)
            ->where('purchaseOrder.documents.can_replace', false)
            ->where('purchaseOrder.documents.can_rebuild', false)
            ->where('purchaseOrder.documents.can_view', false)
            ->has('purchaseOrder.documents.rows', 1)
            ->where('purchaseOrder.documents.rows.0.original_name', 'a.pdf'));

    $this->actingAs(po_cons_user('MMS Head'))
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.can_view', true)
            ->where('purchaseOrder.documents.can_void', false)
            ->has('purchaseOrder.documents.rows', 2));
});

it('rebuilds on Submit when the combined PDF is missing, so the Reviewer always finds one', function () {
    po_cons_fake();
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    po_cons_make($po, 'a.pdf', 1); // as if the earlier rebuild had failed or never ran

    $this->actingAs(po_cons_user('Procurement'))->post(po_cons_url($po).'/submit')->assertSessionHasNoErrors();

    $po->refresh();

    expect($po->status->value)->toBe('uploaded')
        ->and($po->consolidated_pages)->toBe(2);
});

/* ---------------------------------------------------------------- the real qpdf and the real scans */

function po_cons_real_qpdf(): ?string
{
    $configured = env('MMS_QPDF_PATH');

    if (is_string($configured) && $configured !== '' && is_file($configured)) {
        return $configured;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        return null;
    }

    $found = trim((string) @shell_exec('command -v qpdf 2>/dev/null'));

    return $found !== '' ? $found : null;
}

function po_cons_fixture(string $name): string
{
    return base_path("tests/Fixtures/pdf/{$name}");
}

/**
 * The upload of a real scan. A normal closed temp file, as the browser's upload is: UploadedFile::fake()->createWithContent()
 * keeps a tmpfile() handle open, and on Windows qpdf (another program) cannot read a file that PHP still holds open.
 */
function po_cons_scan(string $fixture, string $name): UploadedFile
{
    $copy = (string) tempnam(sys_get_temp_dir(), 'scan');
    copy(po_cons_fixture($fixture), $copy);

    return new UploadedFile($copy, $name, 'application/pdf', null, true);
}

/**
 * The page sizes (/MediaBox, in points, rounded) of every page of a PDF, sorted. qpdf --qdf writes the
 * file in a readable form first, so the sizes can be read without a PDF library.
 *
 * @return list<string>
 */
function po_cons_page_sizes(string $pdf): array
{
    $readable = (string) tempnam(sys_get_temp_dir(), 'qdf');
    Process::run([Qpdf::path(), '--qdf', '--object-streams=disable', $pdf, $readable]);
    $text = (string) file_get_contents($readable);
    @unlink($readable);

    preg_match_all('/\/MediaBox\s*\[\s*(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s+(-?[\d.]+)\s*\]/', $text, $boxes, PREG_SET_ORDER);

    $sizes = array_map(fn (array $box) => round((float) $box[3] - (float) $box[1]).'x'.round((float) $box[4] - (float) $box[2]), $boxes);
    sort($sizes);

    return $sizes;
}

it('combines the two real office scans (4 + 14 pages) in upload order and keeps every page size', function () {
    config(['mms.pdf.qpdf_path' => po_cons_real_qpdf()]);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $user = po_cons_user('Procurement');

    $this->actingAs($user)->post(po_cons_url($po), ['file' => po_cons_scan('iar-26-2192-medline-4-pages.pdf', 'IAR 26-2192 Med-Line.pdf')])->assertSessionHasNoErrors();

    expect($po->fresh()->consolidated_pages)->toBe(4);

    // The second upload is appended as the next pages.
    $this->actingAs($user)->post(po_cons_url($po), ['file' => po_cons_scan('iar-26-2190-savelink-14-pages.pdf', 'IAR 26-2190 Savelink.pdf')])->assertSessionHasNoErrors();

    $po->refresh();
    $combined = Storage::disk('local')->path($po->consolidated_path);

    expect($po->consolidated_pages)->toBe(18)
        ->and($po->consolidated_stale)->toBeFalse()
        ->and(Qpdf::pageCount($combined))->toBe(18)
        ->and(Qpdf::check($combined))->toBeIn([0, 3])
        ->and(po_cons_files($po))->toBe([$po->consolidated_path]);

    // Nothing was resized or rotated: the 18 pages have exactly the sizes the two scans have (12 different ones).
    $expected = [...po_cons_page_sizes(po_cons_fixture('iar-26-2192-medline-4-pages.pdf')), ...po_cons_page_sizes(po_cons_fixture('iar-26-2190-savelink-14-pages.pdf'))];
    sort($expected);

    expect(po_cons_page_sizes($combined))->toBe($expected)
        ->and(count(array_unique($expected)))->toBeGreaterThan(5);

    $this->actingAs($user)
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.rows.0.page_from', 1)
            ->where('purchaseOrder.documents.rows.0.page_to', 4)
            ->where('purchaseOrder.documents.rows.1.page_from', 5)
            ->where('purchaseOrder.documents.rows.1.page_to', 18));

    // The viewer route serves a real PDF.
    $response = $this->actingAs($user)->get(po_cons_url($po).'/consolidated');
    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
})->skip(fn () => po_cons_real_qpdf() === null, 'qpdf was not found: set MMS_QPDF_PATH in .env');

it('removes exactly the pages of a voided real scan (18 -> 14), and replaces one in the same place', function () {
    config(['mms.pdf.qpdf_path' => po_cons_real_qpdf()]);
    $po = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    $user = po_cons_user('Procurement');

    $this->actingAs($user)->post(po_cons_url($po), ['file' => po_cons_scan('iar-26-2192-medline-4-pages.pdf', 'IAR 26-2192 Med-Line.pdf')])->assertSessionHasNoErrors();
    $this->actingAs($user)->post(po_cons_url($po), ['file' => po_cons_scan('iar-26-2190-savelink-14-pages.pdf', 'IAR 26-2190 Savelink.pdf')])->assertSessionHasNoErrors();

    expect($po->fresh()->consolidated_pages)->toBe(18);

    $medline = PoDocument::where('original_name', 'IAR 26-2192 Med-Line.pdf')->sole();

    $this->actingAs($user)->post(po_cons_url($po)."/{$medline->id}/void", ['reason' => 'Wrong supplier'])->assertSessionHasNoErrors();

    $po->refresh();
    $combined = Storage::disk('local')->path($po->consolidated_path);

    expect($po->consolidated_pages)->toBe(14)
        ->and(Qpdf::pageCount($combined))->toBe(14)
        ->and(po_cons_page_sizes($combined))->toBe(po_cons_page_sizes(po_cons_fixture('iar-26-2190-savelink-14-pages.pdf')))
        ->and(po_cons_files($po))->toBe([$po->consolidated_path]);

    // Replace the 14-page scan with the 4-page one: the new file takes its place, 4 pages remain.
    $savelink = PoDocument::where('original_name', 'IAR 26-2190 Savelink.pdf')->sole();

    $this->actingAs($user)
        ->post(po_cons_url($po)."/{$savelink->id}/replace", ['reason' => 'Rescanned', 'file' => po_cons_scan('iar-26-2192-medline-4-pages.pdf', 'IAR 26-2192 rescan.pdf')])
        ->assertSessionHasNoErrors();

    expect($po->fresh()->consolidated_pages)->toBe(4)
        ->and(Qpdf::pageCount(Storage::disk('local')->path($po->fresh()->consolidated_path)))->toBe(4);
})->skip(fn () => po_cons_real_qpdf() === null, 'qpdf was not found: set MMS_QPDF_PATH in .env');