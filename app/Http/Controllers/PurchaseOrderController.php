<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderSource;
use App\Enums\PurchaseOrderStatus;
use App\Http\Requests\EscalatePurchaseOrderRequest;
use App\Http\Requests\RecordExtensionRequest;
use App\Http\Requests\SavePurchaseOrderRequest;
use App\Http\Requests\SendBackPurchaseOrderRequest;
use App\Http\Requests\VerifyPurchaseOrderRequest;
use App\Models\PoExtension;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Services\PoConsolidatedPdfService;
use App\Services\PoDocumentService;
use App\Services\PoExtensionService;
use App\Services\PoHistoryService;
use App\Services\PoReviewService;
use App\Services\PurchaseOrderService;
use App\Support\DisplayTime;
use App\Support\Money;
use App\Support\PoDocumentRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $service,
        private readonly PoExtensionService $extensions,
        private readonly PoReviewService $reviews,
        private readonly PoHistoryService $history,
        private readonly PoDocumentService $documents,
        private readonly PoConsolidatedPdfService $consolidated,
    ) {}

    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', PurchaseOrder::class);

        $filters = $this->service->cleanFilters($request->query());
        $paginator = $this->service->paginate($filters);

        // The Admin queue: escalated POs show their remarks. One audit query for the whole page.
        $escalations = $this->history->latestEscalations(
            $paginator->getCollection()
                ->filter(fn (PurchaseOrder $po) => $po->status === PurchaseOrderStatus::Escalated)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
        );

        return Inertia::render('purchase-orders/index', [
            'purchaseOrders' => [
                'data' => $paginator->getCollection()
                    ->map(fn (PurchaseOrder $po) => $this->row($po, $escalations[$po->id] ?? null))
                    ->values(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
            ],
            'filters' => $filters,
            'statusOptions' => collect(PurchaseOrderStatus::cases())
                ->map(fn (PurchaseOrderStatus $status) => ['value' => $status->value, 'label' => $status->label()])
                ->values()
                ->all(),
            // A closure: only loaded on the first visit, not on every keystroke (partial reloads skip it).
            'supplierOptions' => fn () => $this->service->supplierOptions(),
            // Number on the "Escalated" tab. A closure too: typing in the search box skips it.
            'escalatedCount' => fn () => $this->reviews->escalatedCount(),
        ]);
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('view', $purchaseOrder);

        return Inertia::render('purchase-orders/show', [
            'purchaseOrder' => $this->detail($this->service->detail($purchaseOrder)),
        ]);
    }

    public function create(): Response
    {
        Gate::authorize('create', PurchaseOrder::class);

        return Inertia::render('purchase-orders/create', [
            'supplierOptions' => $this->service->supplierChoices(),
            'dueDateRules' => $this->dueDateRules(),
        ]);
    }

    public function store(SavePurchaseOrderRequest $request): RedirectResponse
    {
        $po = $this->service->create($request->validated());

        return $this->saved($po, "Purchase order {$po->po_number} created.");
    }

    public function edit(PurchaseOrder $purchaseOrder): Response
    {
        Gate::authorize('update', $purchaseOrder);

        $po = $this->service->loadForEdit($purchaseOrder);

        return Inertia::render('purchase-orders/edit', [
            'purchaseOrder' => $this->formData($po),
            'supplierOptions' => $this->service->supplierChoices($po->supplier_id),
            'dueDateRules' => $this->dueDateRules(),
        ]);
    }

    public function update(SavePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $po = $this->service->update($purchaseOrder, $request->validated());

        return $this->saved($po, "Purchase order {$po->po_number} saved.");
    }

    /**
     * Records an approved extension (step 3.5). The Form Request checks extension.manage (403);
     * the Service checks the PO rules under a row lock (error "form" on the page).
     */
    public function storeExtension(RecordExtensionRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $extension = $this->extensions->recordExtension($purchaseOrder, $request->user(), $request->validated());

        return $this->saved(
            $purchaseOrder,
            "Extension recorded for PO {$purchaseOrder->po_number}. New due date: {$extension->new_due_date->toDateString()}.",
        );
    }

    /**
     * Step 3.6: the PO Reviewer verifies an uploaded PO. The Form Request checks po.review (403);
     * the Service checks the PO rules under a row lock (error "form" on the page).
     */
    public function verify(VerifyPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $po = $this->reviews->verify($purchaseOrder, $request->user());

        return $this->saved(
            $po,
            $po->due_date === null
                ? "PO {$po->po_number} verified. It has no due date."
                : "PO {$po->po_number} verified.",
        );
    }

    /** Step 3.6: the PO Reviewer escalates an uploaded PO to the Admin with remarks (po.escalate). */
    public function escalate(EscalatePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $po = $this->reviews->escalate($purchaseOrder, $request->user(), $request->validated('reason'));

        return $this->saved($po, "PO {$po->po_number} escalated to the Admin.");
    }

    /** Step 3.6: the Admin (po.edit) sends a fixed, escalated PO back to the Reviewer with a note. */
    public function sendBack(SendBackPurchaseOrderRequest $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $po = $this->reviews->sendBack($purchaseOrder, $request->user(), $request->validated('reason'));

        return $this->saved($po, "PO {$po->po_number} sent back to the Reviewer.");
    }

    /**
     * Small JSON endpoint for the item picker (active items only, at most 10).
     * An empty or missing q returns the first 10 active items, alphabetical, so the
     * dropdown has something to show before the user types.
     * Needs po.create or po.edit (PurchaseOrderPolicy::searchItems).
     */
    public function itemSearch(Request $request): JsonResponse
    {
        Gate::authorize('searchItems', PurchaseOrder::class);

        $q = $request->query('q', '');
        $term = is_string($q) ? mb_substr(trim($q), 0, 100) : '';

        return response()->json([
            'items' => $this->service->searchItems($term),
        ]);
    }

    /** One place for the success toast (the flash message shared by HandleInertiaRequests). */
    private function saved(PurchaseOrder $po, string $message): RedirectResponse
    {
        return redirect()->route('purchase-orders.show', $po)->with('success', $message);
    }

    /**
     * How the form's live due date preview counts days (same settings as App\Support\DueDate).
     *
     * @return array{term: string, extension: string}
     */
    private function dueDateRules(): array
    {
        return [
            'term' => (string) config('mms.delivery.term_counting'),
            'extension' => (string) config('mms.delivery.extension_counting'),
        ];
    }

    /**
     * @param  array{reason: string|null, by: string|null, at: string}|null  $escalation
     * @return array<string, mixed>
     */
    private function row(PurchaseOrder $po, ?array $escalation = null): array
    {
        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'supplier' => $po->supplier->name,
            'status' => ['value' => $po->status->value, 'label' => $po->status->label()],
            'due_date' => $po->due_date?->toDateString(),
            'po_date' => $po->po_date?->toDateString(),
            'items_count' => (int) $po->items_count,
            // Latest escalation remarks (escalated POs only; null when none were recorded).
            'escalation' => $escalation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(PurchaseOrder $po): array
    {
        // Money in whole centavos (integers) so totals never drift.
        $totalCents = 0;

        $items = $po->items->map(function (PoItem $item) use (&$totalCents) {
            $lineCents = Money::cents($item->unit_price) * $item->quantity_ordered;
            $totalCents += $lineCents;

            return [
                'id' => $item->id,
                'description' => $item->description,
                'category' => $item->category->name,
                'unit' => $item->unit->name,
                'unit_price' => $item->unit_price,
                'quantity_ordered' => $item->quantity_ordered,
                'line_total' => Money::format($lineCents),
                'in_catalog' => $item->item_id !== null,
            ];
        })->values();

        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'supplier' => $po->supplier->name,
            'source' => $po->source === PurchaseOrderSource::BizBox ? 'BizBox' : 'Manual',
            'external_id' => $po->external_id,
            'po_date' => $po->po_date?->toDateString(),
            'emailed_date' => $po->emailed_date?->toDateString(),
            'delivery_term_days' => $po->delivery_term_days,
            'due_date' => $po->due_date?->toDateString(),
            'status' => ['value' => $po->status->value, 'label' => $po->status->label()],
            'closed_at' => $po->closed_at ? DisplayTime::format($po->closed_at) : null,
            'items' => $items,
            'total' => Money::format($totalCents),
            'schedules' => $po->schedules->map(fn ($s) => $s->scheduled_date->toDateString())->values(),
            'extensions' => $po->extensions->map(fn (PoExtension $e) => [
                'id' => $e->id,
                'extra_days' => $e->extra_days,
                'reason' => $e->reason,
                'old_due_date' => $e->old_due_date->toDateString(),
                'new_due_date' => $e->new_due_date->toDateString(),
                'approved_by' => $e->approver?->name,
                'approved_at' => DisplayTime::format($e->approved_at),
            ])->values(),
            'edit' => $this->editInfo($po),
            'extension' => $this->extensionInfo($po),
            'review' => $this->reviewInfo($po),
            'escalation' => $po->status === PurchaseOrderStatus::Escalated ? $this->history->latestEscalation($po) : null,
            'history' => $this->history->forPurchaseOrder($po),
            'documents' => $this->documentsInfo($po),
        ];
    }

    /**
     * Drives the Documents tab (steps 4.2 and 4.3): the combined PDF, the page index, and the buttons for
     * the people who may use them. Hiding a button is for convenience; the Form Requests and the Policy
     * are the real protection. can_view is the single ORIGINAL file (Admin, MMS Head, and uploaders while
     * the PO is open); everyone with po.view sees the combined PDF (can_view_consolidated).
     * Voided rows are sent only to people who handle or audit the files.
     *
     * @return array{can_upload: bool, can_submit: bool, can_remove: bool, can_reorder: bool, can_view: bool, can_view_consolidated: bool, can_void: bool, can_replace: bool, can_rebuild: bool, locked_reason: string|null, accept: string, max_mb: string, consolidated: array<string, mixed>, rows: list<array<string, mixed>>}
     */
    private function documentsInfo(PurchaseOrder $po): array
    {
        $user = request()->user();

        return [
            'can_upload' => $user?->can('uploadDocument', $po) ?? false,
            'can_submit' => ($user?->can('submitDocuments', $po) ?? false) && $this->documents->hasActive($po),
            'can_remove' => $user?->can('removeDocument', $po) ?? false,
            'can_reorder' => $user?->can('reorderDocuments', $po) ?? false,
            'can_view' => $user?->can('viewDocument', $po) ?? false,
            'can_view_consolidated' => $user?->can('viewConsolidated', $po) ?? false,
            'can_void' => $user?->can('voidDocument', $po) ?? false,
            'can_replace' => $user?->can('replaceDocument', $po) ?? false,
            'can_rebuild' => $user?->can('rebuildConsolidated', $po) ?? false,
            'locked_reason' => ($user?->can('po.upload-files') ?? false) ? PoDocumentRule::addBlockReason($po) : null,
            'accept' => collect((array) config('mms.uploads.allowed_extensions'))->map(fn ($ext) => '.'.$ext)->implode(','),
            'max_mb' => PoDocumentService::maxMegabytes(),
            'consolidated' => $this->consolidated->info($po),
            'rows' => $this->documents->rowsFor($po, includeVoid: $user?->canAny(['po.upload-files', 'documents.void', 'documents.view-originals']) ?? false),
        ];
    }

    /**
     * Drives the Edit button. The reason is shown only to people who hold po.edit.
     * Hiding the button is for convenience; PurchaseOrderPolicy is the real protection.
     *
     * @return array{allowed: bool, reason: string|null}
     */
    private function editInfo(PurchaseOrder $po): array
    {
        if (! request()->user()?->can('po.edit')) {
            return ['allowed' => false, 'reason' => null];
        }

        $result = Gate::inspect('update', $po);

        return ['allowed' => $result->allowed(), 'reason' => $result->allowed() ? null : $result->message()];
    }

    /**
     * Drives the "Record extension" button (Schedule tab). The reason is shown only to
     * people who hold extension.manage. max_days and counting feed the modal's hints.
     *
     * @return array{allowed: bool, reason: string|null, max_days: int, counting: string}
     */
    private function extensionInfo(PurchaseOrder $po): array
    {
        $info = [
            'allowed' => false,
            'reason' => null,
            'max_days' => (int) config('mms.delivery.extension_max_days'),
            'counting' => (string) config('mms.delivery.extension_counting'),
        ];

        if (! request()->user()?->can('extension.manage')) {
            return $info;
        }

        $result = Gate::inspect('extend', $po);

        return [
            ...$info,
            'allowed' => $result->allowed(),
            'reason' => $result->allowed() ? null : $result->message(),
        ];
    }

    /**
     * Drives the Verify, Escalate and "Send back to Reviewer" buttons (step 3.6). Reasons go only
     * to people who hold the permission, and only while they matter: the review reason while the
     * PO is before verification, the send-back reason while the PO is escalated. Hiding a button is
     * for convenience; PurchaseOrderPolicy and PoReviewRule are the real protection.
     *
     * @return array{can_verify: bool, can_escalate: bool, can_send_back: bool, reason: string|null, send_back_reason: string|null}
     */
    private function reviewInfo(PurchaseOrder $po): array
    {
        $user = request()->user();

        $verify = $user?->can('po.review') ? Gate::inspect('verify', $po) : null;
        $escalate = $user?->can('po.escalate') ? Gate::inspect('escalate', $po) : null;
        $sendBack = $user?->can('po.edit') ? Gate::inspect('sendBack', $po) : null;

        $beforeVerification = in_array(
            $po->status,
            [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Uploaded, PurchaseOrderStatus::Escalated],
            true,
        );

        $reviewReason = null;

        if ($beforeVerification) {
            foreach ([$verify, $escalate] as $result) {
                if ($result !== null && ! $result->allowed() && $result->message()) {
                    $reviewReason = $result->message();

                    break;
                }
            }
        }

        $sendBackReason = $po->status === PurchaseOrderStatus::Escalated && $sendBack !== null && ! $sendBack->allowed()
            ? $sendBack->message()
            : null;

        return [
            'can_verify' => $verify?->allowed() ?? false,
            'can_escalate' => $escalate?->allowed() ?? false,
            'can_send_back' => $sendBack?->allowed() ?? false,
            'reason' => $reviewReason,
            'send_back_reason' => $sendBackReason,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(PurchaseOrder $po): array
    {
        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'supplier_id' => $po->supplier_id,
            'po_date' => $po->po_date?->toDateString(),
            'emailed_date' => $po->emailed_date?->toDateString(),
            'delivery_term_days' => $po->delivery_term_days,
            'status_label' => $po->status->label(),
            'items' => $po->items->map(fn (PoItem $item) => [
                'id' => $item->id,
                'item_id' => $item->item_id,
                'item_name' => $item->item?->name,
                'item_status' => $item->item === null ? 'missing' : ($item->item->is_active ? 'ok' : 'inactive'),
                'description' => $item->description,
                'category' => $item->category->name,
                'unit' => $item->unit->name,
                'unit_price' => $item->unit_price,
                'quantity_ordered' => $item->quantity_ordered,
            ])->values(),
            'schedule_dates' => $po->schedules->map(fn ($s) => $s->scheduled_date->toDateString())->values(),
            'extension_days' => $po->extensions->pluck('extra_days')->values(),
        ];
    }
}