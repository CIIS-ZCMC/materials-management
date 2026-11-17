<?php

namespace App\Support;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;

/**
 * The one place that says what may be done with a PO's documents at each PO status
 * (step 4.2b). Used by PurchaseOrderPolicy (buttons and 403 messages) and by
 * PoDocumentService (a second check inside the transaction, with the PO row locked).
 *
 *            add file   remove (delete)   move up/down   submit   open one file
 * draft         yes          yes               yes          yes        yes
 * uploaded      yes          no (void)         no           no         yes
 * escalated     yes          no (void)         yes          no         yes
 * verified+     no           no                no           no         Admin / MMS Head only
 *
 * Each method returns a plain message when the action is blocked and null when it is allowed.
 * Permissions (po.upload-files and so on) are checked in the Policy, not here.
 * Voiding a wrong document with a reason (uploaded and escalated POs) is built in step 4.3.
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