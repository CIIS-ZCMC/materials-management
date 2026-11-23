<?php

namespace App\Services;

use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Support\Money;
use App\Support\Print\AmountInWords;
use App\Support\Print\Fonts;
use App\Support\Print\Form13;
use App\Support\Print\Paper;
use App\Support\Print\Renderer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Prints the standard PO, form ZCMC-F-PROC-13 Rev. 12 (step 4.6, CONTEXT.md decision 40).
 *
 * Only reads stored data: the PO, its supplier and its lines, plus the fixed texts of config/mms.php ("print.*").
 * A missing value prints empty, nothing is invented. The Fund Cluster, Funds Available and ORS/BURS block and the
 * supplier's "Date Received" are printed blank (they are filled by hand), and the budget stamp is never printed.
 *
 * The PDF is generated on every call and is NOT stored (decision 40): the real PO in a bundle is the signed paper
 * copy that Procurement scans and uploads. The caller (PoPrintController) writes the audit event.
 *
 * Page layout, fonts and pagination live in App\Support\Print (the code approved in the step 4.4 spike).
 */
final class PoPrintService
{
    public function __construct(private readonly Renderer $renderer) {}

    /**
     * The HTML of the form and the page plan, without making the PDF (the tests read this).
     *
     * @return array{html: string, pages: int, warnings: list<string>, paper: string, filename: string, form: string}
     */
    public function build(PurchaseOrder $po): array
    {
        $po->loadMissing(['supplier', 'items.unit']);

        $paper = Paper::get($this->paperKey());
        $fonts = new Fonts(resource_path('fonts'));
        $form13 = new Form13($fonts, str_replace('\\', '/', public_path('images')));

        $built = $form13->build($this->viewModel($po), $paper);

        return [
            'html' => $built['html'],
            'pages' => $built['pages'],
            'warnings' => $built['warnings'],
            'paper' => $paper['key'],
            'filename' => 'PO-'.trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $po->po_number), '-').'.pdf',
            'form' => (string) config('mms.print.forms.13.number'),
        ];
    }

    /**
     * @return array{pdf: string, pages: int, planned_pages: int, warnings: list<string>, paper: string, filename: string, form: string}
     */
    public function pdf(PurchaseOrder $po): array
    {
        $built = $this->build($po);
        $paper = Paper::get($built['paper']);
        $made = $this->renderer->toPdf($built['html'], $paper);

        return [
            'pdf' => $made['pdf'],
            'pages' => $made['pages'],
            'planned_pages' => $built['pages'],
            'warnings' => $built['warnings'],
            'paper' => $built['paper'],
            'filename' => $built['filename'],
            'form' => $built['form'],
        ];
    }

    /** "bond" (long bond, 8.5 x 13 in) or "a4": config print.paper, MMS_PRINT_PAPER in .env. Anything else = bond. */
    private function paperKey(): string
    {
        $key = strtolower((string) config('mms.print.paper', 'bond'));

        return in_array($key, ['a4', 'bond'], true) ? $key : 'bond';
    }

    /**
     * What Form13 prints. Every optional value is a plain string ('' when missing).
     *
     * @return array<string, mixed>
     */
    private function viewModel(PurchaseOrder $po): array
    {
        $supplier = $po->supplier;
        $signatory = $this->signatory($po);

        return [
            'po_no' => $po->po_number,
            'po_date' => $po->po_date?->format('m/d/Y') ?? '',
            'pr_no' => (string) $po->pr_no,
            'caf_no' => (string) $po->caf_no,
            'mode' => (string) $po->mode_of_procurement,
            'delivery_term_days' => $po->delivery_term_days,
            'free_text' => (string) $po->remarks,
            'supplier' => [
                'name' => (string) $supplier->name,
                'address' => (string) $supplier->address,
                'role' => (string) $supplier->supply_chain_role,
                'contact' => (string) $supplier->contact_no,
                'tin' => (string) $supplier->tin,
            ],
            'lines' => $po->items->sortBy('id')->values()->map(fn (PoItem $item) => [
                'stock' => (string) $item->stock_no,
                'unit' => (string) $item->unit->name,
                'name' => (string) $item->description,
                'spec' => $this->specLines($item),
                'qty' => (int) $item->quantity_ordered,
                'price' => Money::cents($item->unit_price),
            ])->all(),
            'header' => array_values((array) config('mms.print.header_13', [])),
            'place' => [
                'name' => (string) config('mms.print.place_of_delivery.name'),
                'address' => (string) config('mms.print.place_of_delivery.address'),
            ],
            'payment_term' => (string) config('mms.print.payment_term'),
            'certification' => (string) config('mms.print.certification_13'),
            'signatory' => ['name' => (string) ($signatory['name'] ?? ''), 'title' => (string) ($signatory['title'] ?? '')],
            'accountant' => [
                'name' => (string) config('mms.print.accountant.name'),
                'title' => (string) config('mms.print.accountant.title'),
            ],
            'form' => [
                'number' => (string) config('mms.print.forms.13.number'),
                'revision' => (string) config('mms.print.forms.13.revision'),
                'effectivity' => $this->effectivity((string) config('mms.print.forms.13.effectivity_date')),
            ],
        ];
    }

    /**
     * The Medical Center Chief in office on the PO date (today when the PO has no date): the LAST entry of
     * print.signatories whose effective_from is empty or not after that date. Old POs keep their old signatory.
     *
     * @return array{name?: string, title?: string}
     */
    private function signatory(PurchaseOrder $po): array
    {
        $reference = $po->po_date ?? Carbon::today();
        $found = [];

        foreach ((array) config('mms.print.signatories', []) as $entry) {
            $from = $entry['effective_from'] ?? null;

            if ($from === null || $from === '' || Carbon::parse($from)->startOfDay()->lte($reference)) {
                $found = $entry;
            }
        }

        return $found;
    }

    /** "2025-02-24" -> "February 24, 2025" ('' when not set). */
    private function effectivity(string $date): string
    {
        return $date === '' ? '' : Carbon::parse($date)->format('F j, Y');
    }

    /**
     * The "Supplier's Specifications" lines under an item. Only values that were typed are printed.
     *  - brand, manufacturer or country typed: the label line, then one line each (medicine style);
     *    free text, if any, follows as its own lines;
     *  - only free text typed: label and text on one line (food style);
     *  - nothing typed: no lines at all.
     *
     * @return list<string>
     */
    private function specLines(PoItem $item): array
    {
        $details = [];

        foreach ([
            'Brand Name: ' => $item->brand,
            'Manufacturer: ' => $item->manufacturer,
            'Country of Origin: ' => $item->country_of_origin,
        ] as $label => $value) {
            if (filled($value)) {
                $details[] = $label.Str::of((string) $value)->squish();
            }
        }

        $free = trim((string) $item->specifications);

        if ($details === [] && $free === '') {
            return [];
        }

        if ($details === []) {
            return ["***Supplier's Specifications: ".$free];
        }

        return array_values(array_filter(
            ["***Supplier's Specifications:", ...$details, $free],
            fn (string $line) => $line !== '',
        ));
    }

    /** The total of a PO in centavos (for the audit record). */
    public function totalCentavos(PurchaseOrder $po): int
    {
        $po->loadMissing('items');

        return (int) $po->items->sum(fn (PoItem $item) => Money::cents($item->unit_price) * $item->quantity_ordered);
    }

    /** The total in words as printed, for tests and the audit record. */
    public function totalInWords(PurchaseOrder $po): string
    {
        return AmountInWords::amount($this->totalCentavos($po), 'Cents Only');
    }
}
