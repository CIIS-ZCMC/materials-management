<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;

/**
 * STORED status of ONE delivery (one IAR). Part 2 of the flow.
 *
 * ForUploading ... Invoice #, IAR #, Fund Cluster and Amount are entered; DR, OR or IAR still missing
 * Complete ....... DR, OR, IAR and all required supporting documents are uploaded (the completeness
 *                   gate is passed). Which supporting documents are required is still to be defined.
 * Voided ......... the delivery record was entered by mistake; its IAR number is kept and never reused
 *
 * A document that is voided without a replacement sends the delivery back
 * from Complete to ForUploading (needs a reason).
 */
enum DeliveryStatus: string implements Transitionable
{
    use HasTransitions;

    case ForUploading = 'for_uploading';
    case Complete = 'complete';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::ForUploading => 'For uploading',
            self::Complete => 'Documents complete',
            self::Voided => 'Voided',
        };
    }

    public function allowedNext(): array
    {
        return match ($this) {
            self::ForUploading => [self::Complete, self::Voided],
            self::Complete => [self::ForUploading, self::Voided],
            self::Voided => [],
        };
    }

    public function requiresReasonFor(Transitionable $to): bool
    {
        return $to === self::Voided
            || ($this === self::Complete && $to === self::ForUploading);
    }
}