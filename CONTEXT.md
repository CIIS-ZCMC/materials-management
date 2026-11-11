# CONTEXT: MMS Procurement Monitoring System

Attach this file (with PROGRESS.md) to every build session. Last updated: 2026-11-11.

## Project
- Monitors a Purchase Order (PO) from issuance to payment for the Materials Management Section (MMS), Zamboanga City Medical Center (ZCMC). Final flow: "MMS PO to DV Briefing" (4 parts + 3 exception flows).
- Owner: Vvv, the only IT person in MMS, new to React. Under 20 users. Hospital IT deploys it later on their own server (MySQL). They use Laravel.
- Dev machine: Windows, PowerShell, XAMPP (MySQL/phpMyAdmin), folder `MaterialManagementSystem`, database `material_management_system`, run with `composer dev` at http://127.0.0.1:8000.

## Stack and conventions
- Laravel + React starter kit: Inertia, TypeScript (`.tsx`), Tailwind, shadcn-style components in `resources/js/components/ui`, Fortify auth, generated route helpers imported from `@/routes/...`.
- `app/Models/User.php` uses the attribute style `#[Fillable([...])]` and `#[Hidden([...])]` (not `$fillable`). The kit has a repository pattern (`UserRepositoryInterface`).
- Migration files must be the full `return new class extends Migration { ... };` file, not just `up()`/`down()`.
- Roles/permissions: `spatie/laravel-permission` (teams off). Permission names are `module.action` (e.g. `po.review`). Check permissions with Gates/Policies and middleware, never `if ($user->role === ...)`. Also enforce on the server, not only by hiding buttons.
- Frontend access: Laravel shares `auth.permissions` and `auth.sections` on every page; React reads them with the `useAccess()` hook (`can('po.review')`, `canAccess('deliveries')`). Hiding is for convenience only; the server is the real protection.
- Page layout: a page sets `Page.layout = { breadcrumbs: [...] }` (as in `dashboard.tsx`). Placeholder pages use the `ComingSoon` component.
- Login: employee number (`users.employee_no`) + password. Registration and password reset are turned off; the admin creates accounts. Deactivated users (`is_active = false`) are blocked. `last_login_at` is recorded.
- Dummy logins (password `password`): DUMMY-0001 Admin, DUMMY-0002 Procurement, DUMMY-0003 PO Reviewer, DUMMY-0004 Encoder, DUMMY-0005 Inventory Team, DUMMY-0006 MMS Head, DUMMY-0007 Finance, DUMMY-0008 DV Processor, DUMMY-0009 Messenger (0003-0005, 0008, 0009 have the MMS Staff role plus a job preset). Seeders: `RolePermissionSeeder` (structural, safe in production), `DummyUserSeeder` (refuses to run in production).
- Logo: `public/images/zcmc.png`. Brand colors: green `#0f8a3e` / `#0b5a2b`, yellow `#f5e400`.
- Debugging tip: when a button does nothing, check the browser console (F12) first. A stale import (e.g. `@/routes/password`) once broke a whole page.
- After adding routes or changing `config/mms_access.php`: `php artisan config:clear`, `php artisan route:clear`, `php artisan wayfinder:generate`, then `npm run build` (it also catches broken imports).

## UI conventions (apply to every step)
- File placement: React pages go in `resources/js/pages/...` (Inertia resolves them from there); reusable components go in `resources/js/components/...`. Mixing them up gives a page that silently does nothing. After adding a new page file, restart `npm run dev` (or run `npm run build`).
- Confirmations are modals, never `window.confirm`: use `@/components/admin/confirm-dialog` (`ConfirmDialog`).
- Success messages are toasts: the controller redirects with `->with('success', '...')`, `HandleInertiaRequests` shares it as `flash.success`, and `useFlashToast()` (`@/components/admin/use-flash-toast`) shows it. The single `<Toaster />` is mounted in `resources/js/layouts/app-layout.tsx`; do not mount a second one. Server rule errors (for example a blocked action) come back as validation errors and are shown as a toast or under the field.
- Forms use Inertia `useForm` with a Form Request on the server; authorization is in a Policy; business rules and guards live in a Service (Controller -> Service -> Repository for core modules).
- List pages are responsive: a normal table from the `md` breakpoint up; below `md` each row becomes an expandable card (collapsed: main identifier, status badge, one key field; expanded: the other fields and the row actions). No horizontal scrolling on phones. Build it once as a reusable component (planned in step 1.2b) and reuse it for POs, suppliers, deliveries, audit log, and so on.
- Search on list pages is live: debounced (about 300 ms), server-side, no Enter key, no page reload, with a loading state and a clear (X) button. A new search goes back to page 1, pagination keeps the search, and `?search=` stays in the URL.
- The admin user pages use plain URLs (`/admin/users/...`) instead of generated route helpers. Either style is fine; never import a helper that no longer exists.

## Roles (5) and the final flow
Roles: Admin, Procurement, MMS Staff, MMS Head, Finance. MMS Staff covers several jobs through per-user permissions: PO Reviewer, Encoder, Inventory Team, DV Processor, Messenger.

Access rules live in one file, `config/mms_access.php`: the permission list, the role defaults, the job presets, the menu sections, and retired permission names. `RolePermissionSeeder`, `DummyUserSeeder`, `AppServiceProvider`, `HandleInertiaRequests` and the admin screen read it. The MMS Staff role itself only carries a base (`po.view`, `delay.view`, `reports.view`). A job preset lists only the job-specific permissions, and the admin screen applies it to a user with direct permissions (`syncPermissions()`). A user may hold more than one job. Receiving, Supplier, Legal, COA and UMIS have no login.

- **Part 1, Steps 1-2: PO distribution and verification.** Procurement either creates the PO in the system (sets Schedule Term and Delivery Term) or posts it in BizBox (read by the system). Procurement uploads the PO Bundle (with the supplier document checklist). A supplier may request an extension, and the PO Reviewer extends the due date. The PO Reviewer selects the PO, reviews the details, and either verifies it or escalates to the System Admin. The system sends a 3-day delivery notice (email, later).
- **Part 2, Step 4: delivery, receiving, IAR.** Receiving prepares the IAR on paper. The MMS Encoder enters Invoice #, IAR #, Fund Cluster, Amount, and selects the inspectors and end-user from the UMIS list, then uploads DR, OR, IAR. The system merges the files and enforces a 3-day IAR processing window. If DR, OR, or IAR is missing, the system blocks the step.
- **Part 3, Steps 5-6: monitoring and property documents.** The PO Reviewer updates delivered items per item and per date, and the system shows running delivered and undelivered totals. The Inventory Team decides whether an item is equipment. Above PHP 50,000 unit price: PAR plus Sub-PAR (personnel from UMIS). At or below: ICS.
- **Part 4, Step 7: DV and finance.** The DV Processor reviews the supplier checklist. The system auto-generates the DV (editable) and the IAR Transmittal. If documents are incomplete, the MMS Head may override. After printing, the status becomes "For Finance Processing" (files emailed to COA, later). The Messenger forwards the DV to Finance. Finance confirms receipt (eSignature). The Cashier marks payment disbursed, or Finance returns the transaction with remarks.
- **Flow A, cancellation:** "For termination", email Legal (later), Notice of Termination uploaded, PO cancelled/terminated.
- **Flow B, replacement:** select PO and items, "For Replacement", advance notice to the supplier (email, later), Pull-Out Form, replacement received with remarks and dates.
- **Flow C, delay monitoring:** day count and overdue indicator, grace period, then request a Notice to Terminate. An approved extension moves the due date.
- **Dashboard views:** overdue POs, total and daily transactions, PO count by status, top low-performing suppliers, pending Finance returns, open replacement requests, property documentation pending.
- Statuses seen so far (finalize in step 1.4): uploaded, verified, partially delivered, fully delivered, for uploading, For Finance Processing, returned, payment disbursed, For Replacement, for termination, cancelled, terminated.

## Decisions (as of 2026-11-11)
1. **Schedule Term** means scheduled delivery dates (e.g. Oct 1, Oct 15, Oct 30), so one PO may be delivered in batches (e.g. 500, then 300, then 200). **Delivery Term** is the number of days from the issue/emailed date to the due date.
2. **Data model:** PO, then PO items, then one record per delivery batch. The IAR is unique per delivery. Delivered, undelivered, and on-time status are computed, never typed. DV can link to one or more deliveries (DV per PO or per delivery is still open).
3. **BizBox:** no access yet. Keep a `PurchaseOrderSource` interface with a local implementation now and a BizBox stub. The "Fetch from BizBox" button is shown but disabled. Every PO has a local record with `source` (manual/bizbox) and `external_id`. A config flag turns BizBox on later. Do not invent BizBox fields; list what we need in an integration note.
4. **UMIS** (signers and Sub-PAR personnel) works the same way: a dummy `personnel` table behind a `PersonnelDirectory` interface now, real UMIS later.
5. **One consolidated PDF per PO.** Every uploaded document is appended in upload order (first upload = first pages). Originals are kept, and the UI shows the consolidated PDF. Wrong documents are voided or replaced with a reason, and the PDF is rebuilt. Files live on a private disk and are streamed through authorized routes.
6. **IAR numbers:** three series per year (regular `26-1398`, contract `C26-0015`, donation `D26-0001`). The system suggests the next number, staff confirm or edit, the database rejects duplicates, voided numbers are kept and never reused.
7. **Archiving:** a PO is closed when fully delivered (or cancelled/terminated) and payment is disbursed. Closed POs leave the default list but stay searchable. Never delete them.
8. **Legacy data:** start fresh from a cutoff date; import only open POs plus all old IAR numbers. The old spreadsheet stays as a read-only archive.
9. **Emails and scheduled automation come last** (Phase 11). Until then, wherever the flow says "email", fire a Laravel event or record a pending notice only. The overdue indicator is computed when pages load (no scheduler needed).
10. Skip the supplier-ownership question for now. Build a simple Suppliers table (name, optional email) maintained by Procurement and change it later if IT says otherwise.
11. Skip Inertia SSR. Hospital login (LDAP/SSO) and APIs are the final phase.
12. **Access model (step 0.3):** 34 permissions, no approval permissions (the final flow has none). `cancel.finalize` covers both cancellation and overdue termination. Job presets are defined in `config/mms_access.php`.
13. **Menu and route access (step 1.1):** `config/mms_access.php` has a `sections` list (menu section => permissions, any one is enough). Each section becomes a Gate `section.<name>` (AppServiceProvider). Routes use `->middleware('can:section.<name>')`; the sidebar hides items using `auth.sections`. The Dashboard has no section (every logged-in user). Reports is the last item of Main Menu; the Exceptions and Admin groups hide themselves when empty.
14. **User management design (step 1.2):** a user's permissions = role permissions + direct permissions. Role permissions show ticked and locked in the admin form; job presets and per-person changes are direct permissions. The admin types passwords (no email reset, no forced change at first login for now). `users.manage` covers user actions, `roles.manage` covers role and permission changes. An admin cannot deactivate themselves or remove their own admin access, and the last active Admin cannot be deactivated or demoted.
15. **User management as built (step 1.2):** `app/Support/MmsAccess.php` is the only class that reads the shape of `config/mms_access.php` for the user screens (permission groups by module, presets, role lookups); locked role ticks come from the database roles that `RolePermissionSeeder` fills from the config, so re-run the seeder after editing the config. A job preset REPLACES a person's direct permissions; the "Job" column in the list is derived (largest preset contained in the direct permissions, MMS Staff only), not stored. Direct permissions never duplicate role permissions. A user with `users.manage` but not `roles.manage` can create users only as MMS Staff with no direct permissions and cannot change role or permissions (403). `UserService` keeps the older CRUD methods (`createUser`, `updateUser`, ...) and adds `paginate`, `create`, `update`, `setActive`, `resetPassword` and the guards (it uses `DB::transaction` directly so guard messages reach the form). Password reset also rotates `remember_token`. Known gap: deactivating a user does not end a session they already have open (decide in step 1.3).
16. **UI conventions** are in the "UI conventions" section above (modals, toasts, responsive list cards, live search, file placement).

## Assumptions to confirm with MMS or IT (do not block work)
- Delivery Term counts calendar days or working days, and how holidays are handled ("working calendar days" in Step 5).
- Does a PO become overdue after its Delivery Term due date or after the last scheduled date?
- DV per PO or per delivery? DV numbering?
- "Equipment?" decided from the item's category flag (e.g. `Equipment:Medical`), editable by the Inventory Team.
- Consequence when the 3-day IAR window is exceeded (the briefing does not say). Log only for now.
- Who may raise a Recommendation to Terminate (Flow A). What "OMCC" is (Flow B). Whether replacements are re-inspected. For now the Flow A/B/C actions sit in the PO Reviewer preset.
- Finance steps (return with remarks, payment disbursed) are still TBD for Finance's approval. eSignature is a manual "confirm receipt" for now. One Finance role covers Finance/Accounting and the Cashier.
- Scope of the MMS Head's incomplete-documents override.
- Retention period for closed records.
- Who generates PAR / Sub-PAR / ICS: the Part 3 table says the Inventory Team, the roles slide says the PO Reviewer. Built as Inventory Team.
- Who may void documents (built as Procurement for the PO Bundle and the Encoder for DR/OR/IAR). Whether the Admin fixes escalated PO details (built as yes, via `po.edit`).
- There is no view-only suppliers permission, so only Procurement sees Suppliers. Whether other roles should see the supplier list.
- Whether users must change the admin-typed password at first login.

## Working rules for every session
1. Work only on the step named in the prompt.
2. Give complete files with full paths, and exact PowerShell commands.
3. Follow this file. If something is missing or conflicts, ask before guessing.
4. Explain React/Inertia parts briefly in plain words.
5. No email sending in Phases 0-10. Fire events instead.
6. End with how to test the step, and an updated PROGRESS.md.