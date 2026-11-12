<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;

/**
 * STORED lifecycle of a Purchase Order (column purchase_orders.status).
 *
 * Delivery progress (not delivered / partial / full), overdue, DV and payment
 * are NOT here: they are computed or live on their own records. See
 * DeliveryProgress, DeliveryTiming, DeliveryStatus and DvStatus.
 *
 * Draft ................. PO created (or read from BizBox), PO Bundle not uploaded yet
 * Uploaded .............. PO Bundle attached, waiting for the PO Reviewer
 * Escalated ............. Reviewer found wrong details and sent it to the Admin
 * Verified .............. Reviewer confirmed the details; deliveries, DV and Flow B/C run from here
 * ForTerminationApproval  Flow A: Recommendation to Terminate raised and verified by MMS,
 *                         waiting for the approving authority (HOPE / Head / MCC)
 * ForTermination ........ Flow A: approved; Legal is asked for the Notice of Termination
 * Cancelled / Terminated  final (Flow A)
 *
 * Disapproval sends ForTerminationApproval back to Verified (reason required).
 * The order "MMS verifies, then the approving authority decides" is read from
 * the final flowchart and still to be confirmed.
 */
enum PurchaseOrderStatus: string implements Transitionable
{
    use HasTransitions;

    case Draft = 'draft';
    case Uploaded = 'uploaded';
    case Escalated = 'escalated';
    case Verified = 'verified';
    case ForTerminationApproval = 'for_termination_approval';
    case ForTermination = 'for_termination';
    case Cancelled = 'cancelled';
    case Terminated = 'terminated';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Uploaded => 'Uploaded',
            self::Escalated => 'Escalated to Admin',
            self::Verified => 'Verified',
            self::ForTerminationApproval => 'For termination approval',
            self::ForTermination => 'For termination',
            self::Cancelled => 'Cancelled',
            self::Terminated => 'Terminated',
        };
    }

    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Uploaded],
            self::Uploaded => [self::Verified, self::Escalated],
            self::Escalated => [self::Uploaded],
            self::Verified => [self::ForTerminationApproval],
            self::ForTerminationApproval => [self::ForTermination, self::Verified],
            self::ForTermination => [self::Cancelled, self::Terminated],
            self::Cancelled, self::Terminated => [],
        };
    }

    public function requiresReasonFor(Transitionable $to): bool
    {
        return in_array($to, [self::Escalated, self::ForTerminationApproval, self::Cancelled, self::Terminated], true)
            || ($this === self::ForTerminationApproval && $to === self::Verified); // disapproval
    }
}