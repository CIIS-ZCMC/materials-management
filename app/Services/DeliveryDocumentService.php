<?php

namespace App\Services;

use App\Enums\DeliveryStatus;
use App\Enums\PoDocumentStatus;
use App\Exceptions\InvalidStatusTransition;
use App\Models\Delivery;
use App\Models\PoDocument;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\DeliveryDocumentRule;
use App\Support\PoDocumentChecklist;
use App\Support\PoDocumentOrder;
use App\Support\StatusTransition;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The documents of ONE delivery and its completeness gate (step 5.3). Files are stored by PoDocumentService
 * (same checks, same private disk); this service adds the delivery side:
 *
 *   checklist()  which required types have an active file, which are missing (by name)
 *   upload()     files for a for_uploading delivery
 *   void()       a wrong file, with a reason; if the delivery was complete and a required type is left without a
 *                file, the delivery goes back to for_uploading in the SAME transaction (reason written for you)
 *   replace()    void + new file of the same type; the delivery status never changes
 *   complete()   for_uploading -> complete, only when no required file is missing (the block message names them)
 *   reopen()     complete -> for_uploading, with a reason (decision 17)
 *
 * Every status change goes through StatusTransition::apply() (log "delivery"). Permissions are the Policy's job.
 * Lock order: PO row, then delivery row. After any change the CONTROLLER rebuilds the PO's combined PDF
 * (PoConsolidatedPdfService), because the PDF changes with the files and with the delivery status.
 * Errors that belong to a button use the error key "form"; the file box uses "file"; a reason uses "reason".
 */
class DeliveryDocumentService
{
    public function __construct(
        private readonly PoDocumentService $documents,
        private readonly DeliveryService $deliveries,
    ) {}

    /**
     * For the documents panel: the checklist (one line per delivery type, required ones first by config order)
     * and every file of the delivery, voided ones included, in the order of the combined PDF.
     *
     * @return array{required_total: int, required_done: int, complete: bool, missing: list<string>, items: list<array<string, mixed>>}
     */
    public function checklist(Delivery $delivery): array
    {
        /** @var array<string, int> $counts */
        $counts = PoDocument::query()
            ->where('delivery_id', $delivery->getKey())
            ->where('status', PoDocumentStatus::Active->value)
            ->selectRaw('type, count(*) as total')
            ->groupBy('type')
            ->pluck('total', 'type')
            ->map(fn ($total) => (int) $total)
            ->all();

        $items = [];
        $required = 0;
        $done = 0;
        $missing = [];

        foreach (PoDocumentChecklist::deliveryTypes() as $type) {
            $count = $counts[$type['key']] ?? 0;

            $items[] = ['key' => $type['key'], 'label' => $type['label'], 'required' => $type['required'], 'count' => $count];

            if ($type['required']) {
                $required++;
                $count > 0 ? $done++ : $missing[] = $type['label'];
            }
        }

        return [
            'required_total' => $required,
            'required_done' => $done,
            'complete' => $missing === [],
            'missing' => $missing,
            'items' => $items,
        ];
    }

    /**
     * Everything the documents panel of the delivery page needs. Hiding a button is for convenience only; the Policy,
     * the Form Requests and the Services are the real protection. The reasons are shown only to people who hold
     * delivery.upload-docs (the ones who could act).
     *
     * @return array<string, mixed>
     */
    public function panel(Delivery $delivery, ?User $user): array
    {
        $delivery->loadMissing('purchaseOrder');

        $upload = Gate::inspect('uploadDocuments', $delivery);
        $void = Gate::inspect('voidDocument', $delivery);
        $replace = Gate::inspect('replaceDocument', $delivery);
        $complete = Gate::inspect('completeDocuments', $delivery);
        $reopen = Gate::inspect('reopenDocuments', $delivery);
        $handles = (bool) $user?->can('delivery.upload-docs');
        $checklist = $this->checklist($delivery);

        return [
            'can_upload' => $upload->allowed(),
            'can_void' => $void->allowed(),
            'can_replace' => $replace->allowed(),
            // The button shows while the delivery is for_uploading; the Service refuses (naming the files) while any is missing.
            'can_complete' => $complete->allowed(),
            'can_reopen' => $reopen->allowed(),
            'can_view' => $user !== null && Gate::allows('viewDocument', $delivery),
            'can_view_combined' => $user !== null && Gate::allows('viewConsolidated', $delivery->purchaseOrder),
            'locked_reason' => $handles && ! $upload->allowed() ? $upload->message() : null,
            'accept' => collect((array) config('mms.uploads.allowed_extensions'))->map(fn ($ext) => '.'.$ext)->implode(','),
            'max_mb' => PoDocumentService::maxMegabytes(),
            'max_files' => (int) config('mms.uploads.max_files_per_request'),
            'max_total_mb' => (int) floor(((int) config('mms.uploads.max_request_kb')) / 1024),
            'types' => array_map(fn (array $type) => ['key' => $type['key'], 'label' => $type['label']], PoDocumentChecklist::deliveryTypes()),
            'checklist' => $checklist,
            'pdf_mode' => PoDocumentOrder::mode(),
            'rows' => $this->files($delivery),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function files(Delivery $delivery, bool $includeVoid = true): array
    {
        return collect($this->documents->rowsFor($delivery->purchaseOrder ?? PurchaseOrder::query()->findOrFail($delivery->purchase_order_id), $includeVoid))
            ->where('delivery_id', $delivery->getKey())
            ->values()
            ->all();
    }

    /**
     * @param  list<array{type: string, files: list<UploadedFile>}>  $groups
     * @return list<PoDocument>
     *
     * @throws ValidationException
     */
    public function upload(Delivery $delivery, array $groups, User $actor): array
    {
        $this->deliveries->noteIarWindowExceeded($delivery);

        $po = PurchaseOrder::query()->findOrFail($delivery->purchase_order_id);

        return $this->documents->uploadMany($po, $groups, $actor, $delivery);
    }

    /**
     * @return array{reopened: bool}
     *
     * @throws ValidationException
     */
    public function void(Delivery $delivery, PoDocument $document, string $reason, User $actor): array
    {
        $reason = $this->cleanReason($reason);
        $this->deliveries->noteIarWindowExceeded($delivery);

        return DB::transaction(function () use ($delivery, $document, $reason, $actor): array {
            [$po, $row] = $this->lock($delivery);

            $this->documents->voidDeliveryDocument($row, $document, $reason, $actor);

            $missing = DeliveryDocumentRule::missing($row);

            if ($row->status !== DeliveryStatus::Complete || $missing === []) {
                return ['reopened' => false];
            }

            // A required type is left without a file: the delivery is not complete any more.
            $this->transition($row, DeliveryStatus::ForUploading, 'A required document was voided without a replacement ('.implode(', ', $missing).'). '.$reason, [
                'missing' => $missing,
                'document_id' => $document->getKey(),
            ]);

            return ['reopened' => true];
        });
    }

    /**
     * @throws ValidationException
     */
    public function replace(Delivery $delivery, PoDocument $document, UploadedFile $file, string $reason, User $actor): PoDocument
    {
        $this->deliveries->noteIarWindowExceeded($delivery);

        return $this->documents->replaceForDelivery($delivery, $document, $file, $this->cleanReason($reason), $actor);
    }

    /**
     * The completeness gate: for_uploading -> complete, only when every required type has an active file.
     *
     * @throws ValidationException error key "form" naming the missing files
     */
    public function complete(Delivery $delivery, User $actor): Delivery
    {
        $this->deliveries->noteIarWindowExceeded($delivery);

        return DB::transaction(function () use ($delivery, $actor): Delivery {
            [$po, $row] = $this->lock($delivery);

            $missing = DeliveryDocumentRule::missing($row);

            if ($reason = DeliveryDocumentRule::completeBlockReason($row, $missing)) {
                throw ValidationException::withMessages(['form' => $reason]);
            }

            $this->transition($row, DeliveryStatus::Complete, null, ['marked_by' => $actor->name]);

            return $row;
        });
    }

    /**
     * complete -> for_uploading with a reason (decision 17), for example when a file must be corrected.
     *
     * @throws ValidationException error key "reason" or "form"
     */
    public function reopen(Delivery $delivery, string $reason, User $actor): Delivery
    {
        $reason = $this->cleanReason($reason);

        return DB::transaction(function () use ($delivery, $reason, $actor): Delivery {
            [$po, $row] = $this->lock($delivery);

            if ($message = DeliveryDocumentRule::reopenBlockReason($row)) {
                throw ValidationException::withMessages(['form' => $message]);
            }

            $this->transition($row, DeliveryStatus::ForUploading, $reason, ['reopened_by' => $actor->name]);

            return $row;
        });
    }

    // ---- internals -------------------------------------------------------------------------------

    /**
     * PO row first, then the delivery row, both locked; the PO is attached to the delivery for the rules.
     *
     * @return array{0: PurchaseOrder, 1: Delivery}
     */
    private function lock(Delivery $delivery): array
    {
        $poId = Delivery::query()->whereKey($delivery->getKey())->value('purchase_order_id');
        $po = PurchaseOrder::query()->whereKey($poId)->lockForUpdate()->firstOrFail();
        $row = Delivery::query()->whereKey($delivery->getKey())->lockForUpdate()->firstOrFail();
        $row->setRelation('purchaseOrder', $po);

        return [$po, $row];
    }

    /**
     * The only status change of this service. A stale model or a move the enum forbids becomes a plain message.
     *
     * @param  array<string, mixed>  $extra
     */
    private function transition(Delivery $row, DeliveryStatus $to, ?string $reason, array $extra = []): void
    {
        try {
            StatusTransition::apply(
                $row,
                'status',
                $to,
                reason: $reason,
                extra: ['iar_number' => $row->iar_number, 'purchase_order_id' => $row->purchase_order_id, ...$extra],
                log: 'delivery',
            );
        } catch (InvalidStatusTransition $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }
    }

    /** @throws ValidationException */
    private function cleanReason(string $reason): string
    {
        $reason = trim($reason);

        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => 'Give a reason of 5 to 500 characters.']);
        }

        return $reason;
    }
}
