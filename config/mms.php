<?php

/*
|--------------------------------------------------------------------------
| MMS general settings (not access rules; those are in mms_access.php)
|--------------------------------------------------------------------------
| display_timezone  The app stores times in UTC (config/app.php timezone stays
|                   "UTC"). Pages show times converted to this zone.
| property.par_threshold / ics_min_value   Part 3 value limits (PHP). UNRESOLVED:
|                   the final flowchart flags that the PAR and ICS thresholds
|                   overlap. Both are provisional until MMS answers.
| iar.processing_days      Days allowed to finish IAR processing (Part 2).
| delivery.notice_days     Days before the delivery date for the automatic notice
|                   (Part 1). Recorded only until Phase 11 (no emails yet).
| delivery.grace_period_*  Calendar days after the due date before a Notice to
|                   Terminate may be requested; access then closes unless the
|                   Head of MMS reopens it (Flow C). The final flowchart says
|                   3 to 7 days. min/max hold that range; grace_period_days is
|                   the value actually used (null = not chosen yet).
| delivery.term_counting   How the Delivery Term counts days: "calendar" or
|                   "working" (working skips Sat/Sun; holidays come later).
|                   Provisional until MMS confirms.
| delivery.extension_counting  How an approved extension counts days. The
|                   flowchart says "working calendar days only", which is
|                   ambiguous; read as "working" for now, holidays to confirm.
|                   Both counting settings are used only by App\Support\DueDate.
| delivery.extension_max_days  The most days one extension may add (step 3.5,
|                   MMS_EXTENSION_MAX_DAYS in .env, default 90). A second
|                   extension may be recorded later; each one is capped.
| uploads.*         Limits for uploaded documents (see the note on PHP limits).
|                   Allowed types: pdf, jpg, jpeg, png (step 4.2). Step 4.2c: several
|                   files per request (max_files_per_request, max_request_kb).
| documents.types   Step 4.2c: the document types, ONE list, changed in this file only
|                   (no database table, no admin screen). Each entry: key (a
|                   PoDocumentType value), label (shown on screen), required (counts in
|                   the "x of y required types uploaded" indicator, which is information
|                   only and never blocks Submit), stage (po = may be chosen in the upload
|                   form; delivery and exception = not selectable there) and sort.
|                   THIS LIST IS A SAMPLE until the MMS Office sends the real checklist
|                   (step 7.1 reviews it). Required: PO Bundle (PO stage) and DR, OR, IAR
|                   (delivery stage, step 5.3: the completeness gate of a delivery).
| documents.delivery_in_pdf  Step 5.3: "on_upload" (default) or "when_complete": when delivery files join the PO's PDF.
|                   A new key needs a matching PoDocumentType case (no migration).
| pdf.qpdf_path     MMS_QPDF_PATH in .env: the ABSOLUTE path of the qpdf program
|                   (see docs/PDF-MERGE.md section 8). Used to check every
|                   uploaded PDF (step 4.2) and to build the consolidated PDF
|                   of a PO after every change to its documents (step 4.3).
|                   After changing .env: php artisan config:clear
| bizbox.enabled    BIZBOX_ENABLED in .env (default false). false = the app uses
|                   LocalPurchaseOrderProvider (our own database) and the BizBox
|                   provider is never created. true = the BizBox provider is used;
|                   today that is only a stub that throws "BizBox is not configured",
|                   so leave it false until Phase 13. After changing .env:
|                   php artisan config:clear
| print.*           Fixed text of the printed PO forms (step 4.5). Form 13 is printed
|                   by PoPrintService (step 4.6); form 04A follows in step 4.7.
|                   Nothing here is typed per PO.
|                   print.paper               "bond" (long bond, 8.5 x 13 in) or "a4".
|                                             MMS_PRINT_PAPER in .env, default bond.
|                                             Not confirmed by the office yet.
|                   print.header_13           The four header lines of form 13.
|                   print.certification_13    The Conforme text of form 13, exactly as
|                                             printed on the form (the scans spell
|                                             "confroms"; confirm with the office).
|                   print.place_of_delivery   name and address lines of form 13.
|                   print.payment_term        "Payment Term" text of form 13.
|                   print.signatories         The Medical Center Chief, newest
|                                             effective_from last. effective_from
|                                             null = in office since before this
|                                             list started. When the person changes,
|                                             ADD a new entry; do not edit the old
|                                             one, so old printouts stay explainable.
|                   print.accountant          Printed in the fund block of form 13.
|                   print.forms               Form number, revision and effectivity
|                                             date of each form (printed in the
|                                             footer or header).
|                   print.procurement_modes   The choices of "Mode of Procurement"
|                                             in the PO form. A PO stores the text,
|                                             so changing this list never changes
|                                             old POs. The Fund Cluster, Funds
|                                             Available and ORS/BURS block is not
|                                             stored; it prints blank.
|                   print.max_date_columns    The most delivery-date columns of the printed
|                                             Delivery Schedule (form 04A) and of the
|                                             quantity split on the PO form (step 3.3c).
|                                             MMS_MAX_DATE_COLUMNS in .env, default 4.
|                                             The scans show 2 plus the term column; raise
|                                             it only after a 04A print test shows it fits.
|                   Values come from the November 2026 scans (docs/PO-TEMPLATE.md);
|                   the office must confirm them.
| delivery.min_inspectors / max_inspectors  Step 5.2: how many inspectors one delivery
|                   may carry (MMS_DELIVERY_MIN_INSPECTORS, MMS_DELIVERY_MAX_INSPECTORS;
|                   default 1 and 3). The end-user is always exactly one person.
| delivery.allow_after_full  Step 6.1e: may a NEW delivery be recorded for a PO that is already fully delivered?
|                   MMS_DELIVERY_AFTER_FULL, default true = allowed, with a soft warning on the Record buttons
|                   and the form (over-delivery is the only reason to do it). Set false to refuse it (403 with
|                   the reason). Only adding is affected: editing, voiding and item entry are not. Derived each
|                   time from the totals, so a voided delivery that made the PO full frees it again.
| audit.subject_types  Model class => label shown in the Audit Log filter.
|                   Later steps add Delivery, etc. here (step 6.1: DeliveryItem).
| audit.fields      Property key => label for the "what changed" column.
| audit.events      Event name => label.
|
| Status rules (which status may follow which) are NOT here: they live in
| app/Enums (PurchaseOrderStatus, DeliveryStatus, DvStatus, ReplacementStatus).
|
| After editing this file or .env: php artisan config:clear
*/

return [

    'display_timezone' => env('MMS_DISPLAY_TIMEZONE', 'Asia/Manila'),

    'property' => [
        // Step 6.2, PROVISIONAL until MMS answers (the final flowchart flags the PAR and ICS
        // thresholds as overlapping). Default rule (PHASE-6-PLAN Q4), per UNIT price in pesos:
        //   equipment above par_threshold ................ PAR + Sub-PAR
        //   equipment from ics_min_value up to and
        //   including par_threshold ...................... ICS
        //   equipment below ics_min_value ................ neither, remarks required
        //   not equipment ................................ nothing to issue
        // Changing a number here affects NEW decisions only: every decision stores the thresholds it was made with.
        // Set 'provisional' to false when MMS has confirmed; the "provisional" notices then disappear.
        'provisional' => true,
        'par_threshold' => 50000,
        'ics_min_value' => 1000,

        // Number formats: prefix + 2-digit year + hyphen + 4-digit sequence, e.g. PAR-26-0001. The prefixes are a
        // GUESS (the real forms were not seen); change them here before the first document is issued.
        'numbering' => [
            'prefixes' => [
                'par' => 'PAR-',
                'sub_par' => 'SPAR-',
                'ics' => 'ICS-',
            ],
        ],
    ],

    'iar' => [
        'processing_days' => 3,
    ],

    'delivery' => [
        'notice_days' => 3,

        // Final flowchart: 3 to 7 calendar days after the due date.
        'grace_period_min_days' => 3,
        'grace_period_max_days' => 7,

        // The value used. Not chosen yet (one fixed number, or set per PO
        // inside the range?). Set MMS_GRACE_PERIOD_DAYS in .env once decided.
        // While null, a late PO is "Overdue" and never "Past grace period".
        'grace_period_days' => filled(env('MMS_GRACE_PERIOD_DAYS'))
            ? (int) env('MMS_GRACE_PERIOD_DAYS')
            : null,

        // "calendar" or "working". Provisional until MMS confirms.
        'term_counting' => env('MMS_DELIVERY_TERM_COUNTING', 'calendar'),

        // Flowchart: extensions count "working calendar days only".
        'extension_counting' => env('MMS_EXTENSION_COUNTING', 'working'),

        // Step 3.5: the most days a single extension may add.
        'extension_max_days' => (int) env('MMS_EXTENSION_MAX_DAYS', 90),

        // Step 5.2: how many inspectors one delivery may carry (the end-user is always exactly one).
        'min_inspectors' => (int) env('MMS_DELIVERY_MIN_INSPECTORS', 1),
        'max_inspectors' => (int) env('MMS_DELIVERY_MAX_INSPECTORS', 3),

        // Step 6.1d: how many centavos the typed Amount of a delivery may differ from the value of its quantities
        // (quantity x unit price) and still count as "matches"; also the margin before "deliveries add up to more than
        // the PO total" is warned. 0 = exact to the centavo. Provisional: MMS has not said what the Amount is.
        // Set MMS_AMOUNT_TOLERANCE_CENTS in .env once decided (500 = PHP 5.00). A check only, never a block.
        'amount_tolerance_cents' => (int) env('MMS_AMOUNT_TOLERANCE_CENTS', 0),

        // Step 6.1e: may a new delivery be recorded after the PO is fully delivered? true (default) = yes, with a soft
        // warning; false = refused. Provisional: MMS has not answered whether the supplier may deliver more after full
        // (over-delivery) or whether that gets a new IAR. Set MMS_DELIVERY_AFTER_FULL=false in .env to block it.
        'allow_after_full' => (bool) env('MMS_DELIVERY_AFTER_FULL', true),
    ],

    /*
    | Upload limits.
    | max_size_kb is PER FILE, in kilobytes (Laravel's "max" rule uses KB).
    | PHP has its own limits that win over this number: upload_max_filesize
    | (one file) and post_max_size (the whole request). If PHP's limit is
    | smaller, PHP drops the upload before Laravel sees it and the form fails
    | with a confusing message. Keep this number at or below both, and
    | post_max_size at least (files per request x max_size_kb).
    | Set upload_max_filesize = 40M and post_max_size = 50M in the php.ini that
    | "php --ini" shows (the hospital server may differ).
    |
    | Step 4.2c: one upload form may send several files (all types together).
    | max_files_per_request is the most files in one request. max_request_kb is the
    | most TOTAL size of one request in KB (default 45 MB = 46080). Keep it BELOW
    | post_max_size (50M): the files travel in the same request as the form fields,
    | and a request over post_max_size is dropped by PHP before Laravel can give a
    | plain message. With 10 MB per file the total is the number that matters: 4 files
    | of 10 MB fit, 5 do not. PHP also has max_file_uploads (default 20): keep
    | max_files_per_request at or below it. The upload form and the Form Request both
    | check these two numbers first.
    */
    'uploads' => [
        'max_size_kb' => (int) env('MMS_MAX_UPLOAD_KB', 10240), // 10 MB per file
        'allowed_extensions' => ['pdf', 'jpg', 'jpeg', 'png'],
        'max_files_per_request' => (int) env('MMS_MAX_FILES_PER_REQUEST', 10),
        'max_request_kb' => (int) env('MMS_MAX_REQUEST_KB', 46080), // 45 MB in total, below post_max_size 50M
    ],

    'pdf' => [
        // Absolute path, for example C:\tools\qpdf\bin\qpdf.exe or /usr/bin/qpdf.
        'qpdf_path' => env('MMS_QPDF_PATH'),
    ],

    /*
    | Document types and checklists (step 4.2c, extended in step 5.3). Replace this list (this one place)
    | when the MMS Office sends the real checklist. See the note at the top of this file.
    |
    | stage     where the type may be chosen: "po" (PO upload form), "delivery" (the documents panel of a
    |           delivery) or "exception" (not selectable yet). A type may list SEVERAL stages, for example
    |           ['po', 'delivery'] for Supporting Document.
    | required  true = counts in the checklist of every stage the type is listed in. For a PO the indicator is
    |           information only; for a DELIVERY it is the completeness gate: the delivery cannot become
    |           "Documents complete" until every required delivery type has an active file.
    |           MMS has not defined the required supporting documents yet: today DR, OR and IAR only.
    |           To require another type, set required => true on its line; to drop one, set false. No code change.
    |
    | delivery_in_pdf  When a delivery's files join the PO's combined PDF (step 5.3):
    |           "on_upload"     (default) every active delivery file is in the PDF as soon as it is uploaded,
    |                           unless its delivery is voided.
    |           "when_complete" a delivery's files join the PDF only while the delivery is "Documents complete".
    |           After changing it, press "Rebuild combined PDF" on each PO (or just wait for its next change).
    | delivery_sort     Order of the types INSIDE one delivery in the combined PDF is the "sort" number below.
    */
    'documents' => [
        'checklist_is_sample' => true,

        'delivery_in_pdf' => env('MMS_DELIVERY_IN_PDF', 'on_upload'),

        'types' => [
            ['key' => 'po_bundle', 'label' => 'PO Bundle', 'required' => true, 'stage' => 'po', 'sort' => 10],
            ['key' => 'supplier_document_checklist', 'label' => 'Supplier Document Checklist', 'required' => false, 'stage' => 'po', 'sort' => 20],
            ['key' => 'guarantee_letter', 'label' => 'Guarantee Letter', 'required' => false, 'stage' => 'po', 'sort' => 30],
            ['key' => 'supporting_document', 'label' => 'Supporting Document', 'required' => false, 'stage' => ['po', 'delivery'], 'sort' => 40],
            ['key' => 'other', 'label' => 'Others', 'required' => false, 'stage' => 'po', 'sort' => 99],

            // Delivery documents (step 5.3). DR, OR and IAR are required; the rest are optional.
            ['key' => 'notice_of_delivery', 'label' => 'Notice of Delivery', 'required' => false, 'stage' => 'delivery', 'sort' => 110],
            ['key' => 'dr', 'label' => 'DR', 'required' => true, 'stage' => 'delivery', 'sort' => 120],
            ['key' => 'iar', 'label' => 'IAR', 'required' => true, 'stage' => 'delivery', 'sort' => 130],
            ['key' => 'or', 'label' => 'OR', 'required' => true, 'stage' => 'delivery', 'sort' => 140],

            // Advance Notice Letter: filed under Flow B in the flowchart; treated as an optional delivery
            // document for now (MMS to confirm). Move it back to 'exception' here if MMS says so.
            ['key' => 'advance_notice_letter', 'label' => 'Advance Notice Letter (OMCC)', 'required' => false, 'stage' => 'delivery', 'sort' => 150],

            // Flow A documents.
            ['key' => 'notice_of_termination', 'label' => 'Notice of Termination', 'required' => false, 'stage' => 'exception', 'sort' => 210],
            ['key' => 'pull_out_form', 'label' => 'Pull-Out Form', 'required' => false, 'stage' => 'exception', 'sort' => 220],
        ],
    ],

    'bizbox' => [
        // Read the shared switch as a real boolean ("false" in .env must mean false).
        'enabled' => filter_var(env('BIZBOX_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'print' => [
        // Step 4.6. Set MMS_PRINT_PAPER in .env to "a4" if the office prints on A4. After changing .env: php artisan config:clear
        'paper' => env('MMS_PRINT_PAPER', 'bond'),

        'header_13' => [
            'Republic of the Philippines',
            'Department of Health',
            'ZAMBOANGA CITY MEDICAL CENTER',
            'Dr. D. Evangelista St., Sta. Catalina, Zamboanga City, 7000',
        ],

        'certification_13' => 'I hereby certify that I am authorized to receive this document and that the company confroms to the Terms and Conditions set forth on the final page of this document.',

        'place_of_delivery' => [
            'name' => 'Zamboanga City Medical Center',
            'address' => 'Dr. Evangelista Street Sta. Catalina Zamboanga City Zamboanga Del Sur Philippines 7000',
        ],

        'payment_term' => 'Refer to Terms and Conditions',

        // The Medical Center Chief who signs the PO. Add a new entry when the person changes.
        'signatories' => [
            [
                'name' => 'AFDAL B. KUNTING, MD, MPH, FPCP',
                'title' => 'Medical Center Chief II',
                'effective_from' => null, // start date not known yet (name as printed on the Nov 2026 scans)
            ],
        ],

        'accountant' => [
            'name' => 'FRITZIE LYNN T. CABILIN',
            'title' => 'Accountant IV - Accounting Section',
        ],

        'forms' => [
            '13' => [
                'number' => 'ZCMC-F-PROC-13',
                'revision' => '12',
                'effectivity_date' => '2025-02-24',
            ],
            '04A' => [
                'number' => 'ZCMC-F-PROC-04A',
                'revision' => '0',
                'effectivity_date' => '2026-05-25',
            ],
        ],

        // Step 3.3c: the most date columns on form 04A (and in the PO form's quantity split).
        'max_date_columns' => (int) env('MMS_MAX_DATE_COLUMNS', 4),

        // The three values seen on the scans. Procurement confirms the full list.
        'procurement_modes' => [
            'Competitive/Public Bidding',
            'Nego. Proc. - Emergency Cases',
            'Nego. Proc. - Small Value Procurement',
        ],
    ],

    'audit' => [
        'subject_types' => [
            \App\Models\User::class => 'User',
            \App\Models\Supplier::class => 'Supplier',
            \App\Models\ItemCategory::class => 'Item category',
            \App\Models\Unit::class => 'Unit',
            \App\Models\Item::class => 'Item',
            \App\Models\PurchaseOrder::class => 'Purchase order',
            \App\Models\IarSeries::class => 'IAR series',
            \App\Models\IarNumber::class => 'IAR number',
            \App\Models\Delivery::class => 'Delivery',
            \App\Models\DeliveryItem::class => 'Delivered item',
            \App\Models\PropertySeries::class => 'Property series',
            \App\Models\PropertyDecision::class => 'Property decision',
            \App\Models\PropertyDocument::class => 'Property document',
        ],

        'fields' => [
            'employee_no' => 'Employee no.',
            'name' => 'Name',
            'position' => 'Position',
            'email' => 'Email',
            'contact_person' => 'Contact person',
            'address' => 'Address',
            'tin' => 'TIN',
            'contact_no' => 'Contact no.',
            'supply_chain_role' => 'Supply chain role',
            'role' => 'Role',
            'permissions' => 'Direct permissions',
            'category' => 'Category',
            'unit' => 'Unit',
            'code' => 'Short code',
            'stock_no' => 'Stock/Property no.',
            'is_equipment' => 'Equipment category',
            'status' => 'Status',
            'po_number' => 'PO number',
            'supplier' => 'Supplier',
            'po_date' => 'PO date',
            'emailed_date' => 'Emailed date',
            'delivery_term_days' => 'Delivery term (days)',
            'due_date' => 'Due date',
            'pr_no' => 'P.R. no.',
            'caf_no' => 'CAF no.',
            'mode_of_procurement' => 'Mode of procurement',
            'remarks' => 'Free-text line',
            'items' => 'Items',
            'schedule' => 'Schedule dates',
            'quantities' => 'Delivery quantities',
            'extension' => 'Extension',
            'documents' => 'Documents',
            'next_number' => 'Next IAR number',
            'iar_number' => 'IAR number',
            'series' => 'Series',
            'purchase_order' => 'PO',
            'invoice_no' => 'Invoice no.',
            'iar_series' => 'IAR series',
            'fund_cluster' => 'Fund cluster',
            'amount' => 'Amount',
            'date_received' => 'Date received',
            'inspectors' => 'Inspectors',
            'end_user' => 'End-user',
            'delivered_items' => 'Delivered items',
            'over_delivery' => 'Over-delivery',
            'item' => 'Item',
            'quantity' => 'Quantity',
            'delivered_total_after' => 'Total delivered after',
            'equipment' => 'Equipment',
            'category_default' => 'Category says equipment',
            'rule_band' => 'Rule result',
            'unit_price' => 'Unit price',
            'document' => 'Document',
            'document_number' => 'Number',
            'sub_par_user' => 'Sub-PAR user',
        ],

        'events' => [
            'created' => 'Created',
            'updated' => 'Edited',
            'activated' => 'Activated',
            'deactivated' => 'Deactivated',
            'password_reset' => 'Password reset',
            'access_changed' => 'Access changed',
            'status_changed' => 'Status changed',
            'po_printed' => 'PO printed',
            'extension_recorded' => 'Extension recorded',
            'iar_window_exceeded' => 'IAR window exceeded',
            'delivery_items_recorded' => 'Delivered items recorded',
            'delivery_items_voided' => 'Delivered items voided with the delivery',
            'document_uploaded' => 'Document uploaded',
            'document_removed' => 'Document removed',
            'documents_reordered' => 'Documents reordered',
            'document_voided' => 'Document voided',
            'document_replaced' => 'Document replaced',
            'iar_number_reserved' => 'IAR number reserved',
            'iar_number_voided' => 'IAR number voided',
            'iar_counter_set' => 'IAR counter set',
            'property_decided' => 'Equipment decision recorded',
            'property_document_issued' => 'Property document issued',
            'property_counter_set' => 'Property counter set',
            'login' => 'Login',
            'login_failed' => 'Failed login',
            'login_blocked' => 'Login blocked',
            'logout' => 'Logout',
            'session_ended' => 'Session ended',
        ],
    ],

];