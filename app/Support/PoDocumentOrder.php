<?php

namespace App\Support;

use App\Enums\DeliveryStatus;
use App\Models\PoDocument;
use Illuminate\Support\Collection;

/**
 * The page order of a PO's combined PDF and its page index (step 5.3). ONE place, used by
 * PoConsolidatedPdfService (builds the PDF) and PoDocumentService::rowsFor() (the page numbers), so the
 * index can never disagree with the PDF.
 *
 *   1. The PO's own files (delivery_id empty), by sort_order (the arrows of step 4.2d move these).
 *   2. Then each delivery's files, deliveries in the order they were recorded (delivery id), and inside a
 *      delivery by the type order of config/mms.php (Notice, DR, IAR, OR ...), then by sort_order.
 *
 * Which delivery files are IN the PDF is config('mms.documents.delivery_in_pdf'):
 *   on_upload      (default) every active delivery file, unless its delivery is voided
 *   when_complete  only the files of a delivery that is "Documents complete"
 * A voided delivery never contributes pages. Voided documents are never in the PDF.
 */
final class PoDocumentOrder
{
    public const ON_UPLOAD = 'on_upload';

    public const WHEN_COMPLETE = 'when_complete';

    public static function mode(): string
    {
        return config('mms.documents.delivery_in_pdf') === self::WHEN_COMPLETE ? self::WHEN_COMPLETE : self::ON_UPLOAD;
    }

    /** Whether this ACTIVE document belongs in the combined PDF. Needs the "delivery" relation loaded for delivery files. */
    public static function included(PoDocument $document): bool
    {
        if ($document->delivery_id === null) {
            return true;
        }

        $status = $document->delivery?->status;

        if ($status === null || $status === DeliveryStatus::Voided) {
            return false;
        }

        return self::mode() === self::ON_UPLOAD || $status === DeliveryStatus::Complete;
    }

    /**
     * @param  Collection<int, PoDocument>  $documents
     * @return Collection<int, PoDocument>
     */
    public static function sort(Collection $documents): Collection
    {
        return $documents
            ->sortBy(fn (PoDocument $d) => [
                $d->delivery_id === null ? 0 : 1,
                (int) $d->delivery_id,
                $d->delivery_id === null ? 0 : PoDocumentChecklist::deliverySort($d->type->value),
                (int) $d->sort_order,
            ])
            ->values();
    }
}
