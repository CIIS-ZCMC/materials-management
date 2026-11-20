<?php

namespace App\Support;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\User;

/**
 * The one place that says what may be done with a PO's documents at each PO status
 * (step 4.2b, reorder changed in step 4.2d). Used by PurchaseOrderPolicy (buttons and 403 messages)
 * and by PoDocumentService (a second check inside the transaction, with the PO row locked).
 *
 *            add file   remove (delete)   move up/down                       submit   void / replace   open one file
 * draft         yes          yes           uploader                              yes          no (remove)    yes
 * uploaded      yes          no (void)     staff                                  no          yes            yes
 * escalated     yes          no (void)     uploader                               no          yes            yes
 * verified+     no           no            nobody                                 no          no             documents.view-originals only
 *
 * Moving ("uploader" = po.upload-files, Procurement; "staff" = documents.reorder, MMS staff such as the
 * PO Reviewer) is decided by REORDER and REORDER_PERMISSIONS below: they are the ONLY two places to edit
 * if the reorder rule changes (another status for a group, or another group with its own permission).
 *
 * Opening ONE original file (PurchaseOrderPolicy::viewDocument): uploaders while the PO is open (isOpen),
 * Admin and MMS Head at any status (documents.view-originals), and MMS staff who hold documents.reorder
 * only while staffMayViewOriginals() says so (VIEW_ORIGINALS_STAFF below, step 4.2e).
 *
 * Each method returns a plain message when the action is blocked and null when it is allowed.
 * Permissions (po.upload-files, documents.void and so on) are checked in the Policy, not here, except for
 * reordering, where WHICH permission the person holds decides WHICH statuses count (reorderCapacities).
 * Every change to the documents of a PO is followed by a rebuild of the consolidated PDF
 * (PoConsolidatedPdfService).
 */
final class PoDocumentRule
{
    /** @var list<PurchaseOrderStatus> */
    private const ADD = [
        PurchaseOrderStatus::Draft,
        PurchaseOrderStatus::Uploaded,
        PurchaseOrderStatus::Escalated,
    ];

    /**
     * Moving documents up or down (step 4.2d): group name => the PO statuses where that group may do it.
     * After "verified" nobody may: the Reviewer verified a specific bundle.
     *
     * @var array<string, list<PurchaseOrderStatus>>
     */
    private const REORDER = [
        'uploader' => [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Escalated],
        'staff' => [PurchaseOrderStatus::Uploaded],
    ];

    /**
     * Which permission puts a person in which REORDER group. Someone holding both permissions
     * gets the statuses of both groups.
     *
     * @var array<string, string>
     */
    private const REORDER_PERMISSIONS = [
        'po.upload-files' => 'uploader',
        'documents.reorder' => 'staff',
    ];

    /**
     * The PO statuses where MMS staff who hold documents.reorder may also open the single ORIGINAL files
     * (step 4.2e): you cannot sort files you cannot open. Edit this list to widen or narrow it.
     *
     * @var list<PurchaseOrderStatus>
     */
    private const VIEW_ORIGINALS_STAFF = [
        PurchaseOrderStatus::Uploaded,
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

    /** MMS staff with documents.reorder may open the original files at these PO statuses (step 4.2e). */
    public static function staffMayViewOriginals(PurchaseOrder $po): bool
    {
        return in_array($po->status, self::VIEW_ORIGINALS_STAFF, true);
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

    /**
     * The REORDER groups this person belongs to, from the permissions they hold
     * (REORDER_PERMISSIONS). Empty = they may never reorder, at any status.
     *
     * @return list<string>
     */
    public static function reorderCapacities(User $actor): array
    {
        $groups = [];

        foreach (self::REORDER_PERMISSIONS as $permission => $group) {
            if ($actor->can($permission) && ! in_array($group, $groups, true)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * Move up/down: depends on WHO asks (their permissions) and on the PO status (step 4.2d).
     * The message lists only the statuses this person may use, for example "draft or escalated"
     * for Procurement and "uploaded" for MMS staff.
     */
    public static function reorderBlockReason(PurchaseOrder $po, User $actor): ?string
    {
        $groups = self::reorderCapacities($actor);

        if ($groups === []) {
            return 'You do not have permission to change the order of the documents.';
        }

        $allowed = [];

        foreach ($groups as $group) {
            foreach (self::REORDER[$group] as $status) {
                if ($status === $po->status) {
                    return null;
                }

                $allowed[$status->value] = strtolower($status->label());
            }
        }

        return 'You can change the order of the documents only while the PO is '.implode(' or ', array_values($allowed)).'.';
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