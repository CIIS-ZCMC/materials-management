<?php

use App\Enums\PoDocumentStatus;
use App\Enums\PoDocumentType;
use App\Enums\PurchaseOrderStatus;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\PoDocumentService;
use App\Services\PoHistoryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
 * Step 4.2d: MMS staff (permission documents.reorder, in the PO Reviewer job) may move the documents of a
 * SUBMITTED (uploaded) PO; Procurement (po.upload-files) keeps draft and escalated; nobody may after
 * "verified". The rule lives in App\Support\PoDocumentRule (REORDER and REORDER_PERMISSIONS): if it is
 * changed later, these tests are the ones to update.
 *
 * qpdf is faked (Process::fake) like in PoConsolidatedPdfTest, so no program is needed. The helpers have
 * their own prefix (po_reorder_) so they never clash with the helpers of the other test files.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
    config(['mms.pdf.qpdf_path' => 'qpdf-for-tests']);
});

function po_reorder_user(string $role, array $permissions = []): User
{
    $user = User::factory()->create()->assignRole($role);

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return $user;
}

/** MMS staff who may reorder: the MMS Staff role plus the new permission (what the PO Reviewer preset adds). */
function po_reorder_staff(): User
{
    return po_reorder_user('MMS Staff', ['documents.reorder']);
}

function po_reorder_url(PurchaseOrder $po): string
{
    return "/purchase-orders/{$po->id}/documents";
}

/** A PO in this status with two active documents (a first, b second). @return array{0: PurchaseOrder, 1: PoDocument, 2: PoDocument} */
function po_reorder_po(string $status): array
{
    $po = PurchaseOrder::factory()->create(['status' => $status]);

    return [$po, po_reorder_doc($po, "a-{$status}.pdf", 1), po_reorder_doc($po, "b-{$status}.pdf", 2)];
}

function po_reorder_doc(PurchaseOrder $po, string $name, int $order): PoDocument
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

/** @return list<string> original names of this PO in page order */
function po_reorder_names(PurchaseOrder $po): array
{
    return PoDocument::where('purchase_order_id', $po->id)->orderBy('sort_order')->pluck('original_name')->all();
}

/**
 * Every command the fake qpdf was asked to run. (This Laravel version has no Process::recorded(), so the
 * fake writes them down itself.)
 *
 * @return list<list<string>>
 */
function po_reorder_commands(?array $add = null, bool $reset = false): array
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

/** A fake qpdf: every stand-in file has 2 pages; $mergeExit is the exit code of the merge (0 = output written, 2 = failure). */
function po_reorder_fake(int $mergeExit = 0): void
{
    po_reorder_commands(reset: true);

    Process::fake(['*' => function (PendingProcess $process) use ($mergeExit) {
        $command = (array) $process->command;
        po_reorder_commands($command);

        if (in_array('--show-npages', $command, true)) {
            $file = (string) end($command);
            $content = is_file($file) ? (string) file_get_contents($file) : '';

            return Process::result(output: (str_starts_with($content, 'MERGED:') ? substr($content, 7) : '2')."\n");
        }

        if (in_array('--pages', $command, true)) {
            $parts = array_search('--', $command, true) - array_search('--pages', $command, true) - 1;

            if ($mergeExit === 0) {
                file_put_contents((string) end($command), 'MERGED:'.($parts * 2));
            }

            return Process::result(exitCode: $mergeExit);
        }

        return Process::result(exitCode: 0); // --check
    }]);
}

function po_reorder_pdf(string $name = 'extra.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n");
}

/* ---------------------------------------------------------------- MMS staff, uploaded PO */

it('lets MMS staff move a file on an uploaded PO', function () {
    po_reorder_fake();
    [$po, $a, $b] = po_reorder_po('uploaded');

    $this->actingAs(po_reorder_staff())
        ->post(po_reorder_url($po)."/{$b->id}/move", ['direction' => 'up'])
        ->assertSessionHasNoErrors();

    expect(po_reorder_names($po))->toBe(['b-uploaded.pdf', 'a-uploaded.pdf'])
        ->and(PoDocument::orderBy('sort_order')->pluck('sort_order')->all())->toBe([1, 2]);

    $this->actingAs(po_reorder_staff())
        ->post(po_reorder_url($po)."/{$b->id}/move", ['direction' => 'down'])
        ->assertSessionHasNoErrors();

    expect(po_reorder_names($po))->toBe(['a-uploaded.pdf', 'b-uploaded.pdf']);
});

it('gives MMS staff 403 on every PO status except uploaded', function () {
    po_reorder_fake();
    $staff = po_reorder_staff();

    foreach (PurchaseOrderStatus::cases() as $status) {
        if ($status === PurchaseOrderStatus::Uploaded) {
            continue;
        }

        [$po, , $b] = po_reorder_po($status->value);

        $this->actingAs($staff)->post(po_reorder_url($po)."/{$b->id}/move", ['direction' => 'up'])->assertForbidden();

        expect(po_reorder_names($po))->toBe(["a-{$status->value}.pdf", "b-{$status->value}.pdf"]);
    }
});

it('gives people without documents.reorder 403 on an uploaded PO', function (string $role) {
    po_reorder_fake();
    [$po, , $b] = po_reorder_po('uploaded');

    $this->actingAs(po_reorder_user($role))
        ->post(po_reorder_url($po)."/{$b->id}/move", ['direction' => 'up'])
        ->assertForbidden();

    expect(po_reorder_names($po))->toBe(['a-uploaded.pdf', 'b-uploaded.pdf']);
})->with(['MMS Staff', 'Admin', 'MMS Head', 'Finance']);

it('gives a user built from the PO Reviewer job preset the right to reorder an uploaded PO', function () {
    po_reorder_fake();

    expect(config('mms_access.permissions'))->toContain('documents.reorder')
        ->and(config('mms_access.job_presets.po-reviewer.permissions'))->toContain('documents.reorder');

    // Built the way DummyUserSeeder builds a job: the role plus the preset's permissions as direct permissions.
    $reviewer = User::factory()->create()->assignRole('MMS Staff');
    $reviewer->syncPermissions(config('mms_access.job_presets.po-reviewer.permissions'));

    [$po, , $b] = po_reorder_po('uploaded');

    $this->actingAs($reviewer)
        ->post(po_reorder_url($po)."/{$b->id}/move", ['direction' => 'up'])
        ->assertSessionHasNoErrors();

    expect(po_reorder_names($po))->toBe(['b-uploaded.pdf', 'a-uploaded.pdf']);
});

it('gives MMS staff no other document powers: no upload, remove, void, replace or submit', function () {
    po_reorder_fake();
    [$po, $a] = po_reorder_po('uploaded');
    $staff = po_reorder_staff();

    $this->actingAs($staff)
        ->post(po_reorder_url($po), ['groups' => [['type' => 'po_bundle', 'files' => [po_reorder_pdf()]]]])
        ->assertForbidden();

    $this->actingAs($staff)->delete(po_reorder_url($po)."/{$a->id}")->assertForbidden();
    $this->actingAs($staff)->post(po_reorder_url($po)."/{$a->id}/void", ['reason' => 'Wrong document was uploaded'])->assertForbidden();
    $this->actingAs($staff)
        ->post(po_reorder_url($po)."/{$a->id}/replace", ['reason' => 'Wrong document was uploaded', 'file' => po_reorder_pdf('new.pdf')])
        ->assertForbidden();
    $this->actingAs($staff)->post(po_reorder_url($po).'/submit')->assertForbidden();

    expect(PoDocument::where('purchase_order_id', $po->id)->count())->toBe(2)
        ->and(PoDocument::where('purchase_order_id', $po->id)->where('status', PoDocumentStatus::Active->value)->count())->toBe(2);
});

/* ---------------------------------------------------------------- Procurement, unchanged */

it('keeps Procurement at draft and escalated: allowed there, 403 at every other status', function () {
    po_reorder_fake();
    $procurement = po_reorder_user('Procurement');

    foreach (PurchaseOrderStatus::cases() as $status) {
        [$po, , $b] = po_reorder_po($status->value);

        $response = $this->actingAs($procurement)->post(po_reorder_url($po)."/{$b->id}/move", ['direction' => 'up']);

        if (in_array($status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Escalated], true)) {
            $response->assertSessionHasNoErrors();
            expect(po_reorder_names($po))->toBe(["b-{$status->value}.pdf", "a-{$status->value}.pdf"]);
        } else {
            $response->assertForbidden();
            expect(po_reorder_names($po))->toBe(["a-{$status->value}.pdf", "b-{$status->value}.pdf"]);
        }
    }
});

it('gives someone holding both permissions the statuses of both groups, and still nothing after verified', function () {
    po_reorder_fake();
    $both = po_reorder_user('Procurement', ['documents.reorder']);

    foreach (PurchaseOrderStatus::cases() as $status) {
        [$po, , $b] = po_reorder_po($status->value);

        $response = $this->actingAs($both)->post(po_reorder_url($po)."/{$b->id}/move", ['direction' => 'up']);

        if (in_array($status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Uploaded, PurchaseOrderStatus::Escalated], true)) {
            $response->assertSessionHasNoErrors();
        } else {
            $response->assertForbidden();
        }
    }
});

/* ---------------------------------------------------------------- audit and History */

it('writes the audit event with who moved it and the PO status, and shows it in the History', function () {
    po_reorder_fake();
    [$po, , $b] = po_reorder_po('uploaded');
    $staff = po_reorder_staff();

    $this->actingAs($staff)->post(po_reorder_url($po)."/{$b->id}/move", ['direction' => 'up'])->assertSessionHasNoErrors();

    $activity = Activity::where('event', 'documents_reordered')->where('log_name', 'po')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and((int) $activity->causer_id)->toBe($staff->id)
        ->and($activity->description)->toContain('b-uploaded.pdf')
        ->and($activity->description)->toContain('while the PO was '.strtolower(PurchaseOrderStatus::Uploaded->label()));

    // What the PO Reviewer sees on the History tab.
    $entry = collect(app(PoHistoryService::class)->forPurchaseOrder($po->fresh()))->firstWhere('event', 'documents_reordered');

    expect($entry)->not->toBeNull()
        ->and($entry['event_label'])->toBe('Documents reordered')
        ->and($entry['who'])->toBe($staff->name);
});

/* ---------------------------------------------------------------- the combined PDF follows the order */

it('rebuilds the combined PDF in the new order after MMS staff reorder an uploaded PO', function () {
    po_reorder_fake();
    [$po, $a, $b] = po_reorder_po('uploaded');

    $this->actingAs(po_reorder_staff())->post(po_reorder_url($po)."/{$b->id}/move", ['direction' => 'up'])->assertSessionHasNoErrors();

    $merges = array_values(array_filter(po_reorder_commands(), fn (array $command) => in_array('--pages', $command, true)));
    $merge = end($merges);
    $start = array_search('--pages', $merge, true) + 1;
    $files = array_slice($merge, $start, array_search('--', $merge, true) - $start);

    $fresh = $po->fresh();

    expect(array_map('basename', $files))->toBe([basename($b->stored_path), basename($a->stored_path)])
        ->and($fresh->consolidated_path)->not->toBeNull()
        ->and($fresh->consolidated_pages)->toBe(4)
        ->and((bool) $fresh->consolidated_stale)->toBeFalse();
});

it('keeps the new order when the rebuild fails, and lets MMS staff retry with Rebuild', function () {
    po_reorder_fake(2);
    [$po, , $b] = po_reorder_po('uploaded');
    $staff = po_reorder_staff();

    $this->actingAs($staff)
        ->post(po_reorder_url($po)."/{$b->id}/move", ['direction' => 'up'])
        ->assertSessionHas('success', fn ($message) => str_contains($message, 'could not be rebuilt'));

    expect(po_reorder_names($po))->toBe(['b-uploaded.pdf', 'a-uploaded.pdf'])
        ->and((bool) $po->fresh()->consolidated_stale)->toBeTrue();

    po_reorder_fake(0);

    $this->actingAs($staff)->post(po_reorder_url($po).'/rebuild')->assertSessionHasNoErrors();

    $fresh = $po->fresh();

    expect((bool) $fresh->consolidated_stale)->toBeFalse()
        ->and($fresh->consolidated_path)->not->toBeNull();
});

it('does not give the Rebuild retry to MMS staff without documents.reorder', function () {
    po_reorder_fake();
    [$po] = po_reorder_po('uploaded');

    $this->actingAs(po_reorder_user('MMS Staff'))->post(po_reorder_url($po).'/rebuild')->assertForbidden();
});

/* ---------------------------------------------------------------- the second check, under the lock */

it('refuses the move inside the service when the PO was verified after the page loaded', function () {
    po_reorder_fake();
    [$po, , $b] = po_reorder_po('uploaded');
    $staff = po_reorder_staff();

    $stale = PurchaseOrder::find($po->id); // what the page knew: still uploaded
    PurchaseOrder::whereKey($po->id)->update(['status' => 'verified']);

    try {
        app(PoDocumentService::class)->move($stale, $b, 'up', $staff);

        $this->fail('The move should have been refused.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('form');
    }

    expect(po_reorder_names($po))->toBe(['a-uploaded.pdf', 'b-uploaded.pdf']);
});

/* ---------------------------------------------------------------- what the page shows */

it('shows the move buttons to MMS staff only on an uploaded PO, and no other document buttons', function () {
    po_reorder_fake();
    $staff = po_reorder_staff();

    [$uploaded] = po_reorder_po('uploaded');

    $this->actingAs($staff)
        ->get(route('purchase-orders.show', $uploaded))
        ->assertInertia(fn ($page) => $page
            ->where('purchaseOrder.documents.can_reorder', true)
            ->where('purchaseOrder.documents.can_upload', false)
            ->where('purchaseOrder.documents.can_submit', false)
            ->where('purchaseOrder.documents.can_remove', false)
            ->where('purchaseOrder.documents.can_void', false)
            ->where('purchaseOrder.documents.can_replace', false));

    foreach (['draft', 'escalated', 'verified'] as $status) {
        [$po] = po_reorder_po($status);

        $this->actingAs($staff)
            ->get(route('purchase-orders.show', $po))
            ->assertInertia(fn ($page) => $page->where('purchaseOrder.documents.can_reorder', false));
    }
});

/* ---------------------------------------------------------------- the "View" (original file) button, step 4.2e */

it('lets MMS staff with documents.reorder open an original file on an uploaded PO', function () {
    [$po, $a] = po_reorder_po('uploaded');

    $this->actingAs(po_reorder_staff())
        ->get(po_reorder_url($po)."/{$a->id}/file")
        ->assertOk();
});

it('keeps the original files closed to MMS staff at every status except uploaded', function () {
    $staff = po_reorder_staff();

    foreach (PurchaseOrderStatus::cases() as $status) {
        if ($status === PurchaseOrderStatus::Uploaded) {
            continue;
        }

        [$po, $a] = po_reorder_po($status->value);

        $this->actingAs($staff)->get(po_reorder_url($po)."/{$a->id}/file")->assertForbidden();
    }
});

it('keeps the original files closed to MMS staff without documents.reorder', function () {
    [$po, $a] = po_reorder_po('uploaded');

    $this->actingAs(po_reorder_user('MMS Staff'))->get(po_reorder_url($po)."/{$a->id}/file")->assertForbidden();
});

it('shows the View button flag to MMS staff only on an uploaded PO', function () {
    po_reorder_fake();
    $staff = po_reorder_staff();

    [$uploaded] = po_reorder_po('uploaded');

    $this->actingAs($staff)
        ->get(route('purchase-orders.show', $uploaded))
        ->assertInertia(fn ($page) => $page->where('purchaseOrder.documents.can_view', true));

    foreach (['draft', 'escalated', 'verified'] as $status) {
        [$po] = po_reorder_po($status);

        $this->actingAs($staff)
            ->get(route('purchase-orders.show', $po))
            ->assertInertia(fn ($page) => $page->where('purchaseOrder.documents.can_view', false));
    }
});