<?php

namespace App\Enums;

use App\Support\PoDocumentChecklist;

/**
 * What a PO document is (column po_documents.type, string 40: no migration for a new case).
 *
 * Steps 4.2 and 4.2b only let Procurement upload PoBundle. The others are used from
 * Phase 5 on (DR, OR, IAR, NoticeOfDelivery), Flow A (NoticeOfTermination, GuaranteeLetter)
 * and Flow B (AdvanceNoticeLetter, PullOutForm).
 *
 * NoticeOfDelivery (form ZCMC-F-MMS-42, in every real bundle) and GuaranteeLetter (seen in
 * one real bundle) were added in step 4.2b (decision 7 of the Phase 4 plan, approved).
 *
 * Step 4.2c: this enum stays the list of strings the database may hold. Which types the upload form
 * offers, which are required and the labels shown on screen come from config/mms.php
 * ("documents.types", a SAMPLE list read through App\Support\PoDocumentChecklist). label() asks that
 * list first and falls back to the text below, so a label is edited in one place.
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
        return PoDocumentChecklist::label($this->value) ?? match ($this) {
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