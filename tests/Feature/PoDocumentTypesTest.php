<?php

use App\Enums\PoDocumentStatus;
use App\Enums\PoDocumentType;
use App\Enums\PurchaseOrderStatus;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\PoDocumentChecklist;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
 * Step 4.2c: document types, several files per request, the sample checklist.
 *
 * The request is groups[i][type] + groups[i][files][]. qpdf is faked (Process::fake): "--check" exits 0,
 * "--show-npages" answers 2 for a stand-in file (and the sum for a merged one), and "--pages ... -- out"
 * writes a small stand-in output. The fake writes every command down itself (this Laravel version has no
 * Process::recorded()), so a test can count the merges.
 *
 * The type filter itself is React state on the rows the page already receives: here the tests check that
 * those rows carry the type (type_key) and that old rows keep working. The upload form and the filter are
 * checked in the browser (see PROGRESS.md, "Step 4.2c checks").
 */

/** Audit write that always fails: proves a failed multi-file upload rolls everything back. */
class PoTypesThrowingActivity extends Activity
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
    po_types_fake();
});

function po_types_user(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

/**
 * Every command the fake qpdf was asked to run, in order.
 *
 * @return list<list<string>>
 */
function po_types_commands(?array $add = null, bool $reset = false): array
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

function po_types_fake(): void
{
    po_types_commands(reset: true);

    Process::fake(['*' => function (PendingProcess $process) {
        $command = (array) $process->command;
        po_types_commands($command);

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

/** How many times the fake qpdf was asked to MERGE (the rebuild of the combined PDF). */
function po_types_merges(): int
{
    return count(array_filter(po_types_commands(), fn (array $command) => in_array('--pages', $command, true)));
}

function po_types_pdf(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

function po_types_url(PurchaseOrder $po): string
{
    return "/purchase-orders/{$po->id}/documents";
}

/**
 * The request body: ['po_bundle' => [file, file], 'supporting_document' => [file]] becomes two groups.
 *
 * @param  array<string, list<UploadedFile>>  $map
 * @return array{groups: list<array{type: string, files: list<UploadedFile>}>}
 */
function po_types_body(array $map): array
{
    $groups = [];

    foreach ($map as $type => $files) {
        $groups[] = ['type' => $type, 'files' => $files];
    }

    return ['groups' => $groups];
}

/** A document already on the PO (a file on the fake disk, no upload request). */
function po_types_make(PurchaseOrder $po, string $name, int $order, PoDocumentType $type = PoDocumentType::PoBundle): PoDocument
{
    $stored = "po-documents/{$po->id}/".Str::uuid().'.pdf';
    Storage::disk('local')->put($stored, "%PDF-1.4\n%%EOF\n");

    return PoDocument::create([
        'purchase_order_id' => $po->id,
        'type' => $type,
        'original_name' => $name,
        'stored_path' => $stored,
        'original_path' => null,
        'mime' => 'application/pdf',
        'size_bytes' => 1000,
        'uploaded_by' => User::factory()->create()->id,
        'sort_order' => $order,
    ]);
}

/** @return list<string> */
function po_types_names(): array
{
    return PoDocument::orderBy('sort_order')->pluck('original_name')->all();
}

/** @return list<string> */
function po_types_type_keys(): array
{
    return PoDocument::orderBy('sort_order')->get()->map(fn (PoDocument $document) => $document->type->value)->all();
}

/* ---------------------------------------------------------------- uploading */

it('saves several files under one type, in the order chosen', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), po_types_body(['po_bundle' => [po_types_pdf('a.pdf'), po_types_pdf('b.pdf'), po_types_pdf('c.pdf')]]))
        ->assertRedirect(route('purchase-orders.show', $po))
        ->assertSessionHasNoErrors();

    expect(po_types_names())->toBe(['a.pdf', 'b.pdf', 'c.pdf'])
        ->and(PoDocument::orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2, 3])
        ->and(po_types_type_keys())->toBe(['po_bundle', 'po_bundle', 'po_bundle'])
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Draft);
});

it('saves two types in one request, groups top to bottom', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), po_types_body([
            'po_bundle' => [po_types_pdf('a.pdf'), po_types_pdf('b.pdf')],
            'supporting_document' => [po_types_pdf('c.pdf')],
        ]))
        ->assertSessionHasNoErrors();

    expect(po_types_names())->toBe(['a.pdf', 'b.pdf', 'c.pdf'])
        ->and(po_types_type_keys())->toBe(['po_bundle', 'po_bundle', 'supporting_document']);
});

it('refuses the same type in two groups of one request and stores nothing', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), ['groups' => [
            ['type' => 'po_bundle', 'files' => [po_types_pdf('a.pdf')]],
            ['type' => 'po_bundle', 'files' => [po_types_pdf('b.pdf')]],
        ]])
        ->assertSessionHasErrors('groups.1.type');

    expect(PoDocument::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses an unknown type', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), po_types_body(['banana' => [po_types_pdf('a.pdf')]]))
        ->assertSessionHasErrors(['groups.0.type' => 'That document type is not known. Choose one from the list.']);

    expect(PoDocument::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a type that is not of stage po (delivery and exception documents)', function (string $type) {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), po_types_body([$type => [po_types_pdf('a.pdf')]]))
        ->assertSessionHasErrors('groups.0.type');

    expect(PoDocument::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['iar', 'dr', 'or', 'notice_of_delivery', 'notice_of_termination', 'pull_out_form']);

it('refuses the old one-file request shape', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), ['file' => po_types_pdf('a.pdf')])
        ->assertSessionHasErrors('groups');

    expect(PoDocument::count())->toBe(0);
});

it('adds more files to a type that is already stored, after the earlier files', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    $user = po_types_user('Procurement');

    $this->actingAs($user)->post(po_types_url($po), po_types_body([
        'po_bundle' => [po_types_pdf('a.pdf')],
        'supporting_document' => [po_types_pdf('b.pdf')],
    ]))->assertSessionHasNoErrors();

    // The same request again, one group: "Add files" on the PO Bundle.
    $this->actingAs($user)->post(po_types_url($po), po_types_body(['po_bundle' => [po_types_pdf('c.pdf'), po_types_pdf('d.pdf')]]))
        ->assertSessionHasNoErrors();

    expect(po_types_names())->toBe(['a.pdf', 'b.pdf', 'c.pdf', 'd.pdf'])
        ->and(po_types_type_keys())->toBe(['po_bundle', 'supporting_document', 'po_bundle', 'po_bundle'])
        ->and(PoDocument::orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2, 3, 4]);
});

it('stores nothing when one file of the request is bad (all or nothing)', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), po_types_body(['po_bundle' => [
            po_types_pdf('good.pdf'),
            UploadedFile::fake()->createWithContent('bad.pdf', 'this is only text'),
        ]]))
        ->assertSessionHasErrors('groups.0.files.1');

    expect(PoDocument::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(po_types_merges())->toBe(0);
});

it('rolls back every row and file when the request fails midway', function () {
    config(['activitylog.activity_model' => PoTypesThrowingActivity::class]);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), po_types_body(['po_bundle' => [po_types_pdf('a.pdf'), po_types_pdf('b.pdf')]]))
        ->assertServerError();

    expect(PoDocument::count())->toBe(0)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('rebuilds the combined PDF once per request, not once per file', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), po_types_body([
            'po_bundle' => [po_types_pdf('a.pdf'), po_types_pdf('b.pdf')],
            'other' => [po_types_pdf('c.pdf')],
        ]))
        ->assertSessionHasNoErrors();

    expect(po_types_merges())->toBe(1)
        ->and($po->fresh()->consolidated_pages)->toBe(6);
});

it('writes one audit entry per file, with the type in the text', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), po_types_body([
            'po_bundle' => [po_types_pdf('a.pdf')],
            'supporting_document' => [po_types_pdf('b.pdf')],
        ]))
        ->assertSessionHasNoErrors();

    $descriptions = Activity::where('event', 'document_uploaded')->where('log_name', 'po')->orderBy('id')->pluck('description')->all();

    expect($descriptions)->toBe([
        "Uploaded PO Bundle (a.pdf) to PO {$po->po_number}",
        "Uploaded Supporting Document (b.pdf) to PO {$po->po_number}",
    ])->and(Activity::where('event', 'status_changed')->count())->toBe(0);
});

it('refuses too many files in one request', function () {
    config(['mms.uploads.max_files_per_request' => 2]);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), po_types_body(['po_bundle' => [po_types_pdf('a.pdf'), po_types_pdf('b.pdf'), po_types_pdf('c.pdf')]]))
        ->assertSessionHasErrors('groups');

    expect(PoDocument::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

it('refuses a request whose files together are too big', function () {
    config(['mms.uploads.max_request_kb' => 100]);
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), po_types_body(['po_bundle' => [
            UploadedFile::fake()->create('a.pdf', 80, 'application/pdf'),
            UploadedFile::fake()->create('b.pdf', 80, 'application/pdf'),
        ]]))
        ->assertSessionHasErrors('groups');

    expect(PoDocument::count())->toBe(0);
});

it('gives 403 without po.upload-files and changes nothing', function (string $role) {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $this->actingAs(po_types_user($role))
        ->post(po_types_url($po), po_types_body(['po_bundle' => [po_types_pdf('a.pdf'), po_types_pdf('b.pdf')]]))
        ->assertForbidden();

    expect(PoDocument::count())->toBe(0)
        ->and(Storage::disk('local')->allFiles())->toBe([]);
})->with(['Admin', 'MMS Staff', 'MMS Head', 'Finance']);

it('still refuses new files once the PO is verified (403)', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'verified']);

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po), po_types_body(['po_bundle' => [po_types_pdf('a.pdf')]]))
        ->assertForbidden();

    expect(PoDocument::count())->toBe(0);
});

/* ---------------------------------------------------------------- listing, filter data, checklist */

it('sends the type of every row, keeps old rows working and offers only stage po types', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    po_types_make($po, 'old-bundle.pdf', 1);
    po_types_make($po, 'iar-1.pdf', 2, PoDocumentType::Iar);

    $this->actingAs(po_types_user('Procurement'))
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.rows.0.type_key', 'po_bundle')
            ->where('purchaseOrder.documents.rows.0.type', 'PO Bundle')
            ->where('purchaseOrder.documents.rows.1.type_key', 'iar')
            ->where('purchaseOrder.documents.rows.1.type', 'IAR')
            ->where('purchaseOrder.documents.types', fn ($types) => collect($types)->pluck('key')->all() === [
                'po_bundle', 'supplier_document_checklist', 'guarantee_letter', 'supporting_document', 'other',
            ])
            ->where('purchaseOrder.documents.checklist.items', fn ($items) => collect($items)->contains(
                fn ($item) => $item['key'] === 'iar' && $item['count'] === 1 && $item['selectable'] === false,
            ))
            ->where('purchaseOrder.documents.max_files', 10)
            ->where('purchaseOrder.documents.max_total_mb', 45));
});

it('shows the checklist to people who only read, without upload rights', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'uploaded']);
    po_types_make($po, 'bundle.pdf', 1);

    $this->actingAs(po_types_user('MMS Staff'))
        ->get(route('purchase-orders.show', $po))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.can_upload', false)
            ->where('purchaseOrder.documents.checklist.required_total', 1)
            ->where('purchaseOrder.documents.checklist.required_done', 1));
});

it('counts the required types of the sample list: x of y, and which are missing', function () {
    $po = PurchaseOrder::factory()->create(['status' => 'draft']);

    $empty = PoDocumentChecklist::progress($po);

    expect($empty['is_sample'])->toBeTrue()
        ->and($empty['required_total'])->toBe(1)
        ->and($empty['required_done'])->toBe(0)
        ->and($empty['complete'])->toBeFalse()
        ->and($empty['missing'])->toBe(['PO Bundle']);

    $bundle = po_types_make($po, 'bundle.pdf', 1);
    $done = PoDocumentChecklist::progress($po);

    expect($done['required_done'])->toBe(1)
        ->and($done['complete'])->toBeTrue()
        ->and($done['missing'])->toBe([])
        ->and(collect($done['items'])->firstWhere('key', 'po_bundle')['count'])->toBe(1);

    // A voided document does not count.
    $bundle->status = PoDocumentStatus::Void;
    $bundle->save();

    expect(PoDocumentChecklist::progress($po)['required_done'])->toBe(0);
});

it('counts more than one required type when the list says so', function () {
    config(['mms.documents.types' => [
        ['key' => 'po_bundle', 'label' => 'PO Bundle', 'required' => true, 'stage' => 'po', 'sort' => 1],
        ['key' => 'supplier_document_checklist', 'label' => 'Supplier Document Checklist', 'required' => true, 'stage' => 'po', 'sort' => 2],
        ['key' => 'other', 'label' => 'Others', 'required' => false, 'stage' => 'po', 'sort' => 3],
    ]]);

    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    po_types_make($po, 'bundle.pdf', 1);

    $progress = PoDocumentChecklist::progress($po);

    expect($progress['required_total'])->toBe(2)
        ->and($progress['required_done'])->toBe(1)
        ->and($progress['missing'])->toBe(['Supplier Document Checklist'])
        ->and($progress['complete'])->toBeFalse();
});

it('still lets Procurement submit when a required type is missing', function () {
    config(['mms.documents.types' => [
        ['key' => 'po_bundle', 'label' => 'PO Bundle', 'required' => true, 'stage' => 'po', 'sort' => 1],
        ['key' => 'supporting_document', 'label' => 'Supporting Document', 'required' => false, 'stage' => 'po', 'sort' => 2],
    ]]);

    $po = PurchaseOrder::factory()->create(['status' => 'draft']);
    po_types_make($po, 'support.pdf', 1, PoDocumentType::SupportingDocument); // no PO Bundle at all

    $this->actingAs(po_types_user('Procurement'))
        ->post(po_types_url($po).'/submit')
        ->assertSessionHasNoErrors();

    expect($po->fresh()->status)->toBe(PurchaseOrderStatus::Uploaded);
});

/* ---------------------------------------------------------------- the list in config/mms.php */

it('keeps the sample list and the enum in step: every case is listed, every key is a case, only PO Bundle is required', function () {
    foreach (PoDocumentType::cases() as $case) {
        expect(PoDocumentChecklist::entry($case->value))->not->toBeNull("{$case->value} is missing from mms.documents.types");
    }

    foreach ((array) config('mms.documents.types') as $row) {
        expect(PoDocumentType::tryFrom($row['key']))->not->toBeNull("{$row['key']} is not a PoDocumentType value");
    }

    expect(collect(PoDocumentChecklist::entries())->where('required', true)->pluck('key')->all())->toBe(['po_bundle'])
        ->and(config('mms.documents.checklist_is_sample'))->toBeTrue();
});

it('takes the label of a type from the list in config, and falls back to the enum text', function () {
    expect(PoDocumentType::Other->label())->toBe('Others');

    config(['mms.documents.types' => [
        ['key' => 'iar', 'label' => 'Inspection and Acceptance Report', 'required' => false, 'stage' => 'delivery', 'sort' => 1],
    ]]);

    expect(PoDocumentType::Iar->label())->toBe('Inspection and Acceptance Report')
        ->and(PoDocumentType::PoBundle->label())->toBe('PO Bundle');
});