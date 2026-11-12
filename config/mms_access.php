<?php

/*
|--------------------------------------------------------------------------
| MMS access rules: the single source of truth
|--------------------------------------------------------------------------
| permissions          every permission ("module.action")
| roles                default permissions of the 5 roles
| job_presets          jobs inside the MMS Staff role. Each preset lists only
|                      the job-specific permissions (the role already gives
|                      the base). The admin screen applies one to a user.
| sections             menu sections. A user may open a section if they hold
|                      ANY of its permissions. Each section becomes a Gate
|                      "section.<name>" (see AppServiceProvider), used by
|                      routes ("can:section.<name>") and by the sidebar.
| retired_permissions  old names the seeder deletes
|
| Read by RolePermissionSeeder, DummyUserSeeder, AppServiceProvider,
| HandleInertiaRequests and (step 1.2) the admin screen.
| After editing, run:
|   php artisan config:clear
|   php artisan db:seed --class=RolePermissionSeeder
*/

return [

    'permissions' => [
        // Part 1: PO distribution and verification
        'po.view',                    // see POs and their documents
        'po.create',                  // create a PO in the system (Schedule Term, Delivery Term)
        'po.edit',                    // edit PO details (Procurement; Admin for escalations)
        'po.upload-files',            // upload the PO Bundle
        'po.review',                  // select and review a PO, verify it
        'po.escalate',                // escalate wrong PO details to the System Admin
        'extension.manage',           // record and approve a supplier extension, recalculate due date
        'suppliers.view',             // see the supplier list (view only)
        'suppliers.manage',           // add, edit and deactivate suppliers (also allows viewing)

        // Part 2: delivery, receiving, IAR
        'delivery.encode',            // enter Invoice #, IAR #, Fund Cluster, Amount, signatories
        'delivery.upload-docs',       // upload DR, OR, IAR
        'documents.void',             // void or replace a wrongly uploaded document (reason required)

        // Part 3: monitoring and property documents
        'delivery.update-items',      // update delivered quantity per item and date
        'equipment.decide',           // decide whether an item is equipment
        'par.generate',               // PAR and Sub-PAR (unit price above PHP 50,000)
        'ics.generate',               // ICS (unit price at or below PHP 50,000)

        // Part 4: DV and Finance
        'dv.review-checklist',        // review the supplier document checklist
        'dv.prepare',                 // generate, edit and print the DV and IAR Transmittal
        'dv.override-incomplete',     // MMS Head: allow DV despite incomplete documents
        'dv.forward',                 // Messenger: forward the DV package to Finance
        'finance.receive',            // confirm receipt (manual "eSignature" for now)
        'finance.return',             // return the transaction with remarks
        'finance.mark-disbursed',     // Cashier: mark payment disbursed

        // Flow A: cancellation / termination
        'cancel.initiate',            // raise a Recommendation to Terminate, click "For termination"
        'cancel.finalize',            // upload the Notice of Termination, set cancelled or terminated

        // Flow B: replacement
        'replacement.initiate',       // select PO and items, mark "For Replacement"
        'replacement.notify',         // advance notice to the supplier (event only until Phase 11)
        'replacement.pullout-form',   // complete the Pull-Out Form
        'replacement.receive',        // mark replacement received, with remarks and dates

        // Flow C: delay monitoring
        'delay.view',                 // see day count and overdue indicator
        'delay.request-termination',  // request a Notice to Terminate after the grace period

        // Admin and reporting
        'users.manage',               // create, edit, deactivate users
        'roles.manage',               // assign roles, job presets and per-user permissions
        'audit.view',                 // read the audit log
        'reports.view',               // dashboard, archive search, exports
    ],

    'roles' => [
        'Admin' => [
            'po.view',
            'po.edit',
            'users.manage',
            'roles.manage',
            'audit.view',
            'reports.view',
        ],

        'Procurement' => [
            'po.view',
            'po.create',
            'po.edit',
            'po.upload-files',
            'suppliers.manage',
            'documents.void',
        ],

        // Base only. The job presets below add the rest.
        'MMS Staff' => [
            'po.view',
            'suppliers.view',
            'delay.view',
            'reports.view',
        ],

        'MMS Head' => [
            'po.view',
            'suppliers.view',
            'delay.view',
            'reports.view',
            'audit.view',
            'dv.override-incomplete',
        ],

        'Finance' => [
            'po.view',
            'suppliers.view',
            'finance.receive',
            'finance.return',
            'finance.mark-disbursed',
        ],
    ],

    'job_presets' => [
        'po-reviewer' => [
            'label' => 'PO Reviewer',
            'permissions' => [
                'po.review',
                'po.escalate',
                'extension.manage',
                'delivery.update-items',
                'delay.request-termination',
                'cancel.initiate',
                'cancel.finalize',
                'replacement.initiate',
                'replacement.notify',
                'replacement.pullout-form',
                'replacement.receive',
            ],
        ],

        'encoder' => [
            'label' => 'Encoder',
            'permissions' => [
                'delivery.encode',
                'delivery.upload-docs',
                'documents.void',
            ],
        ],

        'inventory-team' => [
            'label' => 'Inventory Team',
            'permissions' => [
                'equipment.decide',
                'par.generate',
                'ics.generate',
            ],
        ],

        'dv-processor' => [
            'label' => 'DV Processor',
            'permissions' => [
                'dv.review-checklist',
                'dv.prepare',
            ],
        ],

        'messenger' => [
            'label' => 'Messenger',
            'permissions' => [
                'dv.forward',
            ],
        ],
    ],

    // Menu sections: name => permissions (the user needs ANY one of them).
    // The Dashboard has no section: every logged-in user can open it.
    'sections' => [
        'purchase-orders' => ['po.view'],
        'suppliers' => ['suppliers.view', 'suppliers.manage'],
        'deliveries' => ['delivery.encode', 'delivery.upload-docs', 'delivery.update-items'],
        'property-documents' => ['equipment.decide', 'par.generate', 'ics.generate'],
        'dv-finance' => [
            'dv.review-checklist',
            'dv.prepare',
            'dv.override-incomplete',
            'dv.forward',
            'finance.receive',
            'finance.return',
            'finance.mark-disbursed',
        ],
        'cancellations' => ['cancel.initiate', 'cancel.finalize'],
        'replacements' => [
            'replacement.initiate',
            'replacement.notify',
            'replacement.pullout-form',
            'replacement.receive',
        ],
        'delays' => ['delay.view', 'delay.request-termination'],
        'reports' => ['reports.view'],
        'users' => ['users.manage', 'roles.manage'],
        'audit-log' => ['audit.view'],
    ],

    'retired_permissions' => [
        'iar.approve',                  // final flow has no approvals
        'cancel.approve',               // final flow has no approvals
        'iar.set-acceptance',           // IAR is prepared on paper by Receiving
        'po.generate',                  // replaced by po.create and po.edit
        'transmittal.print-iar',        // covered by dv.prepare
        'transmittal.prepare-iar-coa',  // covered by dv.prepare
        'replacement.letter',           // renamed replacement.notify
        'delay.terminate',              // covered by cancel.finalize
    ],

];