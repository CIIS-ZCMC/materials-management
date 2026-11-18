<?php

namespace App\Support;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;

/**
 * The one place that says what may be done with a PO's documents at each PO status
 * (step 4.2b). Used by PurchaseOrderPolicy (buttons and 403 messages) and by
 * PoDocumentService (a second check inside the transaction, with the PO row locked).
 *
 *            add file   remove (delete)   move up/down   submit   void / replace   open one file
 * draft         yes          yes               yes          yes          no (remove)    yes
 * uploaded      yes          no (void)         no           no           yes            yes
 * escalated     yes          no (void)         yes          no           yes            yes
 * verified+     no           no                no           no           no             documents.view-originals only
 *
 * Each method returns a plain message when the action is blocked and null when it is allowed.
 * Permissions (po.upload-files, documents.void and so on) are checked in the Policy, not here.
 * Void and replace (a wrong document, with a reason) are built in step 4.3. Every change to the
 * documents of a PO is followed by a rebuild of the consolidated PDF (PoConsolidatedPdfService).
 */
final class PoDocumentRule
{
    /** @var list<PurchaseOrderStatus> */
    private const ADD = [
        PurchaseOrderStatus::Draft,
        PurchaseOrderStatus::Uploaded,
        PurchaseOrderStatus::Escalated,
    ];

    /** @var list<PurchaseOrderStatus> */
    private const REORDER = [
        PurchaseOrderStatus::Draft,
        PurchaseOrderStatus::Escalated,
    ];

    /** @var list<PurchaseOrderStatus> */
    private const VOID = [
        PurchaseOrderStatus::Uploaded,
        PurchaseOrderStatus::Escalated,
    ];

    /** Documents may be added, and (for people who may upload) opened one by one. */
    public static function isOpen(PurchaseOrder $po): bool
    {
        return in_array($po->status, self::ADD, true);
    }

    public static function addBlockReason(PurchaseOrder $po): ?string
    {
        if (self::isOpen($po)) {
            return null;
        }

        return 'Documents can no longer be added to a PO that is "'.strtolower($po->status->label()).'". '
            .'Only draft, uploaded and escalated POs accept new documents.';
    }

    /** A real delete: draft only. After that a wrong document is voided with a reason (step 4.3). */
    public static function removeBlockReason(PurchaseOrder $po): ?string
    {
        if ($po->status === PurchaseOrderStatus::Draft) {
            return null;
        }

        return 'A file can be removed only while the PO is a draft. After that, a wrong document is voided with a reason.';
    }

    /**
     * Void a wrong document with a reason (step 4.3): uploaded and escalated POs only.
     * In a draft the file is simply removed; from verified on the bundle the Reviewer checked is locked.
     */
    public static function voidBlockReason(PurchaseOrder $po): ?string
    {
        if (in_array($po->status, self::VOID, true)) {
            return null;
        }

        if ($po->status === PurchaseOrderStatus::Draft) {
            return 'In a draft PO a wrong file is removed, not voided.';
        }

        return 'A document can no longer be voided or replaced once the PO is "'.strtolower($po->status->label()).'".';
    }

    /** Replace = void one document and put the new file in its place; the same statuses as voiding. */
    public static function replaceBlockReason(PurchaseOrder $po): ?string
    {
        return self::voidBlockReason($po);
    }

    public static function reorderBlockReason(PurchaseOrder $po): ?string
    {
        if (in_array($po->status, self::REORDER, true)) {
            return null;
        }

        return 'The order of the documents can be changed only while the PO is a draft or escalated.';
    }

    /** Draft -> uploaded. The caller also checks that the PO has at least one active document. */
    public static function submitBlockReason(PurchaseOrder $po): ?string
    {
        if ($po->status === PurchaseOrderStatus::Draft) {
            return null;
        }

        return 'Only a draft PO can be submitted to the PO Reviewer.';
    }
}