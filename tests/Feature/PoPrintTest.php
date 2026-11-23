<?php

use App\Models\PoDocument;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PoPrintService;
use App\Support\Qpdf;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Models\Activity;

/*
 * Step 4.6: "Print PO" (form ZCMC-F-PROC-13). CONTEXT.md decision 40.
 *
 * The text of the form is checked in the HTML the service builds (dompdf compresses the text of the PDF and embeds
 * font subsets, so it cannot be read back reliably). The PDF itself is checked by its first bytes and, when qpdf is
 * installed, by its page count (App\Support\Qpdf::pageCount) against the planned page count. Those tests skip
 * themselves when qpdf is not found, as PoConsolidatedPdfTest does: set MMS_QPDF_PATH in .env.
 *
 * Assumption: PoItem::factory() fills category_id and unit_id by itself (the other PO tests rely on it).
 */

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

function po_print_user(string $role): User
{
    return User::factory()->create()->assignRole($role);
}

/** The PO of scan 01: Bionice, two medicine lines, total 5,400,000.00. */
function po_print_make(array $po = [], ?array $lines = null): PurchaseOrder
{
    // The same supplier is reused when a test makes more than one PO (supplier names are unique).
    $supplier = Supplier::query()->firstOrCreate(
        ['name' => 'Bionice Pharmaceutical Products Trading'],
        [
            'address' => 'Veterens Avenue Extension, Sta. Maria Zamboanga City Philippines',
            'tin' => '409-341-604',
            'contact_no' => '09177233473',
            'supply_chain_role' => null,
            'is_active' => true,
        ],
    );

    $order = PurchaseOrder::factory()->create(array_merge([
        'po_number' => '26111037',
        'supplier_id' => $supplier->id,
        'po_date' => '2026-11-05',
        'delivery_term_days' => 7,
        'caf_no' => '26-11-0579',
        'mode_of_procurement' => 'Nego. Proc. - Emergency Cases',
        'remarks' => 'for Pharmacy use',
        'pr_no' => null,
        'source' => 'manual',
        'status' => 'draft',
    ], $po));

    foreach ($lines ?? [
        ['description' => 'Clindamycin 150mg/ml, 4ml amp (as phosphate)', 'stock_no' => 'MED00084', 'quantity_ordered' => 15000, 'unit_price' => '200.00',
            'brand' => 'DALAMYCIN', 'manufacturer' => 'NORTH CHINA PHARMACEUTICAL CO., LTD.', 'country_of_origin' => 'CHINA'],
        ['description' => 'Meropenem (As Trihydrate) 1G Vial powder, IV', 'stock_no' => 'MED01312', 'quantity_ordered' => 6000, 'unit_price' => '400.00',
            'brand' => 'MEROSAPH 1000', 'manufacturer' => 'KILITCH DRUGS (INDIA) LTD.', 'country_of_origin' => 'INDIA'],
    ] as $line) {
        PoItem::factory()->create(array_merge(['purchase_order_id' => $order->id, 'specifications' => null], $line));
    }

    return $order->fresh();
}

function po_print_url(PurchaseOrder $po, string $query = ''): string
{
    return "/purchase-orders/{$po->id}/print".$query;
}

function po_print_real_qpdf(): ?string
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

/** The 17 lines of scan 02 (food, free-text specifications). */
function po_print_seventeen_lines(): array
{
    $names = ['Bread: Cream', 'Bread: Pudding', 'Brownies', 'Cake: Cassava', 'Cookies: Oatmeal Raisin', 'Cookies: Peanut Butter', 'Cookies: White Choco',
        'Cupcake: Butter', 'Cupcake: Carrot', 'Cupcake: Cheese', 'Cupcake: Peanut Butter', 'Cupcake: Pineapple', 'Cupcake: Banana, Plain',
        'Cupcake: Banana, w/choco chips', 'Maja Blanca', 'Puto: Cheese', 'Revel Bar'];

    return collect($names)->map(fn (string $name, int $i) => [
        'description' => $name,
        'stock_no' => 'SUP'.(11000 + $i),
        'quantity_ordered' => 760,
        'unit_price' => '25.00',
        'brand' => null,
        'manufacturer' => null,
        'country_of_origin' => null,
        'specifications' => "soft, crumbly, at least 40g, preferably Halal\nPICK & GO CONVENIENCE STORE, PHILS.",
    ])->all();
}

/* ---------------------------------------------------------------- what is printed */

it('prints the PO number, supplier, lines, totals and the total in words from the stored data', function () {
    $po = po_print_make();

    $built = app(PoPrintService::class)->build($po);
    $html = $built['html'];

    expect($built['pages'])->toBe(1)
        ->and($built['form'])->toBe('ZCMC-F-PROC-13')
        ->and($built['filename'])->toBe('PO-26111037.pdf')
        ->and($html)
        ->toContain('26111037')
        ->toContain('11/05/2026')
        ->toContain('Bionice Pharmaceutical Products Trading')
        ->toContain('Veterens Avenue Extension')
        ->toContain('409-341-604')
        ->toContain('09177233473')
        ->toContain('26-11-0579')
        ->toContain('Nego. Proc. - Emergency Cases')
        ->toContain('Delivery Term: 7 Days Term')
        ->toContain('MED00084')
        ->toContain('Clindamycin 150mg/ml, 4ml amp (as phosphate)')
        ->toContain('Brand Name: DALAMYCIN')
        ->toContain('Manufacturer: NORTH CHINA PHARMACEUTICAL CO., LTD.')
        ->toContain('Country of Origin: CHINA')
        ->toContain('15,000')
        ->toContain('200.00')
        ->toContain('3,000,000.00')
        ->toContain('2,400,000.00')
        ->toContain('5,400,000.00')
        ->toContain('for Pharmacy use')
        ->toContain('Five Million Four Hundred Thousand and 0/100 Cents Only')
        ->toContain('x-x-x-x-x')
        ->toContain('AFDAL B. KUNTING, MD, MPH, FPCP')
        ->toContain('Medical Center Chief II')
        ->toContain('FRITZIE LYNN T. CABILIN')
        ->toContain('ZCMC-F-PROC-13')
        ->toContain('Rev. 12')
        ->toContain('February 24, 2025');
});

it('adds up the total from the lines in whole centavos', function () {
    $po = po_print_make(lines: [
        ['description' => 'Item A', 'stock_no' => null, 'quantity_ordered' => 3, 'unit_price' => '0.10', 'brand' => null, 'manufacturer' => null, 'country_of_origin' => null],
        ['description' => 'Item B', 'stock_no' => null, 'quantity_ordered' => 7, 'unit_price' => '1051.55', 'brand' => null, 'manufacturer' => null, 'country_of_origin' => null],
    ]);

    // 3 x 0.10 + 7 x 1,051.55 = 7,361.15
    $html = app(PoPrintService::class)->build($po)['html'];

    expect($html)->toContain('7,361.15')
        ->toContain('Seven Thousand Three Hundred Sixty-One and 15/100 Cents Only');
});

it('prints missing values empty and invents nothing', function () {
    $po = po_print_make(
        po: ['pr_no' => null, 'caf_no' => null, 'mode_of_procurement' => null, 'remarks' => null, 'delivery_term_days' => null, 'po_date' => null],
        lines: [['description' => 'Plain item', 'stock_no' => null, 'quantity_ordered' => 2, 'unit_price' => '10.00', 'brand' => null, 'manufacturer' => null, 'country_of_origin' => null]],
    );

    $html = app(PoPrintService::class)->build($po)['html'];

    expect($html)->toContain('Plain item')
        ->not->toContain('Brand Name')
        ->not->toContain('Manufacturer:')
        ->not->toContain('Country of Origin')
        ->not->toContain("Supplier's Specifications")
        ->not->toContain('Days Term')          // no delivery term typed: "Delivery Term: " stays empty
        ->not->toContain('Nego. Proc.')
        ->toContain('Delivery Term: ');
});

it('prints free-text specifications on the label line and leaves the fund block blank', function () {
    $po = po_print_make(lines: [[
        'description' => 'Bread: Cream', 'stock_no' => 'SUP11025', 'quantity_ordered' => 140, 'unit_price' => '67.00',
        'brand' => null, 'manufacturer' => null, 'country_of_origin' => null, 'specifications' => 'Sliced, at least 15 slices/loaf, preferably Halal',
    ]]);

    $html = app(PoPrintService::class)->build($po)['html'];

    expect($html)->toContain("***Supplier&#039;s Specifications: Sliced, at least 15 slices/loaf, preferably Halal")
        ->toContain('Fund Cluster:')
        ->toContain('Funds Available:')
        ->toContain('ORS/BURS No.:')
        ->not->toContain('RECEIVED');          // the budget stamp is never printed
});

it('escapes the text so a supplier name with & or < cannot break the page', function () {
    $po = po_print_make();
    $po->supplier->update(['name' => 'PICK & GO <STORE>']);

    $html = app(PoPrintService::class)->build($po->fresh())['html'];

    expect($html)->toContain('PICK &amp; GO &lt;STORE&gt;')->not->toContain('<STORE>');
});

it('prints the signatory who was in office on the PO date', function () {
    config(['mms.print.signatories' => [
        ['name' => 'FIRST CHIEF', 'title' => 'Medical Center Chief II', 'effective_from' => null],
        ['name' => 'SECOND CHIEF', 'title' => 'Medical Center Chief II', 'effective_from' => '2026-12-01'],
    ]]);

    $old = app(PoPrintService::class)->build(po_print_make(['po_date' => '2026-11-05']))['html'];
    $new = app(PoPrintService::class)->build(po_print_make(['po_number' => '26120001', 'po_date' => '2026-12-01']))['html'];

    expect($old)->toContain('FIRST CHIEF')->not->toContain('SECOND CHIEF')
        ->and($new)->toContain('SECOND CHIEF')->not->toContain('FIRST CHIEF');
});

it('makes one page block per planned page, and a long PO takes more pages', function () {
    $service = app(PoPrintService::class);

    $one = $service->build(po_print_make());
    $many = $service->build(po_print_make(['po_number' => '26100971'], po_print_seventeen_lines()));

    expect(substr_count($one['html'], 'class="page"'))->toBe($one['pages'])
        ->and($many['pages'])->toBeGreaterThanOrEqual(2)
        ->and(substr_count($many['html'], 'class="page"'))->toBe($many['pages'])
        ->and($many['warnings'])->toBe([]);
});

it('uses A4 when config says so, and long bond otherwise', function () {
    $po = po_print_make(['po_number' => '26100971'], po_print_seventeen_lines());

    config(['mms.print.paper' => 'bond']);
    $bond = app(PoPrintService::class)->build($po);
    config(['mms.print.paper' => 'a4']);
    $a4 = app(PoPrintService::class)->build($po);
    config(['mms.print.paper' => 'nonsense']);
    $fallback = app(PoPrintService::class)->build($po);

    expect($bond['paper'])->toBe('bond')
        ->and($a4['paper'])->toBe('a4')
        ->and($a4['pages'])->toBeGreaterThan($bond['pages'])   // A4 is shorter: more pages for the same lines
        ->and($fallback['paper'])->toBe('bond');
});

/* ---------------------------------------------------------------- the PDF and the route */

it('sends a real PDF to a user who may see the PO, and writes the po_printed audit event', function (string $role) {
    $po = po_print_make();

    $response = $this->actingAs(po_print_user($role))->get(po_print_url($po));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))->toContain('inline')->toContain('PO-26111037.pdf')
        ->and(str_starts_with((string) $response->getContent(), '%PDF-'))->toBeTrue();

    $audit = Activity::query()->where('event', 'po_printed')->get();

    expect($audit)->toHaveCount(1)
        ->and($audit->first()->subject_type)->toBe($po->getMorphClass())
        ->and($audit->first()->subject_id)->toBe($po->id)
        ->and($audit->first()->properties['form'])->toBe('ZCMC-F-PROC-13')
        ->and($audit->first()->properties['pages'])->toBe(1)
        ->and($audit->first()->properties['total_in_words'])->toBe('Five Million Four Hundred Thousand and 0/100 Cents Only');
})->with(['Procurement', 'MMS Staff', 'Finance', 'Admin']);

it('sends the file as an attachment with ?download=1, and audits every generation', function () {
    $po = po_print_make();
    $user = po_print_user('Procurement');

    $this->actingAs($user)->get(po_print_url($po))->assertOk();
    $response = $this->actingAs($user)->get(po_print_url($po, '?download=1'));

    expect($response->headers->get('content-disposition'))->toContain('attachment')
        ->and(Activity::query()->where('event', 'po_printed')->count())->toBe(2);
});

it('prints a PO at any status, not only drafts', function (string $status) {
    $po = po_print_make(['status' => $status]);

    $this->actingAs(po_print_user('MMS Staff'))->get(po_print_url($po))->assertOk();
})->with(['draft', 'uploaded', 'verified', 'escalated']);

it('does not store the printed PO as a document or touch the combined PDF (decision 40)', function () {
    $po = po_print_make();

    $this->actingAs(po_print_user('Procurement'))->get(po_print_url($po))->assertOk();

    $po->refresh();

    expect(PoDocument::query()->count())->toBe(0)
        ->and($po->consolidated_path)->toBeNull()
        ->and($po->status->value)->toBe('draft');
});

it('refuses a user without po.view (403) and writes nothing', function () {
    $po = po_print_make();

    $this->actingAs(User::factory()->create())->get(po_print_url($po))->assertForbidden();

    expect(Activity::query()->where('event', 'po_printed')->count())->toBe(0);
});

it('sends a guest to the login page', function () {
    $po = po_print_make();

    $this->get(po_print_url($po))->assertRedirect('/login');
});

it('refuses a BizBox PO with a plain message and writes nothing', function () {
    $po = po_print_make(['source' => 'bizbox', 'external_id' => 'BB-1']);

    $response = $this->actingAs(po_print_user('Procurement'))->get(po_print_url($po));

    $response->assertForbidden();
    expect(Activity::query()->where('event', 'po_printed')->count())->toBe(0)
        ->and(Gate::forUser(po_print_user('Procurement'))->inspect('printOrder', $po)->message())->toContain('BizBox');
});

it('shows the Print PO button data: the policy allows a manual PO and refuses a BizBox PO', function () {
    $user = po_print_user('MMS Staff');

    expect($user->can('printOrder', po_print_make()))->toBeTrue()
        ->and($user->can('printOrder', po_print_make(['po_number' => 'BB-2', 'source' => 'bizbox'])))->toBeFalse();
});

/* ---------------------------------------------------------------- page count with the real qpdf */

it('makes a PDF whose page count equals the planned pages (one page)', function () {
    config(['mms.pdf.qpdf_path' => po_print_real_qpdf()]);

    $result = app(PoPrintService::class)->pdf(po_print_make());

    // A normal, closed temp file: another program (qpdf) cannot read a file PHP still has open on Windows.
    $file = tempnam(sys_get_temp_dir(), 'poprint');
    file_put_contents($file, $result['pdf']);

    try {
        expect(Qpdf::pageCount($file))->toBe(1)
            ->and($result['planned_pages'])->toBe(1)
            ->and($result['pages'])->toBe(1);
    } finally {
        @unlink($file);
    }
})->skip(fn () => po_print_real_qpdf() === null, 'qpdf was not found: set MMS_QPDF_PATH in .env');

it('makes a PDF whose page count equals the planned pages for a multi-page PO (17 lines, long bond and A4)', function (string $paper) {
    config(['mms.pdf.qpdf_path' => po_print_real_qpdf(), 'mms.print.paper' => $paper]);

    $result = app(PoPrintService::class)->pdf(po_print_make(['po_number' => '26100971'], po_print_seventeen_lines()));

    $file = tempnam(sys_get_temp_dir(), 'poprint');
    file_put_contents($file, $result['pdf']);

    try {
        expect($result['planned_pages'])->toBeGreaterThanOrEqual(2)
            ->and(Qpdf::pageCount($file))->toBe($result['planned_pages'])
            ->and($result['pages'])->toBe($result['planned_pages'])
            ->and(Qpdf::check($file))->toBeIn([0, 3]);
    } finally {
        @unlink($file);
    }
})->with(['bond', 'a4'])->skip(fn () => po_print_real_qpdf() === null, 'qpdf was not found: set MMS_QPDF_PATH in .env');
