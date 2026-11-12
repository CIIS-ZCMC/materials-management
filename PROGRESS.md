# PROGRESS: MMS Procurement Monitoring System

Last updated: 2026-11-12
Current step: **1.4** (Statuses, enums, and config). Step 1.3 is built and waiting for the user's test (see "Step 1.3 checks").
Legend: [x] done · [~] in progress · [ ] not started · [!] blocked / needs a decision

How to use: attach this file and CONTEXT.md to every session. At the end of each step, the session gives an updated version. Replace this file with it.

## Phase 0: Foundation (complete)
- [x] 0.1 Foundation built. Starter kit, `spatie/laravel-permission`, users fields (`employee_no`, `position`, `is_active`, `last_login_at`), `RolePermissionSeeder`, `DummyUserSeeder`, employee-number login, new login page with show/hide password.
- [x] 0.2 Foundation verified and tidied. All six checks passed. Dead auth pages, `ResetUserPassword` action, reset-password test, and `welcome.tsx` deleted. `npm run build` passes.
- [x] 0.3 Roles and permissions aligned with the final flow. `config/mms_access.php` is the single source of truth: 34 permissions, 5 role defaults, 5 job presets, 8 retired permissions. Verified.

## Phase 1: App shell and access control
- [x] 1.1 Layout, sidebar, role-based menu. Sections in `config/mms_access.php` become Gates `section.<name>`; routes use `can:section.<name>`; sidebar shows items from `auth.sections`; `auth.permissions` shared on every page; `useAccess()` hook; 11 placeholder pages. Verified: build passes, `can:` middleware on routes, sidebar matches per user, 403 on blocked URLs.
- [x] 1.2 User management (Admin), including job presets. List with search and pagination, create, edit, activate/deactivate (confirmation modal), admin-typed password reset, `UserPolicy`, `SaveUserRequest`, guards, success toasts. Verified by the user: list, edit, toasts, deactivate/activate. The guard checks below were not explicitly confirmed; recheck when convenient.
- [x] 1.2b Users page polish. Live search (`useLiveSearch` hook: 350 ms debounce, partial reload of `users` + `filters`, stale requests cancelled, loading dim + spinner, clear X / Escape, Enter does not reload, new search = page 1, `?search=` kept in the URL and synced on back/forward) and mobile layout (reusable `ResponsiveList`: table from `md` up, expandable cards below, no horizontal scroll). Reusable `LiveSearchInput` and `PaginationBar` added. Users page now uses all four. No PHP changes. Needs the user's test (see "Step 1.2b checks").
- [x] 1.3 Audit log. Spatie Activitylog v5 (needs PHP 8.4+; its activity() helper is not available, so `Audit` writes rows through the package Activity model). `App\Support\Audit` helper (`log`, `statusChanged`); `UserService` logs created, edited, activated/deactivated, password reset, access changed (role + direct permissions added/removed); `AuditAuthEvents` listener logs login, failed login, logout; `login_blocked` logged in `FortifyServiceProvider`; `EnsureUserIsActive` middleware ends sessions of deactivated users; Audit Log page at `/admin/audit-log` (`AuditLogController`, `AuditLogService`, `ActivityPolicy`) with filters by user, date range, subject type and search, and a Sort by time (newest first by default, or oldest first); times shown in Asia/Manila (`App\Support\DisplayTime`, `config/mms.php`). Needs the user's test.
- [ ] 1.4 Statuses, enums, and config

## Phase 2: Reference data
- [ ] 2.1 Suppliers
- [ ] 2.2 Categories, units, item catalog (decide `catalog.manage` here)
- [ ] 2.3 Personnel directory (dummy UMIS)

## Phase 3: Part 1, PO issuance and verification
- [ ] 3.1 PO data model and dummy POs
- [ ] 3.2 PO list and detail shell
- [ ] 3.3 Create/edit PO (Procurement)
- [ ] 3.4 BizBox source structure (button disabled)
- [ ] 3.5 Extension requests
- [ ] 3.6 Verification and escalation

## Phase 4: Documents and the consolidated PDF
- [ ] 4.1 PDF merge spike (choose method)
- [ ] 4.2 Upload service and documents table
- [ ] 4.3 Consolidated PDF builder and viewer

## Phase 5: Part 2, delivery, receiving, IAR
- [ ] 5.1 IAR numbering
- [ ] 5.2 Delivery record (Encoder)
- [ ] 5.3 DR/OR/IAR upload and completeness gate

## Phase 6: Part 3, monitoring and property documents
- [ ] 6.1 Update delivered items (running totals)
- [ ] 6.2 Equipment decision and property records
- [ ] 6.3 Printable PAR / Sub-PAR / ICS

## Phase 7: Part 4, DV and Finance
- [ ] 7.1 Checklist review and MMS Head override
- [ ] 7.2 Generate DV and IAR Transmittal
- [ ] 7.3 Finance processing flow

## Phase 8: Exception flows
- [ ] 8.1 Flow A, cancellation
- [ ] 8.2 Flow B, replacement
- [ ] 8.3 Flow C, delay monitoring

## Phase 9: Dashboard, archive, reports, import
- [ ] 9.1 Dashboard widgets
- [ ] 9.2 Archive, search, export
- [ ] 9.3 Legacy import tool

## Phase 10: Testing and hardening (before email)
- [ ] 10.1 Automated tests for critical rules
- [ ] 10.2 End-to-end walkthrough and MMS trial

## Phase 11: Email generation and automation
- [ ] 11.1 Mail foundation, queue, scheduler
- [ ] 11.2 Notices (3-day, replacement, delay)
- [ ] 11.3 Emails with attachments (COA, Legal)
- [ ] 11.4 Scheduled jobs and reminders

## Phase 12: Deployment and handoff
- [ ] 12.1 Production readiness
- [ ] 12.2 Handoff package
- [ ] 12.3 Deploy with hospital IT and smoke test

## Phase 13: API integrations (last)
- [ ] 13.1 BizBox API
- [ ] 13.2 UMIS registry
- [ ] 13.3 Hospital login (LDAP/SSO)

## Step 1.3 checks
1. Install per the step instructions; `php artisan migrate` creates `activity_log`.
2. Log in as DUMMY-0001: a "Login" entry appears with your name and the right Manila time. Log out and in again: "Logout" then "Login".
3. Wrong password for a real employee no., and for a made-up one: "Failed login" entries (the second has no subject). The filter "Not logged in" shows them. No password appears anywhere.
4. Create a user: "Created" entry; subject = the new user, by = you; the details show employee no., name, role (no password).
5. Edit that user (change the name and position): "Edited" entry with old to new. Change role and tick/untick permissions: "Access changed" with role old to new and permissions added/removed. Save with no changes: no new entry.
6. Reset the password: "Password reset" entry with no values.
7. Deactivate then activate: "Deactivated" / "Activated" entries (Status Active to Inactive). Re-deactivating the same user is not possible, so no duplicates.
8. Session end: log in as DUMMY-0004 in a private window, deactivate DUMMY-0004 as admin, click anything in the private window: it goes to the login page with the deactivated message; a "Session ended" entry appears. A login attempt with that account shows "Login blocked".
9. Filters: by user, From/To dates (a range of today only), subject type, search by an employee no. or name; combine them; Clear filters; Previous/Next keep the filters; `?causer=...&from=...` in the URL; Back/Forward restores the inputs.
10. Blocked guard attempts (self-deactivate, last Admin) are not logged (by design).
11. Phone width: cards expand, no horizontal scroll.
12. A user without `audit.view` (for example DUMMY-0004) gets 403 on `/admin/audit-log`; DUMMY-0006 (MMS Head) can open it.
13. `npm run build` passes; `composer test`/pint only if you run them.

## Step 1.2 checks (recheck the guards when convenient)
1. Create an MMS Staff user with a job preset, tick/untick two permissions, save, log in as them.
2. Creating a second user with the same employee no. shows the unique error.
3. As Admin: cannot deactivate yourself; cannot change your own role away from Admin; cannot untick your own users.manage / roles.manage.
4. With one active Admin left, deactivating or demoting that Admin is blocked.
5. A user with users.manage but not roles.manage can edit details but cannot change role or permissions (403 if forced).

## Step 1.2b checks
1. Type in the search box: the list filters by itself, the page does not reload, the box keeps focus and the cursor.
2. Press Enter: nothing reloads. Press Esc or the X: the box empties and the full list returns at once.
3. Type fast then slow: results always match the last text typed (never an older one). A spinner shows and the list dims while loading.
4. Search something with more than one page, go to page 2, then type a new search: it returns to page 1. Next/Previous keep the search.
5. The URL shows `?search=...`. Refresh keeps it. After Next, the browser Back button returns to the previous page with the same search.
6. Deactivate a user (toast shows), then search: the old toast does not appear again.
7. Phone width (F12, device toolbar, about 390 px): no horizontal scroll; tap a card to open/close it; Edit and Deactivate/Activate work inside the card; Previous/Next are large and stacked under the count.
8. At `md` (768 px) and up the normal table is back.
9. `npm run build` passes.

## Using the audit trail in later steps
- Call `App\Support\Audit::log($event, $subject, $description, old: [...], new: [...], reason: '...', log: 'po')` or `Audit::statusChanged($po, $from, $to, $reason)` inside the Service, in the same `DB::transaction` as the change. Never pass passwords, hashes or tokens.
- Give the new model an `auditLabel()` method (like `User::auditLabel()`), and add it to `config/mms.php` (`audit.subject_types`) so it shows in the Audit Log filter. Add new event names to `audit.events` and field names to `audit.fields` for readable labels.
- Show any date to users with `App\Support\DisplayTime::format($carbon)` (Asia/Manila).

## Reusing ResponsiveList on a new list page (POs, suppliers, deliveries, audit log)
- Controller: send `{ data, current_page, last_page, total, from, to, prev_page_url, next_page_url }` and `filters: { search }` (same as `UserController@index`), with the search trimmed.
- Page: `useLiveSearch({ url, initial: filters.search, only: ['<prop>', 'filters'] })`, then `LiveSearchInput`, `ResponsiveList` (columns with `card: 'title' | 'badge' | 'summary' | 'detail'`, optional `cardCell`, `renderActions`) and `PaginationBar onNavigate={search.goTo}`.

## Key files so far
- `resources/js/hooks/use-live-search.ts` (reusable live, debounced, partial-reload search for Inertia list pages)
- `resources/js/components/live-search-input.tsx` (search box with spinner, clear X, Esc; Enter never reloads)
- `resources/js/components/responsive-list.tsx` (reusable table-on-desktop, expandable-cards-on-phone list: `ResponsiveList`, `ListColumn`)
- `resources/js/components/pagination-bar.tsx` (reusable count + Previous/Next, stacked touch-friendly on phones: `PaginationBar`, `PaginationMeta`)
- Migration: `add_hospital_fields_to_users_table`; Spatie permission migration (unchanged)
- `app/Models/User.php` (HasRoles, new fields, `filterBySearch` for the Filterable trait)
- `app/Services/UserService.php` now holds the legacy CRUD methods (`getAllUsers`, `createUser`, `updateUser`, `deleteUser`...) AND the 1.2 methods (`paginate`, `create`, `update`, `setActive`, `resetPassword`, guards)
- `config/mms_access.php` (permissions, role defaults, job presets, menu sections, retired names: single source of truth)
- `database/seeders/`: `RolePermissionSeeder.php`, `DummyUserSeeder.php`, `DatabaseSeeder.php`
- `app/Providers/FortifyServiceProvider.php` (employee-number login, inactive block), `AppServiceProvider.php` (last_login_at, section Gates)
- `app/Http/Middleware/HandleInertiaRequests.php` (shares `auth.permissions` and `auth.sections`)
- `config/fortify.php` (`username` = `employee_no`; `lowercase_usernames` = false; `features` empty)
- `routes/web.php` (`/` redirects to `/login`; dashboard; placeholder routes with `can:section.<name>`; `admin/users` routes now point to `Admin\UserController`)
- 1.3: `app/Support/Audit.php`, `app/Support/DisplayTime.php`, `config/mms.php`, `app/Listeners/AuditAuthEvents.php`, `app/Http/Middleware/EnsureUserIsActive.php` (registered in `bootstrap/app.php`), `app/Policies/ActivityPolicy.php`, `app/Services/AuditLogService.php`, `app/Http/Controllers/Admin/AuditLogController.php`, `resources/js/pages/admin/audit-log/index.tsx`; `config/activitylog.php` and the `activity_log` migration from the package
- `app/Support/MmsAccess.php` (only class that reads the shape of `config/mms_access.php` for the user screens; constants for Admin role, base role, self-keep permissions)
- `app/Policies/UserPolicy.php`, `app/Http/Requests/Admin/SaveUserRequest.php`, `ResetUserPasswordRequest.php`
- `app/Services/UserService.php` (create, update, setActive, resetPassword, guards), `app/Http/Controllers/Admin/UserController.php`
- `app/Contracts/UserRepositoryInterface.php`, `app/Repositories/UserRepository.php` (`paginateForAdmin`, `countActiveWithRole`)
- `resources/js/hooks/use-access.ts`, `resources/js/components/app-sidebar.tsx` (MMS menu), `resources/js/components/coming-soon.tsx`
- `resources/js/components/admin/user-form.tsx`, `use-flash-toast.ts`, `confirm-dialog.tsx` (reusable confirmation modal; use it instead of `window.confirm`)
- `resources/js/layouts/app-layout.tsx` mounts the single `<Toaster />`; `HandleInertiaRequests.php` shares `flash.success` (set with `->with('success', '...')`, shown by `useFlashToast()`)
- `resources/js/pages/`: `purchase-orders`, `suppliers`, `deliveries`, `property-documents`, `dv-finance`, `reports`, `exceptions/{cancellations,replacements,delays}`, `admin/audit-log` (placeholders); `admin/users/{index,create,edit}.tsx` (real; `index.tsx` uses the 1.2b components)
- `resources/js/pages/auth/login.tsx`, `resources/js/layouts/auth/auth-simple-layout.tsx`, `public/images/zcmc.png`, `resources/css/app.css` (hides Edge reveal-password icon)
- Settings pages still in place: `profile`, `security`, `appearance`

## Decisions log
- 2026-10-29: 5 roles with per-user permissions; employee-number login; skip Inertia SSR; BizBox integration deferred behind an adapter.
- 2026-11-11: Final flow received. Schedule Term = scheduled delivery dates. "Fetch from BizBox" disabled, structure kept. One consolidated PDF per PO. Emails and automation moved to Phase 11. Supplier ownership question skipped for now. See CONTEXT.md for the full list.
- 2026-11-11: Fortify `lowercase_usernames` turned off so employee numbers match exactly on any database collation.
- 2026-11-11: Access model: no approval permissions; 34 permissions; MMS Staff role is a small base and each job is a preset applied as direct permissions; `cancel.finalize` covers cancellation and overdue termination; dummy users one per MMS job (DUMMY-0003 to 0005, 0008, 0009).
- 2026-11-11: Menu and route access: sections in `config/mms_access.php` become Gates `section.<name>`; routes use `can:section.<name>`; the sidebar uses `auth.sections`. Dashboard open to all logged-in users. Reports sits at the end of Main Menu.
- 2026-11-11: Step 1.2 design: role permissions show ticked and locked; job presets and per-person changes are direct permissions; admin types passwords (no email reset); last active Admin cannot be deactivated or demoted.
- 2026-11-11: UI conventions set in 1.2: confirmations are modals (`ConfirmDialog`), not `confirm()`; success messages are toasts via `flash.success`; pages use plain URLs (no Wayfinder imports) and live in `resources/js/pages/...` (components in `resources/js/components/...`; mixing these folders caused a blank page once); new Inertia pages need a Vite restart.
- 2026-11-11: Responsive UI convention for ALL list pages (users, POs, suppliers, deliveries, audit log, ...): table from the `md` breakpoint up; below `md` each row becomes an expandable card (collapsed: main identifier, status badge, one key field; expanded: remaining fields and actions). No horizontal scrolling on phones. Search is live (debounced, server-side, no Enter, no page reload) and pagination keeps the search. Build it once as a reusable component and reuse it.
- 2026-11-11: Step 1.2 build choices: locked role ticks are read from the database roles (filled by `RolePermissionSeeder` from the config); a job preset REPLACES the person's direct permissions; job label in the list is derived (largest preset contained in the user's direct permissions, MMS Staff only), no new column; a user with `users.manage` but not `roles.manage` can create users only as MMS Staff with no direct permissions; direct permissions never duplicate role permissions; password reset also rotates `remember_token`.
- 2026-11-11: Step 1.2b: typing searches use `replace` (no history spam); Previous/Next push history so Back returns to the earlier page with the same search. Cards allow several open at once. Search term is trimmed in the browser and again in the controller.
- 2026-11-12: Step 1.3: Spatie Activitylog `^5.0` (Laravel 13 supported; needs PHP 8.4+). `Audit` writes rows directly through the package `Activity` model (the `activity()` helper does not exist here), all values in `properties` (`old`, `new`, `reason`, `ip`, `subject_label`, `permissions_added/removed`). Logging lives in the Services (and one listener for auth events), not in controllers. Subject label is stored as a snapshot so the log stays readable. App timezone stays UTC; times are converted to Asia/Manila only for display (`config/mms.php` `display_timezone`, `DisplayTime`); date filters are read as Manila days. Deactivated users are logged out on their next request by `EnsureUserIsActive`; deactivation also rotates `remember_token`. Blocked guard attempts are not logged. Audit Log viewing needs `audit.view` (Admin and MMS Head).

## Open questions
See "Assumptions to confirm" in CONTEXT.md. Includes: who generates PAR / Sub-PAR / ICS (Inventory Team built; slide 17 says PO Reviewer). No view-only suppliers permission, so only Procurement sees Suppliers. Forced password change at first login: not for now.

## Known issues
- `last_login_at` is stored in UTC and the app timezone stays UTC (decided in 1.3). Wherever it is shown, use `DisplayTime::format()`.
- The package config `delete_records_older_than_days` defaults to 365 and `activitylog:clean` is not scheduled until Phase 11. Set it high (or null) in `config/activitylog.php` so the audit trail is never pruned by accident; retention period is still an open question for MMS.
- Other kit tests in `tests/Feature/Auth` may fail because they log in with email; fix in Phase 10.1.
- A blocked page shows Laravel's plain 403 screen; a friendlier Inertia error page can come later.
- Step 1.2 guards (self-deactivate, own Admin role, own `users.manage`/`roles.manage`, last active Admin, unique employee no. error) were not explicitly confirmed by the user; recheck.