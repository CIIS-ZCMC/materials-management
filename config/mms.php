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
| uploads.*         Limits for uploaded documents (see the note on PHP limits).
| bizbox.enabled    BIZBOX_ENABLED in .env (default false). false = the app uses
|                   LocalPurchaseOrderProvider (our own database) and the BizBox
|                   provider is never created. true = the BizBox provider is used;
|                   today that is only a stub that throws "BizBox is not configured",
|                   so leave it false until Phase 13. After changing .env:
|                   php artisan config:clear
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
    ],

    /*
    | Upload limits.
    | max_size_kb is PER FILE, in kilobytes (Laravel's "max" rule uses KB).
    | PHP has its own limits that win over this number: upload_max_filesize
    | (one file) and post_max_size (the whole request). If PHP's limit is
    | smaller, PHP drops the upload before Laravel sees it and the form fails
    | with a confusing message. Keep this number at or below both, and
    | post_max_size at least (files per request x max_size_kb).
    | XAMPP usually ships 40M for both, but check with the command in the
    | step notes; the hospital server may differ.
    */
    'uploads' => [
        'max_size_kb' => (int) env('MMS_MAX_UPLOAD_KB', 10240), // 10 MB per file
        'allowed_extensions' => ['pdf'],
    ],

        'bizbox' => [
        // Read the shared switch as a real boolean ("false" in .env must mean false).
        'enabled' => filter_var(env('BIZBOX_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    ],

    'audit' => [
        'subject_types' => [
            \App\Models\User::class => 'User',
            \App\Models\Supplier::class => 'Supplier',
            \App\Models\ItemCategory::class => 'Item category',
            \App\Models\Unit::class => 'Unit',
            \App\Models\Item::class => 'Item',
            \App\Models\PurchaseOrder::class => 'Purchase order',
        ],

        'fields' => [
            'employee_no' => 'Employee no.',
            'name' => 'Name',
            'position' => 'Position',
            'email' => 'Email',
            'contact_person' => 'Contact person',
            'address' => 'Address',
            'role' => 'Role',
            'permissions' => 'Direct permissions',
            'category' => 'Category',
            'unit' => 'Unit',
            'code' => 'Short code',
            'is_equipment' => 'Equipment category',
            'status' => 'Status',
            'po_number' => 'PO number',
            'supplier' => 'Supplier',
            'po_date' => 'PO date',
            'emailed_date' => 'Emailed date',
            'delivery_term_days' => 'Delivery term (days)',
            'due_date' => 'Due date',
            'items' => 'Items',
            'schedule' => 'Schedule dates',
        ],

        'events' => [
            'created' => 'Created',
            'updated' => 'Edited',
            'activated' => 'Activated',
            'deactivated' => 'Deactivated',
            'password_reset' => 'Password reset',
            'access_changed' => 'Access changed',
            'status_changed' => 'Status changed',
            'login' => 'Login',
            'login_failed' => 'Failed login',
            'login_blocked' => 'Login blocked',
            'logout' => 'Logout',
            'session_ended' => 'Session ended',
        ],
    ],

];