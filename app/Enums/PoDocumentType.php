<?php

namespace App\Enums;

/**
 * What a PO document is (column po_documents.type, string 40: no migration for a new case).
 *
 * Steps 4.2 and 4.2b only let Procurement upload PoBundle. The others are used from
 * Phase 5 on (DR, OR, IAR, NoticeOfDelivery), Flow A (NoticeOfTermination, GuaranteeLetter)
 * and Flow B (AdvanceNoticeLetter, PullOutForm).
 *
 * NoticeOfDelivery (form ZCMC-F-MMS-42, in every real bundle) and GuaranteeLetter (seen in
 * one real bundle) were added in step 4.2b (decision 7 of the Phase 4 plan, approved).
 */
enum PoDocumentType: string
{
    case PoBundle = 'po_bundle';
    case SupplierDocumentChecklist = 'supplier_document_checklist';
    case DeliveryReceipt = 'dr';
    case OfficialReceipt = 'or';
    case Iar = 'iar';
    case NoticeOfDelivery = 'notice_of_delivery';
    case GuaranteeLetter = 'guarantee_letter';
    case NoticeOfTermination = 'notice_of_termination';
    case PullOutForm = 'pull_out_form';
    case SupportingDocument = 'supporting_document';
    case AdvanceNoticeLetter = 'advance_notice_letter';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PoBundle => 'PO Bundle',
            self::SupplierDocumentChecklist => 'Supplier Document Checklist',
            self::DeliveryReceipt => 'DR',
            self::OfficialReceipt => 'OR',
            self::Iar => 'IAR',
            self::NoticeOfDelivery => 'Notice of Delivery',
            self::GuaranteeLetter => 'Guarantee Letter',
            self::NoticeOfTermination => 'Notice of Termination',
            self::PullOutForm => 'Pull-Out Form',
            self::SupportingDocument => 'Supporting Document',
            self::AdvanceNoticeLetter => 'Advance Notice Letter (OMCC)',
            self::Other => 'Other',
        };
    }
}