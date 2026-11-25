<?php

namespace App\Policies;

use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\DeliveryDocumentRule;
use App\Support\DeliveryRule;
use Illuminate\Auth\Access\Response;

/**
 * delivery.encode   record, edit and void a delivery (the Encoder job). Each only while DeliveryRule allows it.
 * Reading           po.view or any delivery.* permission (the Deliveries list page is still behind the
 *                   section.deliveries gate in routes/web.php).
 * Step 5.3, the documents of a delivery (DR, OR, IAR ...), existing permissions only:
 *   delivery.upload-docs                upload, "Mark complete" and "Reopen for uploading" (the Encoder job)
 *   delivery.upload-docs + documents.void   void and replace a file (the Encoder; Procurement holds documents.void
 *                                       but not delivery.upload-docs, so it cannot touch delivery files)
 *   documents.view-originals or delivery.upload-docs / delivery.encode   open one original file
 * The PO Bundle is untouched: PurchaseOrderPolicy still needs po.upload-files for it, which the Encoder lacks.
 * Each action is also limited by DeliveryDocumentRule (delivery status, closed PO).
 * Existing permissions only: nothing was added in step 5.2 or 5.3.
 * Auto-discovered: App\Models\Delivery -> App\Policies\DeliveryPolicy.
 */
class DeliveryPolicy
{
    public const ENCODE = 'delivery.encode';

    public const READ_PERMISSIONS = [
        'po.view',
        'delivery.encode',
        'delivery.upload-docs',
        'delivery.update-items',
    ];

    public function viewAny(User $actor): bool
    {
        return $actor->hasAnyPermission(self::READ_PERMISSIONS);
    }

    public function view(User $actor, Delivery $delivery): bool
    {
        return $actor->hasAnyPermission(self::READ_PERMISSIONS);
    }

    /** Called as Gate::authorize('create', [Delivery::class, $purchaseOrder]). Returns a Response so a blocked PO gives a clear 403 message. */
    public function create(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can(self::ENCODE)) {
            return Response::deny();
        }

        $reason = DeliveryRule::addBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Asking for a suggested IAR number needs the same permission as recording a delivery. */
    public function suggestIar(User $actor): bool
    {
        return $actor->can(self::ENCODE);
    }

    public function update(User $actor, Delivery $delivery): Response
    {
        if (! $actor->can(self::ENCODE)) {
            return Response::deny();
        }

        $delivery->loadMissing('purchaseOrder');
        $reason = DeliveryRule::editBlockReason($delivery);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Who may void: the same people who record (default proposal, awaiting MMS). Change it here only. */
    public function void(User $actor, Delivery $delivery): Response
    {
        if (! $actor->can(self::ENCODE)) {
            return Response::deny();
        }

        $delivery->loadMissing('purchaseOrder');
        $reason = DeliveryRule::voidBlockReason($delivery);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    // ---- documents of a delivery (step 5.3) --------------------------------------------------------

    public const UPLOAD_DOCS = 'delivery.upload-docs';

    public const VOID_DOCS = 'documents.void';

    /** Upload DR, OR, IAR and the other delivery files. Only while the delivery is "For uploading". */
    public function uploadDocuments(User $actor, Delivery $delivery): Response
    {
        if (! $actor->can(self::UPLOAD_DOCS)) {
            return Response::deny();
        }

        $delivery->loadMissing('purchaseOrder');
        $reason = DeliveryDocumentRule::addBlockReason($delivery);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Void a wrong file with a reason: delivery.upload-docs AND documents.void. */
    public function voidDocument(User $actor, Delivery $delivery): Response
    {
        if (! $actor->can(self::UPLOAD_DOCS) || ! $actor->can(self::VOID_DOCS)) {
            return Response::deny();
        }

        $delivery->loadMissing('purchaseOrder');
        $reason = DeliveryDocumentRule::voidBlockReason($delivery);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Replace a wrong file (void + new file of the same type): the same people as voiding. */
    public function replaceDocument(User $actor, Delivery $delivery): Response
    {
        if (! $actor->can(self::UPLOAD_DOCS) || ! $actor->can(self::VOID_DOCS)) {
            return Response::deny();
        }

        $delivery->loadMissing('purchaseOrder');
        $reason = DeliveryDocumentRule::replaceBlockReason($delivery);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /**
     * "Mark complete". The Policy checks the person and the delivery status; WHICH files are missing is checked
     * by the Service (it names them in the message), so the button can show while files are still missing.
     */
    public function completeDocuments(User $actor, Delivery $delivery): Response
    {
        if (! $actor->can(self::UPLOAD_DOCS)) {
            return Response::deny();
        }

        $delivery->loadMissing('purchaseOrder');
        $reason = DeliveryDocumentRule::completeBlockReason($delivery, []);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** "Reopen for uploading" (reason required): complete -> for_uploading. */
    public function reopenDocuments(User $actor, Delivery $delivery): Response
    {
        if (! $actor->can(self::UPLOAD_DOCS)) {
            return Response::deny();
        }

        $delivery->loadMissing('purchaseOrder');
        $reason = DeliveryDocumentRule::reopenBlockReason($delivery);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Open ONE original file of a delivery. Admin and MMS Head (documents.view-originals) and the people who handle the files. */
    public function viewDocument(User $actor, Delivery $delivery): bool
    {
        return $actor->canAny(['documents.view-originals', self::UPLOAD_DOCS, self::ENCODE]);
    }
}
