<?php

namespace App\Policies;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Support\ExtensionRule;
use App\Support\PoDocumentRule;
use App\Support\PoEditRule;
use App\Support\PoReviewRule;
use Illuminate\Auth\Access\Response;

/**
 * po.view          see the PO list and a PO's details
 * po.create        create a PO in the system
 * po.edit          edit a PO, only while PoEditRule allows it (draft, uploaded, escalated);
 *                  also sends an escalated PO back to the Reviewer (PoReviewRule::sendBackBlockReason)
 * extension.manage record an extension, only while ExtensionRule allows it (verified, not closed, has a due date)
 * po.review        verify a PO, only while PoReviewRule allows it (uploaded)
 * po.escalate      escalate a PO to the Admin, only while PoReviewRule allows it (uploaded)
 * po.upload-files  the PO Bundle (step 4.2b): upload, submit to the Reviewer, remove and reorder,
 *                  each only while PoDocumentRule allows it
 * documents.void  voids or replaces a wrong document with a reason (step 4.3), only while PoDocumentRule
 *                  allows it (uploaded, escalated). The PO Bundle also needs po.upload-files (Procurement);
 *                  when Phase 5 adds DR/OR/IAR the Encoder's own rule is decided there.
 * documents.view-originals  opens any single (original) document file at any status (Admin and MMS Head)
 * po.view          opens the consolidated PDF (a draft's only for people who handle the files)
 * Auto-discovered: App\Models\PurchaseOrder -> App\Policies\PurchaseOrderPolicy.
 */
class PurchaseOrderPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('po.view');
    }

    public function view(User $actor, PurchaseOrder $purchaseOrder): bool
    {
        return $actor->can('po.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('po.create');
    }

    /** Returns a Response so a locked PO gives a clear message with the 403. */
    public function update(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.edit')) {
            return Response::deny();
        }

        $reason = PoEditRule::blockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Record an extension (not an edit: PoEditRule does not apply). Drives the "Record extension" button. */
    public function extend(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('extension.manage')) {
            return Response::deny();
        }

        $reason = ExtensionRule::blockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Verify the PO (step 3.6). PO Reviewer job only; the Admin does not verify. */
    public function verify(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.review')) {
            return Response::deny();
        }

        $reason = PoReviewRule::verifyBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Escalate the PO to the System Admin with remarks (step 3.6). */
    public function escalate(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.escalate')) {
            return Response::deny();
        }

        $reason = PoReviewRule::escalateBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** "Send back to Reviewer" (step 3.6): the Admin, with po.edit, after fixing an escalated PO. */
    public function sendBack(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.edit')) {
            return Response::deny();
        }

        $reason = PoReviewRule::sendBackBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** The catalog search box on the PO form. */
    public function searchItems(User $actor): bool
    {
        return $actor->can('po.create') || $actor->can('po.edit');
    }

    /** Add a file to the PO Bundle (step 4.2b): po.upload-files, and draft, uploaded or escalated. */
    public function uploadDocument(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.upload-files')) {
            return Response::deny();
        }

        $reason = PoDocumentRule::addBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** "Submit to Reviewer": draft -> uploaded. The Service also needs at least one active document. */
    public function submitDocuments(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.upload-files')) {
            return Response::deny();
        }

        $reason = PoDocumentRule::submitBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Remove (delete) a file: draft only. Later, a wrong document is voided with a reason (step 4.3). */
    public function removeDocument(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.upload-files')) {
            return Response::deny();
        }

        $reason = PoDocumentRule::removeBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Move a file up or down: draft and escalated. */
    public function reorderDocuments(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('po.upload-files')) {
            return Response::deny();
        }

        $reason = PoDocumentRule::reorderBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Void a wrong document with a reason (step 4.3): documents.void + po.upload-files, uploaded or escalated. */
    public function voidDocument(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('documents.void') || ! $actor->can('po.upload-files')) {
            return Response::deny();
        }

        $reason = PoDocumentRule::voidBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /** Replace a wrong document (void + new file in its place): same people and statuses as voiding. */
    public function replaceDocument(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if (! $actor->can('documents.void') || ! $actor->can('po.upload-files')) {
            return Response::deny();
        }

        $reason = PoDocumentRule::replaceBlockReason($purchaseOrder);

        return $reason === null ? Response::allow() : Response::deny($reason);
    }

    /**
     * Open the consolidated PDF (inline viewer, download). Everyone who may see the PO (po.view) may open it,
     * except that a DRAFT's bundle is for the people who handle the files: the PO Reviewer sees it only after
     * "Submit to Reviewer".
     */
    public function viewConsolidated(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if ($purchaseOrder->status === PurchaseOrderStatus::Draft) {
            return $actor->canAny(['po.upload-files', 'documents.view-originals']) ? Response::allow() : Response::deny();
        }

        return $actor->can('po.view') ? Response::allow() : Response::deny();
    }

    /** "Rebuild combined PDF" (retry after a failed rebuild). Harmless and repeatable, so any status. */
    public function rebuildConsolidated(User $actor, PurchaseOrder $purchaseOrder): bool
    {
        return $actor->canAny(['po.upload-files', 'documents.void', 'documents.view-originals']);
    }

    /**
     * Open one ORIGINAL document file (the "Original" button). Uploaders may open files while the PO is draft,
     * uploaded or escalated. Admin and MMS Head (documents.view-originals) may open them at any status.
     * Everyone else works with the consolidated PDF only.
     */
    public function viewDocument(User $actor, PurchaseOrder $purchaseOrder): Response
    {
        if ($actor->can('documents.view-originals')) {
            return Response::allow();
        }

        if ($actor->can('po.upload-files') && PoDocumentRule::isOpen($purchaseOrder)) {
            return Response::allow();
        }

        return Response::deny();
    }
}