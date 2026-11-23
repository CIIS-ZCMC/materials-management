<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Services\PoPrintService;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * "Print PO" (step 4.6): makes the standard PO (form ZCMC-F-PROC-13) from the stored data and sends it to the browser.
 * GET /purchase-orders/{id}/print          opens it in the browser's PDF viewer (print or download from there)
 * GET /purchase-orders/{id}/print?download=1   sends it as a file
 *
 * Nothing is stored (decision 40). Every successful generation writes the audit event po_printed.
 */
class PoPrintController extends Controller
{
    public function __construct(private readonly PoPrintService $printing) {}

    public function __invoke(Request $request, PurchaseOrder $purchaseOrder): Response
    {
        // po.view, and a BizBox PO is refused with a plain message (PurchaseOrderPolicy::printOrder, PoPrintRule).
        Gate::authorize('printOrder', $purchaseOrder);

        try {
            $result = $this->printing->pdf($purchaseOrder);
        } catch (Throwable $e) {
            report($e);

            abort(500, 'The purchase order could not be printed. Please try again, and tell the System Admin if it keeps happening.');
        }

        Audit::log(
            event: 'po_printed',
            subject: $purchaseOrder,
            description: "Printed PO {$purchaseOrder->po_number} (form {$result['form']})",
            extra: [
                'form' => $result['form'],
                'pages' => $result['pages'],
                'paper' => $result['paper'],
                'total_in_words' => $this->printing->totalInWords($purchaseOrder),
                'po_status' => $purchaseOrder->status->value,
                'download' => $request->boolean('download'),
            ],
        );

        return response($result['pdf'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$result['filename'].'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
