<?php

use App\Enums\PoDocumentType;
use App\Enums\PurchaseOrderStatus;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Steps 4.2 and 4.2b (4.2c changed the request shape of the upload: groups[i][type] and groups[i][files][];
 * the new behaviour is tested in PoDocumentTypesTest). qpdf is faked (Process::fake) so the tests do not need the program;
 * the exit codes 0, 3 and 2 are what "qpdf --check" really returns
 * (docs/PDF-MERGE.md section 3.3). Images need the "gd" PHP extension to build.
 *
 * Step 4.3 (consolidated PDF, void, replace) is tested in PoConsolidatedPdfTest.
 *
 * Since 4.2b an upload never changes the PO status ("Submit to Reviewer" does), uploads are
 * allowed in draft, uploaded and escalated only, remove is draft only, moving is draft and
 * escalated, and one file may be opened by uploaders (open statuses) and by Admin / MMS Head.
 */

/** Audit write that always fails: proves a failed upload rolls everything back. */
class PoDocumentThrowingActivity extends Activity
{
    public function save(array $options = []): bool
    {
        throw new RuntimeException('audit down');
    }
}

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
    config(['mms.pdf.qpdf_path' => 'qpdf-for-tests']);
});

function po_doc_user(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

function po_doc_qpdf(int $exitCode): void
{
    Process::fake(['*' => Process::result(exitCode: $exitCode)]);
}

function po_doc_pdf(string $name = 'bundle.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

function po_doc_jpeg(?int $orientation = null): string
{
    $image = imagecreatetruecolor(40, 20);
    ob_start();
    imagejpeg($image);
    $bytes = (string) ob_get_clean();

    if ($orientation === null) {
        return $bytes;
    }

    // An EXIF block (big-endian TIFF) with only the orientation tag, inserted after the JPEG start marker.
    $tiff = "MM\x00\x2A\x00\x00\x00\x08"
        ."\x00\x01"
        ."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00"
        ."\x00\x00\x00\x00";
    $app1 = "\xFF\xE1".pack('n', 2 + 6 + strlen($tiff))."Exif\x00\x00".$tiff;

    return substr($bytes, 0, 2).$app1.substr($bytes, 2);
}

function po_doc_png(bool $interlaced = false): string
{
    $image = imagecreatetruecolor(30, 30);

    if ($interlaced) {
        imageinterlace($image, true);
    }

    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/** The request body of the upload form (step 4.2c): one group of type po_bundle with these files. */
function po_doc_groups(UploadedFile ...$files): array
{
    return ['groups' => [['type' => 'po_bundle', 'files' => array_values($files)]]];
}

function po_doc_url(PurchaseOrder $po): string
{
    return "/purchase-orders/{$po->id}/documents";
}

/** A document already on the PO (files on the fake disk, no upload request). $image adds an "uploaded JPG" original. */
function po_doc_make(PurchaseOrder $po, string $name, int $order, bool $image = false): PoDocument
{
    $disk = Storage::disk('local');
    $stored = "po-documents/{$po->id}/".Str::uuid().'.pdf';
    $original = null;

    $disk->put($stored, "%PDF-1.4\n%%EOF\n");

    if ($image) {
        $original = substr($stored, 0, -4).'.jpg';
        $disk->put($original, 'jpeg-bytes');
    }

    return PoDocument::create([
        'purchase_order_id' => $po->id,
        'type' => PoDocumentType::PoBundle,
        'original_name' => $name,
        'stored_path' => $stored,
        'original_path' => $original,
        'mime' => $image ? 'image/jpeg' : 'application/pdf',
        'size_bytes' => 1000,
        'uploaded_by' => User::factory()->create()->id,
        'sort_order' => $order,
    ]);
}

/** @return list<string> original names in page order */
function po_doc_names(): array
{
    return PoDocument::orderBy('sort_order')->pluck('original_name')->all();
}

/* ---------------------------------------------------------------- upload (4.2, changed by 4.2b) */

it('stores the PO Bundle privately and creates the record, and the PO stays a draft', function () {
    po_doc_qpdf(0);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $user = po_doc_user('Procurement');

    $this->actingAs($user)
        ->post(po_doc_url($po), po_doc_groups(po_doc_pdf('Supplier Bundle.pdf')))
        ->assertRedirect(route('purchase-orders.show', $po))
        ->assertSessionHasNoErrors();

    $document = PoDocument::sole();

    expect($document->purchase_order_id)->toEqual($po->id)
        ->and($document->uploaded_by)->toEqual($user->id)
        ->and($document->type->value)->toBe('po_bundle')
        ->and($document->original_name)->toBe('Supplier Bundle.pdf')
        ->and($document->mime)->toBe('application/pdf')
        ->and($document->sort_order)->toBe(1)
        ->and($document->status->value)->toBe('active')
        ->and($document->original_path)->toBeNull()
        ->and($document->stored_path)->toStartWith("po-documents/{$po->id}/")
        ->and($document->stored_path)->toEndWith('.pdf')
        ->and($document->stored_path)->not->toContain('Supplier');

    // Private disk only, never the public one.
    expect(Storage::disk('local')->exists($document->stored_path))->toBeTrue();
    expect(Storage::disk('public')->exists($document->stored_path))->toBeFalse();

    // 4.2b: uploading does not submit the PO.
    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Draft);

    // One audit entry for the upload, none for a status change.
    expect(Activity::where('event', 'document_uploaded')->where('log_name', 'po')->count())->toBe(1)
        ->and(Activity::where('event', 'status_changed')->count())->toBe(0);

    // The real qpdf check ran on the uploaded file.
    Process::assertRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === '--check');
});

it('adds a file to an uploaded or escalated PO without a status change', function (string $status) {
    po_doc_qpdf(0);
    $po = PurchaseOrder::factory()->create(['status' => $status]);

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(po_doc_pdf()))
        ->assertSessionHasNoErrors();

    expect(PoDocument::count())->toBe(1)
        ->and($po->fresh()->status->value)->toBe($status)
        ->and(Activity::where('event', 'status_changed')->count())->toBe(0)
        ->and(Activity::where('event', 'document_uploaded')->count())->toBe(1);
})->with(['uploaded', 'escalated']);

it('refuses a new file once the PO is verified or later (403), and stores nothing', function (string $status) {
    po_doc_qpdf(0);
    $po = PurchaseOrder::factory()->create(['status' => $status]);

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(po_doc_pdf()))
        ->assertForbidden();

    expect(PoDocument::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and($po->fresh()->status->value)->toBe($status);
})->with(['verified', 'for_termination_approval', 'for_termination', 'cancelled', 'terminated']);

it('numbers uploads in the order they arrive', function () {
    po_doc_qpdf(0);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $user = po_doc_user('Procurement');

    $this->actingAs($user)->post(po_doc_url($po), po_doc_groups(po_doc_pdf('first.pdf')));
    $this->actingAs($user)->post(po_doc_url($po), po_doc_groups(po_doc_pdf('second.pdf')));

    expect(po_doc_names())->toBe(['first.pdf', 'second.pdf'])
        ->and(PoDocument::orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2]);
});

it('gives 403 without po.upload-files and changes nothing', function (string $role) {
    po_doc_qpdf(0);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_doc_user($role))
        ->post(po_doc_url($po), po_doc_groups(po_doc_pdf()))
        ->assertForbidden();

    expect(PoDocument::count())->toBe(0)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['Admin', 'MMS Staff', 'MMS Head', 'Finance']);

it('rejects a wrong file type', function () {
    po_doc_qpdf(0);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $user = po_doc_user('Procurement');

    // A Word file, and a text file that only has a .pdf name.
    $this->actingAs($user)->post(po_doc_url($po), po_doc_groups(UploadedFile::fake()->create('letter.docx', 20)))
        ->assertSessionHasErrors('groups.0.files.0');
    $this->actingAs($user)->post(po_doc_url($po), po_doc_groups(UploadedFile::fake()->createWithContent('fake.pdf', 'this is only text')))
        ->assertSessionHasErrors('groups.0.files.0');

    expect(PoDocument::count())->toBe(0)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Draft);
});

it('rejects a file over the size limit', function () {
    po_doc_qpdf(0);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $tooBig = (int) config('mms.uploads.max_size_kb') + 1;

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(UploadedFile::fake()->create('big.pdf', $tooBig, 'application/pdf')))
        ->assertSessionHasErrors(['groups.0.files.0' => 'The file is too large. The limit is 10 MB per file.']);

    expect(PoDocument::count())->toBe(0);
});

it('rejects a damaged PDF (qpdf exit 2) with the plain message', function () {
    po_doc_qpdf(2);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(po_doc_pdf()))
        ->assertSessionHasErrors(['groups.0.files.0' => 'The file is damaged or password-protected. Rescan it or remove the password.']);

    expect(PoDocument::count())->toBe(0)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('accepts a PDF with warnings (qpdf exit 3)', function () {
    po_doc_qpdf(3);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(po_doc_pdf()))
        ->assertSessionHasNoErrors();

    expect(PoDocument::count())->toBe(1)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Draft);
});

it('rejects a PDF when qpdf cannot be used', function () {
    po_doc_qpdf(127); // for example "program not found"
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(po_doc_pdf()))
        ->assertSessionHasErrors('groups.0.files.0');

    expect(PoDocument::count())->toBe(0);
});

it('turns a JPEG into a PDF page at upload without re-encoding it and keeps the original', function () {
    Process::fake();
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $jpeg = po_doc_jpeg();

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(UploadedFile::fake()->createWithContent('photo.jpg', $jpeg)))
        ->assertSessionHasNoErrors();

    $document = PoDocument::sole();
    $pdf = Storage::disk('local')->get($document->stored_path);

    expect($document->mime)->toBe('image/jpeg')
        ->and($document->stored_path)->toEndWith('.pdf')
        ->and($document->original_path)->toEndWith('.jpg')
        ->and($pdf)->toStartWith('%PDF-')
        ->and(str_contains($pdf, $jpeg))->toBeTrue() // the JPEG bytes are inside the PDF unchanged
        ->and(str_contains($pdf, '/Rotate'))->toBeFalse()
        ->and(Storage::disk('local')->get($document->original_path))->toBe($jpeg)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Draft);

    // Since step 4.3 an upload also rebuilds the combined PDF, so qpdf does run; what must NOT run is the check of a PDF.
    Process::assertNotRan(fn (PendingProcess $process) => ($process->command[1] ?? null) === '--check');
});

it('applies the phone rotation (EXIF) through the page /Rotate', function () {
    Process::fake();
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $jpeg = po_doc_jpeg(6); // 6 = rotate 90 degrees clockwise

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(UploadedFile::fake()->createWithContent('phone.jpg', $jpeg)))
        ->assertSessionHasNoErrors();

    $pdf = Storage::disk('local')->get(PoDocument::sole()->stored_path);

    expect(str_contains($pdf, '/Rotate 90'))->toBeTrue()
        ->and(str_contains($pdf, $jpeg))->toBeTrue();
});

it('turns a PNG into a PDF page at upload', function () {
    Process::fake();
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(UploadedFile::fake()->createWithContent('scan.png', po_doc_png())))
        ->assertSessionHasNoErrors();

    $document = PoDocument::sole();

    expect($document->mime)->toBe('image/png')
        ->and($document->original_path)->toEndWith('.png')
        ->and(Storage::disk('local')->get($document->stored_path))->toStartWith('%PDF-');
});

it('rejects an interlaced PNG', function () {
    Process::fake();
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(UploadedFile::fake()->createWithContent('scan.png', po_doc_png(interlaced: true))))
        ->assertSessionHasErrors('groups.0.files.0');

    expect(PoDocument::count())->toBe(0);
});

it('rejects an HEIC photo, even when it is renamed to .jpg', function (string $name) {
    Process::fake();
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $heic = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic".str_repeat("\x00", 64);

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(UploadedFile::fake()->createWithContent($name, $heic)))
        ->assertSessionHasErrors('groups.0.files.0');

    expect(PoDocument::count())->toBe(0);
})->with(['photo.heic', 'photo.jpg']);

it('leaves the PO as draft and removes the files when the upload fails midway', function () {
    po_doc_qpdf(0);
    config(['activitylog.activity_model' => PoDocumentThrowingActivity::class]);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po), po_doc_groups(po_doc_pdf()))
        ->assertServerError();

    expect(PoDocument::count())->toBe(0)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

/* ---------------------------------------------------------------- Submit to Reviewer (4.2b) */

it('submits a draft PO with documents: draft -> uploaded, logged once', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    po_doc_make($po, 'bundle.pdf', 1);

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po).'/submit')
        ->assertRedirect(route('purchase-orders.show', $po))
        ->assertSessionHasNoErrors();

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Uploaded)
        ->and(Activity::where('event', 'status_changed')->where('log_name', 'po')->count())->toBe(1);
});

it('refuses to submit a draft PO that has no active document', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $user = po_doc_user('Procurement');

    $this->actingAs($user)->post(po_doc_url($po).'/submit')->assertSessionHasErrors('form');

    // Only a voided document: still nothing to send.
    po_doc_make($po, 'old.pdf', 1);
    PoDocument::query()->update(['status' => 'void']);

    $this->actingAs($user)->post(po_doc_url($po).'/submit')->assertSessionHasErrors('form');

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and(Activity::where('event', 'status_changed')->count())->toBe(0);
});

it('gives 403 for Submit without po.upload-files, or when the PO is not a draft', function () {
    $draft = PurchaseOrder::factory()->create(['status' => 'draft']);
    po_doc_make($draft, 'a.pdf', 1);
    $uploaded = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    po_doc_make($uploaded, 'b.pdf', 1);

    $this->actingAs(po_doc_user('MMS Staff'))->post(po_doc_url($draft).'/submit')->assertForbidden();
    $this->actingAs(po_doc_user('Procurement'))->post(po_doc_url($uploaded).'/submit')->assertForbidden();

    expect($draft->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($uploaded->fresh()->status)->toBe(PurchaseOrderStatus::Uploaded);
});

/* ---------------------------------------------------------------- remove (4.2b) */

it('deletes a file on a draft PO (row, PDF and original) and closes the gap in the numbers', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    po_doc_make($po, 'one.pdf', 1);
    $middle = po_doc_make($po, 'two.jpg', 2, image: true);
    po_doc_make($po, 'three.pdf', 3);
    $files = [$middle->stored_path, $middle->original_path];

    $this->actingAs(po_doc_user('Procurement'))
        ->delete(po_doc_url($po)."/{$middle->id}")
        ->assertRedirect(route('purchase-orders.show', $po))
        ->assertSessionHasNoErrors();

    expect(po_doc_names())->toBe(['one.pdf', 'three.pdf'])
        ->and(PoDocument::orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2])
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and(Activity::where('event', 'document_removed')->where('log_name', 'po')->count())->toBe(1);

    foreach ($files as $path) {
        expect(Storage::disk('local')->exists($path))->toBeFalse();
    }
});

it('does not delete a file once the PO is uploaded (403), and keeps the files', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    $document = po_doc_make($po, 'bundle.pdf', 1);

    $this->actingAs(po_doc_user('Procurement'))
        ->delete(po_doc_url($po)."/{$document->id}")
        ->assertForbidden();

    expect(PoDocument::count())->toBe(1);
    expect(Storage::disk('local')->exists($document->stored_path))->toBeTrue();
});

it('gives 403 for remove without po.upload-files, and 404 for a document of another PO', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $other = PurchaseOrder::factory()->create(['status' => 'draft']);
    $document = po_doc_make($po, 'bundle.pdf', 1);

    $this->actingAs(po_doc_user('Admin'))->delete(po_doc_url($po)."/{$document->id}")->assertForbidden();
    $this->actingAs(po_doc_user('Procurement'))->delete(po_doc_url($other)."/{$document->id}")->assertNotFound();

    expect(PoDocument::count())->toBe(1);
});

/* ---------------------------------------------------------------- move up / down (4.2b) */

it('moves a file up and down by swapping the page numbers', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    po_doc_make($po, 'first.pdf', 1);
    $second = po_doc_make($po, 'second.pdf', 2);
    po_doc_make($po, 'third.pdf', 3);
    $user = po_doc_user('Procurement');

    $this->actingAs($user)
        ->post(po_doc_url($po)."/{$second->id}/move", ['direction' => 'up'])
        ->assertSessionHasNoErrors();

    expect(po_doc_names())->toBe(['second.pdf', 'first.pdf', 'third.pdf'])
        ->and(PoDocument::orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2, 3]);

    $this->actingAs($user)
        ->post(po_doc_url($po)."/{$second->id}/move", ['direction' => 'down'])
        ->assertSessionHasNoErrors();

    expect(po_doc_names())->toBe(['first.pdf', 'second.pdf', 'third.pdf'])
        ->and(Activity::where('event', 'documents_reordered')->where('log_name', 'po')->count())->toBe(2);
});

it('skips a voided document when moving, and leaves it where it is', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'escalated']);
    po_doc_make($po, 'first.pdf', 1);
    $voided = po_doc_make($po, 'voided.pdf', 2);
    $third = po_doc_make($po, 'third.pdf', 3);
    PoDocument::whereKey($voided->id)->update(['status' => 'void']);

    $this->actingAs(po_doc_user('Procurement'))
        ->post(po_doc_url($po)."/{$third->id}/move", ['direction' => 'up'])
        ->assertSessionHasNoErrors();

    expect(PoDocument::orderBy('sort_order')->pluck('original_name', 'sort_order')->all())
        ->toBe([1 => 'third.pdf', 2 => 'voided.pdf', 3 => 'first.pdf']);
});

it('refuses to move the first file up or the last file down', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $first = po_doc_make($po, 'first.pdf', 1);
    $last = po_doc_make($po, 'last.pdf', 2);
    $user = po_doc_user('Procurement');

    $this->actingAs($user)->post(po_doc_url($po)."/{$first->id}/move", ['direction' => 'up'])->assertSessionHasErrors('form');
    $this->actingAs($user)->post(po_doc_url($po)."/{$last->id}/move", ['direction' => 'down'])->assertSessionHasErrors('form');
    $this->actingAs($user)->post(po_doc_url($po)."/{$last->id}/move", ['direction' => 'sideways'])->assertSessionHasErrors('direction');

    expect(po_doc_names())->toBe(['first.pdf', 'last.pdf']);
});

it('gives 403 for moving when the PO is uploaded or verified, or without po.upload-files', function () {
    $user = po_doc_user('Procurement');

    foreach (['uploaded', 'verified'] as $status) {
        $po = PurchaseOrder::factory()->create(['status' => $status]);
        $a = po_doc_make($po, "a-{$status}.pdf", 1);
        po_doc_make($po, "b-{$status}.pdf", 2);

        $this->actingAs($user)->post(po_doc_url($po)."/{$a->id}/move", ['direction' => 'down'])->assertForbidden();
    }

    $draft = PurchaseOrder::factory()->create(['status' => 'draft']);
    $a = po_doc_make($draft, 'a-draft.pdf', 1);
    po_doc_make($draft, 'b-draft.pdf', 2);

    $this->actingAs(po_doc_user('MMS Head'))->post(po_doc_url($draft)."/{$a->id}/move", ['direction' => 'down'])->assertForbidden();

    expect(PoDocument::orderBy('id')->pluck('sort_order')->all())->toBe([1, 2, 1, 2, 1, 2]);
});

/* ---------------------------------------------------------------- View one file (4.2b) */

it('streams one file inline as a PDF to an uploader while the PO is open', function (string $status) {
    $po = PurchaseOrder::factory()->create(['status' => $status]);
    $document = po_doc_make($po, 'Supplier Bundle.pdf', 1);

    $response = $this->actingAs(po_doc_user('Procurement'))->get(po_doc_url($po)."/{$document->id}/file");

    $response->assertOk();

    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('inline')
        ->and($response->headers->get('content-disposition'))->toContain('Supplier Bundle.pdf');
})->with(['draft', 'uploaded', 'escalated']);

it('lets the Admin and the MMS Head open a file at any status', function (string $role) {
    $po = PurchaseOrder::factory()->create(['status' => 'verified']);
    $document = po_doc_make($po, 'bundle.pdf', 1);

    $this->actingAs(po_doc_user($role))->get(po_doc_url($po)."/{$document->id}/file")->assertOk();
})->with(['Admin', 'MMS Head']);

it('keeps single files closed to everyone else', function () {
    $verified = PurchaseOrder::factory()->create(['status' => 'verified']);
    $closedFile = po_doc_make($verified, 'a.pdf', 1);
    $draft = PurchaseOrder::factory()->create(['status' => 'draft']);
    $openFile = po_doc_make($draft, 'b.pdf', 1);

    // An uploader loses the View once the PO is verified.
    $this->actingAs(po_doc_user('Procurement'))->get(po_doc_url($verified)."/{$closedFile->id}/file")->assertForbidden();

    // People without the permission never get it (PO Reviewer sees the consolidated PDF in step 4.3).
    foreach (['MMS Staff', 'Finance'] as $role) {
        $this->actingAs(po_doc_user($role))->get(po_doc_url($draft)."/{$openFile->id}/file")->assertForbidden();
    }
});

it('gives 404 for a file of another PO and 404 when the file is gone from the disk', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $other = PurchaseOrder::factory()->create(['status' => 'draft']);
    $document = po_doc_make($po, 'bundle.pdf', 1);
    $user = po_doc_user('Procurement');

    $this->actingAs($user)->get(po_doc_url($other)."/{$document->id}/file")->assertNotFound();

    Storage::disk('local')->delete($document->stored_path);

    $this->actingAs($user)->get(po_doc_url($po)."/{$document->id}/file")->assertNotFound();
});

/* ---------------------------------------------------------------- the PO detail page */

it('lists the documents on the PO detail page and shows the buttons only to the people who may use them', function () {
    po_doc_qpdf(0);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $procurement = po_doc_user('Procurement');

    $this->actingAs($procurement)->post(po_doc_url($po), po_doc_groups(po_doc_pdf()));

    $this->actingAs($procurement)
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.can_upload', true)
            ->where('purchaseOrder.documents.can_submit', true)
            ->where('purchaseOrder.documents.can_remove', true)
            ->where('purchaseOrder.documents.can_reorder', true)
            ->where('purchaseOrder.documents.can_view', true)
            ->where('purchaseOrder.documents.locked_reason', null)
            ->has('purchaseOrder.documents.rows', 1));

    $this->actingAs(po_doc_user('MMS Staff'))
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.can_upload', false)
            ->where('purchaseOrder.documents.can_submit', false)
            ->where('purchaseOrder.documents.can_remove', false)
            ->where('purchaseOrder.documents.can_reorder', false)
            ->where('purchaseOrder.documents.can_view', false)
            ->where('purchaseOrder.documents.locked_reason', null)
            ->has('purchaseOrder.documents.rows', 1));
});

it('shows no Submit button on a draft PO without documents', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_doc_user('Procurement'))
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.can_upload', true)
            ->where('purchaseOrder.documents.can_submit', false));
});

it('tells an uploader why a verified PO takes no more documents', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'verified']);
    po_doc_make($po, 'bundle.pdf', 1);

    $this->actingAs(po_doc_user('Procurement'))
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.can_upload', false)
            ->where('purchaseOrder.documents.can_view', false)
            ->where('purchaseOrder.documents.locked_reason', fn ($reason) => is_string($reason) && $reason !== ''));
});