<?php

namespace App\Enums;

use App\Enums\Concerns\HasTransitions;

/**
 * STORED status of a Disbursement Voucher (Part 4).
 *
 * Draft ................ DV and IAR Transmittal generated; editable until printed
 * ForFinanceProcessing . printed; files go to COA; MMS Staff (Messenger job) forwards it to Finance
 * ReceivedByFinance .... receipt confirmed with signature and photo documentation (subject to DPU
 *                        clearance); built as a manual confirm for now
 * Returned ............. Finance sent it back with remarks; MMS corrects it (back to Draft)
 * PaymentDisbursed ..... Cashier marked it paid; final
 *
 * Finance's own steps (return with remarks, payment disbursed) are not restated in the final
 * flowchart: carried over from the briefing and flagged. ReceivedByFinance and Returned may
 * change after Finance's answer.
 */
enum DvStatus: string implements Transitionable
{
    use HasTransitions;

    case Draft = 'draft';
    case ForFinanceProcessing = 'for_finance_processing';
    case ReceivedByFinance = 'received_by_finance';
    case Returned = 'returned';
    case PaymentDisbursed = 'payment_disbursed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::ForFinanceProcessing => 'For Finance Processing',
            self::ReceivedByFinance => 'Received by Finance',
            self::Returned => 'Returned by Finance',
            self::PaymentDisbursed => 'Payment disbursed',
        };
    }

    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::ForFinanceProcessing],
            self::ForFinanceProcessing => [self::ReceivedByFinance],
            self::ReceivedByFinance => [self::PaymentDisbursed, self::Returned],
            self::Returned => [self::Draft],
            self::PaymentDisbursed => [],
        };
    }

    public function requiresReasonFor(Transitionable $to): bool
    {
        // Finance must say what is needed ("returned with remarks").
        return $to === self::Returned;
    }
}