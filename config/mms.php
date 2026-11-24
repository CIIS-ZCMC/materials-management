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
|                   (steps 5.3 and 7.1 review it). Only "PO Bundle" is required for now.
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
| audit.subject_types  Model class => label shown in the Audit Log filter.
|                   Later steps add Delivery, etc. here.
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
        // UNRESOLVED (flagged in the final flowchart): the briefing says strictly
        // above 50,000 = PAR + Sub-PAR and at or below = ICS; the flowchart says
        // PAR is mandatory for equipment and ICS applies from 1,000, below
        // 1,000 neither applies (remarks required). Step 6.2 waits for MMS.
        'par_threshold' => 50000,
        'ics_min_value' => 1000,
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
    | Step 4.2c: SAMPLE list of document types and checklist. Replace it (this one place)
    | when the MMS Office sends the real checklist. See the note at the top of this file.
    | stage: po = in the upload dropdown; delivery / exception = not selectable there.
    */
    'documents' => [
        'checklist_is_sample' => true,

        'types' => [
            ['key' => 'po_bundle', 'label' => 'PO Bundle', 'required' => true, 'stage' => 'po', 'sort' => 10],
            ['key' => 'supplier_document_checklist', 'label' => 'Supplier Document Checklist', 'required' => false, 'stage' => 'po', 'sort' => 20],
            ['key' => 'guarantee_letter', 'label' => 'Guarantee Letter', 'required' => false, 'stage' => 'po', 'sort' => 30],
            ['key' => 'supporting_document', 'label' => 'Supporting Document', 'required' => false, 'stage' => 'po', 'sort' => 40],
            ['key' => 'other', 'label' => 'Others', 'required' => false, 'stage' => 'po', 'sort' => 99],

            // Delivery documents (Phase 5): listed so the filter shows their names; not selectable in this step.
            ['key' => 'notice_of_delivery', 'label' => 'Notice of Delivery', 'required' => false, 'stage' => 'delivery', 'sort' => 110],
            ['key' => 'dr', 'label' => 'DR', 'required' => false, 'stage' => 'delivery', 'sort' => 120],
            ['key' => 'iar', 'label' => 'IAR', 'required' => false, 'stage' => 'delivery', 'sort' => 130],
            ['key' => 'or', 'label' => 'OR', 'required' => false, 'stage' => 'delivery', 'sort' => 140],

            // Flow A and Flow B documents.
            ['key' => 'notice_of_termination', 'label' => 'Notice of Termination', 'required' => false, 'stage' => 'exception', 'sort' => 210],
            ['key' => 'pull_out_form', 'label' => 'Pull-Out Form', 'required' => false, 'stage' => 'exception', 'sort' => 220],
            ['key' => 'advance_notice_letter', 'label' => 'Advance Notice Letter (OMCC)', 'required' => false, 'stage' => 'exception', 'sort' => 230],
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
            'document_uploaded' => 'Document uploaded',
            'document_removed' => 'Document removed',
            'documents_reordered' => 'Documents reordered',
            'document_voided' => 'Document voided',
            'document_replaced' => 'Document replaced',
            'iar_number_reserved' => 'IAR number reserved',
            'iar_number_voided' => 'IAR number voided',
            'iar_counter_set' => 'IAR counter set',
            'login' => 'Login',
            'login_failed' => 'Failed login',
            'login_blocked' => 'Login blocked',
            'logout' => 'Logout',
            'session_ended' => 'Session ended',
        ],
    ],

];