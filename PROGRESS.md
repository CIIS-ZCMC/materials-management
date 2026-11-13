# PROGRESS: MMS Procurement Monitoring System

Last updated: 2026-11-13
Current step: **3.4** (BizBox-ready structure) is built and seen working in the browser 2026-11-13: the create form shows the compact "How is this PO made?" card with the disabled "Fetch from BizBox" button. The provider tests and tinker checks are not explicitly confirmed yet (see "Step 3.4 checks"). Next: 3.5 (extension requests). Steps 1.4, 2.1, 2.2, 3.1, 3.2 and 3.3 are done and verified (3.3: `PurchaseOrderFormTest` passes, 12 passed, 55 assertions). Step 2.3 (personnel directory) is still open; it can wait until Phase 5. Open items: grace period value, offline scope (revisit before 5.1), Flow A approval permission, whether Procurement enters a quantity per schedule date (ask MMS; does not block anything), stricter PO number rule, and everything on the BizBox side, which IT must confirm (see "Open questions" and `docs/INTEGRATION-BIZBOX.md`).
Legend: [x] done · [~] in progress · [ ] not started · [!] blocked / needs a decision

How to use: attach this file and CONTEXT.md to every session. At the end of each step, the session gives an updated version. Replace this file with it.

## Phase 0: Foundation (complete)
- [x] 0.1 Foundation built. Starter kit, `spatie/laravel-permission`, users fields (`employee_no`, `position`, `is_active`, `last_login_at`), `RolePermissionSeeder`, `DummyUserSeeder`, employee-number login, new login page with show/hide password.
- [x] 0.2 Foundation verified and tidied. All six checks passed. Dead auth pages, `ResetUserPassword` action, reset-password test, and `welcome.tsx` deleted. `npm run build` passes.
- [x] 0.3 Roles and permissions aligned with the final flow. `config/mms_access.php` is the single source of truth: 5 role defaults, 5 job presets, 8 retired permissions. Verified with `php artisan mms:access-matrix` (permission count was 34; it is 35 since step 2.1 added `suppliers.view`).

## Phase 1: App shell and access control
- [x] 1.1 Layout, sidebar, role-based menu. Sections in `config/mms_access.php` become Gates `section.<n>`; routes use `can:section.<n>`; sidebar shows items from `auth.sections`; `auth.permissions` shared on every page; `useAccess()` hook; 11 placeholder pages. Verified: build passes, `can:` middleware on routes, sidebar matches per user, 403 on blocked URLs.
- [x] 1.2 User management (Admin), including job presets. List with search and pagination, create, edit, activate/deactivate (confirmation modal), admin-typed password reset, `UserPolicy`, `SaveUserRequest`, guards, success toasts. Verified by the user: list, edit, toasts, deactivate/activate. The guard checks below were not explicitly confirmed; recheck when convenient.
- [x] 1.2b Users page polish. Live search (`useLiveSearch` hook: 350 ms debounce, partial reload of `users` + `filters`, stale requests cancelled, loading dim + spinner, clear X / Escape, Enter does not reload, new search = page 1, `?search=` kept in the URL and synced on back/forward) and mobile layout (reusable `ResponsiveList`: table from `md` up, expandable cards below, no horizontal scroll). Reusable `LiveSearchInput` and `PaginationBar` added. Users page uses all four. No PHP changes. Phone-width checks not explicitly confirmed (see "Step 1.2b checks").
- [x] 1.3 Audit log. Spatie Activitylog v5 (needs PHP 8.4+; its activity() helper is not available, so `Audit` writes rows through the package Activity model). `App\Support\Audit` helper (`log`, `statusChanged`); `UserService` logs created, edited, activated/deactivated, password reset, access changed (role + direct permissions added/removed); `AuditAuthEvents` listener logs login, failed login, logout; `login_blocked` logged in `FortifyServiceProvider`; `EnsureUserIsActive` middleware ends sessions of deactivated users; Audit Log page at `/admin/audit-log` (`AuditLogController`, `AuditLogService`, `ActivityPolicy`) with filters by user, date range, subject type and search, and a Sort by time (newest first by default, or oldest first); times shown in Asia/Manila (`App\Support\DisplayTime`, `config/mms.php`). The Audit Log page was confirmed working in the 2.1 test (supplier entries show, Supplier filter works); the full "Step 1.3 checks" list was not explicitly confirmed.
- [x] 1.4 Statuses, enums, and config. Approved by the user 2026-11-12. Six enums in `app/Enums` (four stored with `label()` and transition rules, two derived), `App\Support\StatusTransition` (only valid changes, logs through `Audit::statusChanged()` with enum labels), `InvalidStatusTransition`, new keys in `config/mms.php`, unit test `StatusModelTest`. No migrations. `StatusTransition::apply()` was first exercised in 3.1 (`PurchaseOrderTest` and the tinker check). Still to do: rerun `php artisan test --filter=StatusModelTest` (the earlier pass was before the final-flowchart revision) and raise the PHP upload limits.

## Phase 2: Reference data
- [x] 2.1 Suppliers. Table `suppliers` (name unique, email, contact_person, address, is_active), `Supplier` model (Filterable, `auditLabel()`, `filterBySearch`), `SupplierFactory`, `SupplierSeeder` (40 fake names, every 10th inactive, refuses to run in production, added to `DatabaseSeeder`), `SupplierRepositoryInterface` + `SupplierRepository` (bound in `AppServiceProvider`), `SupplierService` (create, update, setActive; audit log `suppliers`), `SupplierPolicy`, `SaveSupplierRequest`, `SupplierController`, routes `/suppliers/...`, pages `suppliers/{index,create,edit}.tsx` and `components/suppliers/supplier-form.tsx`. New permission `suppliers.view` (MMS Staff base, MMS Head, Finance); `suppliers.manage` (Procurement) also allows viewing; the `suppliers` section opens with either. Admin has neither (can be added to the Admin role in the config). Supplier added to `audit.subject_types` and the new fields to `audit.fields`. Verified by the user: Procurement creates and manages, MMS Staff sees a view-only list, `/suppliers/create` gives 403 for MMS Staff, Admin has no Suppliers menu, audit log shows supplier entries.
- [x] 2.2 Categories, units, item catalog. Built and verified by the user 2026-11-12 (migrate, seeders, build, access matrix, Procurement lists). Tables `item_categories` (name unique, `is_equipment`, is_active), `units` (name unique, optional `code`, is_active), `items` (name, `category_id`, `unit_id`, is_active; unique on name + unit); models `ItemCategory`, `Unit`, `Item` (Filterable, `auditLabel()`, `filterBySearch`), three factories, `CatalogSeeder` (8 categories, 10 units, 60 items, 2 equipment categories, every 20th item inactive, refuses to run in production, added to `DatabaseSeeder`), three repository interfaces + repositories (bound in `AppServiceProvider`; categories and units also have `options()` for dropdowns), `ItemCategoryService` / `UnitService` / `ItemService` (create, update, setActive; audit log `catalog`), three policies, `SaveItemCategoryRequest` / `SaveUnitRequest` / `SaveItemRequest`, three controllers, routes `/catalog/{items,categories,units}/...` (names `catalog.*`), pages `catalog/{items,categories,units}/{index,create,edit}.tsx` and `components/catalog/{item,category,unit}-form.tsx`. New permissions `catalog.view` (MMS Staff base, MMS Head, Finance) and `catalog.manage` (Procurement; also allows viewing); new section `catalog` and a "Catalog" sidebar group (Items, Categories, Units). Admin has neither. The three models are in `audit.subject_types`; new fields in `audit.fields`; `catalog` label added to `MmsAccess::MODULE_LABELS`.
- [ ] 2.3 Personnel directory (dummy UMIS)

## Phase 3: Part 1, PO issuance and verification
- [x] 3.1 PO data model and dummy POs. Built and verified by the user 2026-11-13 (`migrate:fresh --seed`, tinker counts and status check, `PurchaseOrderTest` 6 of 6, `DueDateTest` 6 of 6; the indexes in phpMyAdmin were not explicitly confirmed). `app/Exceptions/InvalidStatusTransition.php` was missing from the project (listed in 1.4 but never created) and was added here. Tables `purchase_orders`, `po_items`, `po_schedules`, `po_extensions` (no delivery tables yet, no new permissions); new enum `PurchaseOrderSource` (manual / bizbox), `PurchaseOrderStatus` reused; models `PurchaseOrder` (`auditLabel()`, `filterBySearch` on PO number and supplier name, `calculateDueDate()` / `recalculateDueDate()`, `isClosed()`, `open()` scope), `PoItem`, `PoSchedule`, `PoExtension`; four factories; `App\Support\DueDate` (the only code that counts days; reads `term_counting` and `extension_counting`); `PurchaseOrderSeeder` (60 dev POs over all 8 statuses, past, future and missing due dates, 8 extensions, 6 BizBox-source POs each with one line outside the catalog, 3 donation/contract-style POs without a due date; refuses in production; skips when POs already exist; runs after the supplier and catalog seeders); `PurchaseOrder` added to `audit.subject_types`; tests `PurchaseOrderTest` and `DueDateTest` (first real test of `StatusTransition::apply()`).
- [x] 3.2 PO list and detail shell. Built and verified by the user 2026-11-13 (list and detail pages working), read only. No migration and no new permission (`po.view`, section `purchase-orders`). `PurchaseOrderRepositoryInterface` + `PurchaseOrderRepository` (bound in `AppServiceProvider`; `paginateFiltered`, `resolveSort` with the fixed list of allowed sort columns, `supplierOptions`, `loadDetail`), `PurchaseOrderService` (`paginate`, `cleanFilters`, `supplierOptions`, `detail`), `PurchaseOrderPolicy` (`viewAny`, `view`), `PurchaseOrderController` (`index`, `show`), routes `purchase-orders.index` and `purchase-orders.show` (`/purchase-orders/{purchaseOrder}`, numeric ids only), pages `purchase-orders/{index,show}.tsx`, components `purchase-orders/{po-status-badge,overdue-badge-slot}.tsx` and `purchase-orders/po-format.ts`, test `PurchaseOrderListTest`. List: live search (PO number or supplier name), filters for status, supplier (all, inactive included) and PO date range, Active/Closed toggle (`open()` scope or `closed_at` not null), sortable headers (default due date, soonest first, no due date last), columns PO number, supplier, status badge (stored label), due date, PO date, number of items, an empty `OverdueBadgeSlot` next to the due date (filled in 8.3). Detail: tabs Details, Items (line totals and PO total in PHP, "Not in catalog" marker), Schedule (dates and read-only extension history); Deliveries, Documents and History show `ComingSoon`. `ResponsiveList` gained optional `sortable` columns plus `sort` and `onSort`; `useLiveSearch` gained `params` and `apply()` so filters, toggle and sort survive typing and paging.
- [x] 3.3 Create/edit PO (Procurement). Built 2026-11-13; tests pass (12 of 12 in `PurchaseOrderFormTest`) and the user confirmed the create form works. No migration, no new permission (`po.create`, `po.edit`). New: `SavePurchaseOrderRequest`, `App\Support\PoEditRule` (editable = draft, uploaded, escalated; locked from verified on; BizBox source never), `App\Support\Money` (centavo helpers, also used by the detail page), `PurchaseOrderPolicy` `create` / `update` (returns a deny message) / `searchItems`, `PurchaseOrderService` `create` / `update` / `supplierChoices` / `searchItems` / `loadForEdit` (audit log `po`: `created` and `updated` with old and new values, supplier shown by name, items and schedule summarised as added / removed / changed, no entry when nothing changed; a second status check under a row lock), repository `findLocked`, `loadForEdit`, `supplierChoices`, `searchActiveItems`, `catalogItemsByIds`; routes `create`, `store`, `item-search`, `edit`, `update`; pages `purchase-orders/{create,edit}.tsx`; components `purchase-orders/{po-form,item-search-box}.tsx`; "New PO" button on the list (needs `po.create`), "Edit" button or lock message on the detail page (server flag `edit` from the Policy); eight audit field labels added to `config/mms.php`; test `PurchaseOrderFormTest`. Form: supplier (active only; an existing PO keeps its current one), PO number, PO date, emailed date, delivery term, read-only due date preview, Schedule Term dates (add/remove, unique), item rows with catalog search (at least 2 letters, active items only, at most 10 results), running line and PO totals in PHP, "Post directly in BizBox" shown disabled ("Not yet available").
- [x] 3.4 BizBox-ready structure (button disabled). Built 2026-11-13; the user confirmed it works in the browser the same day (button and input visible and disabled; the other checks are not all explicitly confirmed, see "Step 3.4 checks"). The "How is this PO made?" card was then made compact (small check-icon box on the left, dashed BizBox box on the right with a "Not yet available" badge, input and button on one line, shorter note); layout only, behaviour unchanged.
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

## Final flowchart changes (received 2026-11-12; `MMS_PO_to_DV_Workflow.pdf`, Draft v1.0, authoritative over the briefing)
Applied in 1.4: Flow A approval gate (new PO status `for_termination_approval`); grace period range 3 to 7 calendar days; extension counting "working"; PAR/ICS limits kept in config but flagged unresolved (`ics_min_value` 1000 added). Recorded only (affects later steps, see the development guide table "Changes in the final flowchart"): supporting documents in the Part 2 gate, Part 3 below-1,000 remarks rule and PO Reviewer lane, Part 4 signature + photo handover (DPU), Flow B Advance Notice Letter upload, auto-close of access after the grace period with Head of MMS reopen, supplier warning emails, dashboard additions, and offline functionality with sync (scope undefined; decide before Phase 3).

## Status model (step 1.4, approved 2026-11-12; also in CONTEXT.md decision 17)
Stored (set by a workflow action, changed only through `StatusTransition::apply()`):
- PO (`PurchaseOrderStatus`): draft -> uploaded -> verified; uploaded <-> escalated (to Admin); verified -> for_termination_approval -> for_termination (approved) or back to verified (disapproved); for_termination -> cancelled | terminated. Reason required for: escalated, for_termination_approval, disapproval, cancelled, terminated.
- Delivery / IAR (`DeliveryStatus`): for_uploading -> complete | voided; complete -> for_uploading (reason) | voided (reason). Complete = DR, OR, IAR and the required supporting documents.
- DV (`DvStatus`): draft -> for_finance_processing -> received_by_finance -> payment_disbursed | returned (reason); returned -> draft.
- Replacement item (`ReplacementStatus`): for_replacement -> replaced.
Derived (computed, never stored): `DeliveryProgress` (not delivered / partial / full), `DeliveryTiming` (on time / overdue / past grace; past grace = access auto-closes unless the Head of MMS reopens, the reopen is a stored flag decided in 8.3), "closed" (fully delivered or cancelled/terminated, AND payment disbursed; `closed_at` rule decided in 3.1, see the 2026-11-13 decision), "property documentation pending", open replacement requests.
List/dashboard badge for a PO: stored status, except `verified` shows the progress label. Overdue, DV status and Closed are separate badges.
Added by me and kept with the approval: PO `draft`, PO `escalated`, DV `draft` and `received_by_finance`, `Voided` delivery. Assumptions still to confirm with MMS: Cancelled = withdrawn by MMS, Terminated = supplier default; in Flow A, MMS verifies first and the approving authority decides second, and disapproval returns the PO to verified.

## Step 3.4 checks (pending)
1. Add `BIZBOX_ENABLED=false` to `.env` and `.env.example`; `php artisan config:clear`, `route:clear`, `wayfinder:generate`, `npm run build`; restart `composer dev`.
2. `php artisan test --filter=PurchaseOrderProviderTest` passes (10 tests). `php artisan test` shows only the 3 known kit failures.
3. Tinker: `config('mms.bizbox.enabled')` is false; the container gives `LocalPurchaseOrderProvider`; `fetch($po->po_number)` returns the PO and lines; with `config(['mms.bizbox.enabled' => true])` the container gives the stub and `fetch('x')` throws "BizBox is not configured".
4. DUMMY-0002, New PO: the "Fetch from BizBox" button and input are visible and disabled, the tooltip "Not yet available" shows on hover, the note is visible, clicking does nothing (no request in the F12 Network tab). Creating a PO still works. The edit page has no BizBox box.
5. Phone width: input, button and note fit, no horizontal scroll.
6. `docs/INTEGRATION-BIZBOX.md` exists.

## Step 3.3 checks (tests passed and create form seen working 2026-11-13; the rest not explicitly confirmed)
1. `php artisan test --filter=PurchaseOrderFormTest` passes (done: 12 passed). The full `php artisan test` run has only the 3 known kit failures.
2. Procurement sees "New PO"; MMS Staff does not and gets 403 on /purchase-orders/create.
3. Creating a PO with items and two schedule dates works; the preview due date equals the saved one; the PO shows in the list and detail pages.
4. Errors show per field and per row (duplicate number, space in the number, empty price, repeated date, inactive supplier).
5. Edit works on draft / uploaded / escalated (Admin on escalated); Verified and BizBox POs show no Edit button (the reason shows instead) and give 403 by URL.
6. A real edit writes one "updated" entry in the audit log (log "po"); saving without changes writes none.
7. The "Remove line" confirmation appears for a saved line; a success toast shows after saving (if not, the `flash.success` vs `flash.toast` mismatch, see the decisions log).

## Step 3.2 checks (list and detail confirmed 2026-11-13; items 2, 10 and 11 not explicitly confirmed)
1. `php artisan config:clear`, `php artisan route:clear`, `php artisan wayfinder:generate`, `npm run build`; restart `composer dev`. No migration and no seeding.
2. `php artisan test --filter=PurchaseOrderListTest` passes (9 tests). `php artisan test` shows only the 3 known kit failures (Phase 10.1).
3. DUMMY-0002 (Procurement), Purchase Orders: the list opens sorted by due date, soonest first, with the 7 POs without a due date at the end. Default is Active: "Showing 1-15 of 53" (60 POs minus the 7 closed ones).
4. Closed button: 7 POs, all Cancelled or Terminated. Active returns to 53.
5. Live search by part of a PO number or a supplier name: no reload, `?search=` in the URL, works in both Active and Closed.
6. Status filter: Verified gives 28 (Active), Draft gives 6. Supplier filter lists every supplier, including inactive ones (marked "(inactive)"). PO date From/To narrows the list; "Clear filters" resets status, supplier and dates (search, Active/Closed and sort stay).
7. Click the column headers: arrow shows, a second click reverses, the list returns to page 1, `sort` and `direction` appear in the URL. Previous/Next keep search, filters, Active/Closed and sort.
8. Open a PO (PO number link or View): Details, Items (PHP amounts, line totals, PO total; the 6 BizBox POs show "Not in catalog" on one line) and Schedule (dates; extension history on the POs that have extensions) work. Deliveries, Documents and History show Coming soon. No create/edit/status/extension buttons.
9. Other roles (DUMMY-0001, 0004, 0006, 0007) can open the list and a PO (all have `po.view`). A user without `po.view` gets 403 (covered by the test).
10. Phone width (F12, about 390 px): filters stack, the "Sort by" box and direction button appear, cards expand, no horizontal scroll, detail tabs wrap.
11. Speed: the no-N+1 test passes (the list runs the same number of queries for 3 and for 15 POs).

## Step 3.1 checks (all passed 2026-11-13, except 2 and 7, not explicitly confirmed)
1. Copy the files; `php artisan config:clear`; `php artisan migrate:fresh --seed` runs without errors and ends with "Fake purchase orders seeded: 60". (Dummy logins are recreated by the seed.)
2. phpMyAdmin (`material_management_system`): `purchase_orders` has 60 rows; `po_items`, `po_schedules` and `po_extensions` have rows. On `purchase_orders` > Structure > Indexes: unique `po_number`, unique `source + external_id`, and indexes on `status`, `due_date`, `supplier_id`, `po_date` and `status + due_date`.
3. In `php artisan tinker`: `App\Models\PurchaseOrder::query()->selectRaw('status, count(*) n')->groupBy('status')->pluck('n', 'status')` gives draft 6, uploaded 8, escalated 4, verified 28, for_termination_approval 4, for_termination 3, cancelled 4, terminated 3.
4. Due-date spread: `App\Models\PurchaseOrder::where('due_date', '<', today()->subDays(7))->count()` is above 0 (past any grace period); `where('due_date', '>=', today())->count()` is above 0; `whereNull('due_date')->count()` is 7; `App\Models\PoExtension::count()` is 8; `whereNotNull('closed_at')->count()` is 7 (cancelled and terminated); `where('source', 'bizbox')->count()` is 6 and `App\Models\PoItem::whereNull('item_id')->count()` is 6.
5. Status check in tinker: `$po = App\Models\PurchaseOrder::where('status', 'draft')->first();` then `App\Support\StatusTransition::apply($po, 'status', App\Enums\PurchaseOrderStatus::Uploaded);` returns true and `$po->fresh()->status` is Uploaded; `App\Support\StatusTransition::apply($po, 'status', App\Enums\PurchaseOrderStatus::Cancelled);` throws InvalidStatusTransition and the status stays Uploaded. `Spatie\Activitylog\Models\Activity::latest('id')->first()->description` reads "Status changed: <PO number> (Draft to Uploaded)".
6. `php artisan test` passes, including `--filter=PurchaseOrderTest`, `--filter=DueDateTest` and `--filter=StatusModelTest`.
7. Open `/admin/audit-log` as DUMMY-0001 after step 5: the new "Status changed" entry shows, and the subject filter lists "Purchase order".

## Step 2.2 checks
1. `php artisan migrate`, `config:clear`, `route:clear`, `db:seed --class=RolePermissionSeeder`, `db:seed --class=CatalogSeeder`, `wayfinder:generate`, `npm run build`; restart `composer dev`.
2. `php artisan mms:access-matrix`: 37 permissions, no unknown names; `catalog.view` on MMS Staff, MMS Head and Finance; `catalog.manage` on Procurement only.
3. DUMMY-0002 (Procurement): Catalog group with Items, Categories, Units; 60 items, 8 categories, 10 units; create / edit / deactivate / activate each, with toasts; duplicate category and unit names give the error; the same item name with the same unit gives the error, with another unit it is accepted; live search (items also match category and unit names).
4. Category form: tick and untick "Equipment category", save; the Items list column "Equipment?" follows the category. Deactivate a category: it still shows on its existing items, but is not offered for a new item.
5. DUMMY-0004 (MMS Staff): the three lists are view only (no New / Edit / Deactivate buttons); `/catalog/items/create`, `/catalog/categories/create` and `/catalog/units/create` give 403. DUMMY-0006 and DUMMY-0007 also see the lists. DUMMY-0001 (Admin) has no Catalog group.
6. Admin screen (DUMMY-0001, Users, edit a user): a "Catalog" group with View and Manage.
7. Audit Log (DUMMY-0001 or DUMMY-0006): "Created" / "Edited" / "Deactivated" entries for Item category, Unit and Item; the subject filter lists all three; an item edit shows category and unit by name, a category edit shows Equipment category Yes / No.
8. Phone width: cards expand, no horizontal scroll.

## Step 2.1 checks (all passed)
1. `php artisan migrate`, `config:clear`, `route:clear`, `db:seed --class=RolePermissionSeeder`, `db:seed --class=SupplierSeeder`, `wayfinder:generate`, `npm run build`; restart `composer dev`.
2. DUMMY-0002 (Procurement): Suppliers menu, 40 fake rows, create / edit / deactivate / activate with toasts, duplicate name error, live search.
3. DUMMY-0004 (MMS Staff): list only, no New / Edit / Deactivate buttons, `/suppliers/create` gives 403. DUMMY-0006 and DUMMY-0007 also see the list.
4. DUMMY-0001 (Admin): no Suppliers menu item.
5. Audit Log: supplier "Created" / "Edited" / "Deactivated" entries, Supplier filter works.
Lesson: after adding a permission to `config/mms_access.php`, run `php artisan config:clear` and `php artisan db:seed --class=RolePermissionSeeder`, or existing roles do not get it.

## Step 1.4 checks
1. Copy the files per the where-to-place table; `php artisan config:clear`.
2. `php artisan test --filter=StatusModelTest` passes (8 tests). Rerun pending.
3. In `php artisan tinker`: `App\Enums\PurchaseOrderStatus::Verified->allowedNext()` lists only ForTerminationApproval; `App\Support\StatusTransition::can(App\Enums\PurchaseOrderStatus::Cancelled, App\Enums\PurchaseOrderStatus::Verified)` is false; `config('mms.delivery')` (grace min 3, max 7, days null, term_counting calendar, extension_counting working), `config('mms.property')` (50000 and 1000), `config('mms.uploads.max_size_kb')` (10240) return the new keys; `config('mms.audit.events.login')` still works.
4. PHP limits: the first run showed 2M / 8M in `C:\php85\php.ini`. Raise to about 40M / 50M, restart `composer dev`, and recheck with the `ini_get` command.
5. The Audit Log page and login still work (config file only grew).

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
- Give the new model an `auditLabel()` method (like `User::auditLabel()`), and add it to `config/mms.php` (`audit.subject_types`) so it shows in the Audit Log filter. Add new event names to `audit.events` and field names to `audit.fields` for readable labels. (Done for Supplier in 2.1.)
- Show any date to users with `App\Support\DisplayTime::format($carbon)` (Asia/Manila).

## Reusing ResponsiveList on a new list page (POs, suppliers, deliveries, audit log)
- Controller: send `{ data, current_page, last_page, total, from, to, prev_page_url, next_page_url }` and `filters: { search }` (same as `UserController@index` and `SupplierController@index`), with the search trimmed.
- Page: `useLiveSearch({ url, initial: filters.search, only: ['<prop>', 'filters'] })`, then `LiveSearchInput`, `ResponsiveList` (columns with `card: 'title' | 'badge' | 'summary' | 'detail'`, optional `cardCell`, `renderActions`) and `PaginationBar onNavigate={search.goTo}`. `suppliers/index.tsx` is the simplest example (it hides the action buttons when `canManage` is false).
- Pages with filters, a toggle or sorting (`purchase-orders/index.tsx`): pass the current non-search values to `useLiveSearch` as `params` (empty strings are dropped), change them with `search.apply({ status: 'verified' })` (keeps the typed search, goes back to page 1, URL updated), and mark columns `sortable: true` with `sort` and `onSort` on `ResponsiveList`. The repository checks `sort` against a fixed list.

## Key files so far
- `resources/js/hooks/use-live-search.ts` (reusable live, debounced, partial-reload search for Inertia list pages)
- `resources/js/components/live-search-input.tsx` (search box with spinner, clear X, Esc; Enter never reloads)
- `resources/js/components/responsive-list.tsx` (reusable table-on-desktop, expandable-cards-on-phone list: `ResponsiveList`, `ListColumn`)
- `resources/js/components/pagination-bar.tsx` (reusable count + Previous/Next, stacked touch-friendly on phones: `PaginationBar`, `PaginationMeta`)
- Migration: `add_hospital_fields_to_users_table`; Spatie permission migration (unchanged)
- `app/Models/User.php` (HasRoles, new fields, `filterBySearch` for the Filterable trait)
- `app/Services/UserService.php` now holds the legacy CRUD methods (`getAllUsers`, `createUser`, `updateUser`, `deleteUser`...) AND the 1.2 methods (`paginate`, `create`, `update`, `setActive`, `resetPassword`, guards)
- `config/mms_access.php` (permissions, role defaults, job presets, menu sections, retired names: single source of truth)
- `app/Console/Commands/AccessMatrix.php` (`php artisan mms:access-matrix` prints the role-and-permission matrix from the config; `--md` for Markdown)
- `database/seeders/`: `RolePermissionSeeder.php`, `DummyUserSeeder.php`, `SupplierSeeder.php`, `DatabaseSeeder.php`
- `app/Providers/FortifyServiceProvider.php` (employee-number login, inactive block), `AppServiceProvider.php` (last_login_at, section Gates, repository bindings for users and suppliers)
- `app/Http/Middleware/HandleInertiaRequests.php` (shares `auth.permissions` and `auth.sections`)
- `config/fortify.php` (`username` = `employee_no`; `lowercase_usernames` = false; `features` empty)
- `routes/web.php` (`/` redirects to `/login`; dashboard; placeholder routes with `can:section.<n>`; `admin/users` routes point to `Admin\UserController`; `suppliers` routes point to `SupplierController`)
- 1.3: `app/Support/Audit.php`, `app/Support/DisplayTime.php`, `config/mms.php`, `app/Listeners/AuditAuthEvents.php`, `app/Http/Middleware/EnsureUserIsActive.php` (registered in `bootstrap/app.php`), `app/Policies/ActivityPolicy.php`, `app/Services/AuditLogService.php`, `app/Http/Controllers/Admin/AuditLogController.php`, `resources/js/pages/admin/audit-log/index.tsx`; `config/activitylog.php` and the `activity_log` migration from the package
- 1.4: `app/Enums/{Transitionable,PurchaseOrderStatus,DeliveryStatus,DvStatus,ReplacementStatus,DeliveryProgress,DeliveryTiming}.php`, `app/Enums/Concerns/HasTransitions.php`, `app/Support/StatusTransition.php`, `app/Exceptions/InvalidStatusTransition.php`, `tests/Unit/StatusModelTest.php`; `config/mms.php` extended (`property.par_threshold`, `iar.processing_days`, `delivery.notice_days|grace_period_days|term_counting`, `uploads.max_size_kb|allowed_extensions`)
- 2.1: `database/migrations/2026_11_12_100000_create_suppliers_table.php`, `app/Models/Supplier.php`, `database/factories/SupplierFactory.php`, `app/Contracts/SupplierRepositoryInterface.php`, `app/Repositories/SupplierRepository.php`, `app/Services/SupplierService.php`, `app/Policies/SupplierPolicy.php`, `app/Http/Requests/SaveSupplierRequest.php`, `app/Http/Controllers/SupplierController.php`, `resources/js/pages/suppliers/{index,create,edit}.tsx`, `resources/js/components/suppliers/supplier-form.tsx`
- 2.2: `database/migrations/2026_11_12_11{0000_create_item_categories,0100_create_units,0200_create_items}_table.php`, `app/Models/{ItemCategory,Unit,Item}.php`, `database/factories/{ItemCategory,Unit,Item}Factory.php`, `database/seeders/CatalogSeeder.php`, `app/Contracts/{ItemCategory,Unit,Item}RepositoryInterface.php`, `app/Repositories/{ItemCategory,Unit,Item}Repository.php`, `app/Services/{ItemCategory,Unit,Item}Service.php`, `app/Policies/{ItemCategory,Unit,Item}Policy.php`, `app/Http/Requests/Save{ItemCategory,Unit,Item}Request.php`, `app/Http/Controllers/{ItemCategory,Unit,Item}Controller.php`, `resources/js/pages/catalog/{items,categories,units}/{index,create,edit}.tsx`, `resources/js/components/catalog/{item,category,unit}-form.tsx`
- 3.1: `database/migrations/2026_11_13_1{00000_create_purchase_orders,00100_create_po_items,00200_create_po_schedules,00300_create_po_extensions}_table.php`, `app/Enums/PurchaseOrderSource.php`, `app/Exceptions/InvalidStatusTransition.php` (added in 3.1), `app/Support/DueDate.php`, `app/Models/{PurchaseOrder,PoItem,PoSchedule,PoExtension}.php`, `database/factories/{PurchaseOrder,PoItem,PoSchedule,PoExtension}Factory.php`, `database/seeders/PurchaseOrderSeeder.php` (and `DatabaseSeeder.php`), `config/mms.php` (PurchaseOrder in `audit.subject_types`), `tests/Feature/{PurchaseOrderTest,DueDateTest}.php`
- 3.2: `app/Contracts/PurchaseOrderRepositoryInterface.php`, `app/Repositories/PurchaseOrderRepository.php`, `app/Services/PurchaseOrderService.php`, `app/Policies/PurchaseOrderPolicy.php`, `app/Http/Controllers/PurchaseOrderController.php`, `app/Providers/AppServiceProvider.php` (binding), `routes/web.php`, `resources/js/pages/purchase-orders/{index,show}.tsx`, `resources/js/components/purchase-orders/{po-status-badge,overdue-badge-slot}.tsx`, `resources/js/components/purchase-orders/po-format.ts`, `resources/js/components/responsive-list.tsx` and `resources/js/hooks/use-live-search.ts` (extended), `tests/Feature/PurchaseOrderListTest.php`
- 3.4: `app/Contracts/PurchaseOrderProvider.php`, `app/Data/{PurchaseOrderData,PurchaseOrderLineData}.php`, `app/Exceptions/{BizBoxNotConfigured,PurchaseOrderNotFound}.php`, `app/Integrations/PurchaseOrders/{LocalPurchaseOrderProvider,BizBoxPurchaseOrderProvider}.php`, `app/Providers/AppServiceProvider.php` (binding), `config/mms.php` (`bizbox.enabled`), `.env` / `.env.example` (`BIZBOX_ENABLED`), `resources/js/components/purchase-orders/po-form.tsx` (disabled fetch button), `docs/INTEGRATION-BIZBOX.md`, `tests/Feature/PurchaseOrderProviderTest.php`
- 3.3: `app/Support/{PoEditRule,Money}.php`, `app/Http/Requests/SavePurchaseOrderRequest.php`, `PurchaseOrderPolicy.php` (create, update, searchItems), `PurchaseOrderService.php`, `PurchaseOrderRepository.php` and its interface (form methods), `PurchaseOrderController.php` (create, store, edit, update, itemSearch), `routes/web.php`, `config/mms.php` (audit fields), `resources/js/pages/purchase-orders/{create,edit}.tsx`, `resources/js/components/purchase-orders/{po-form,item-search-box}.tsx`, `tests/Feature/PurchaseOrderFormTest.php`
- `app/Support/MmsAccess.php` (only class that reads the shape of `config/mms_access.php` for the user screens; constants for Admin role, base role, self-keep permissions)
- `app/Policies/UserPolicy.php`, `app/Http/Requests/Admin/SaveUserRequest.php`, `ResetUserPasswordRequest.php`
- `app/Services/UserService.php` (create, update, setActive, resetPassword, guards), `app/Http/Controllers/Admin/UserController.php`
- `app/Contracts/UserRepositoryInterface.php`, `app/Repositories/UserRepository.php` (`paginateForAdmin`, `countActiveWithRole`)
- `resources/js/hooks/use-access.ts`, `resources/js/components/app-sidebar.tsx` (MMS menu), `resources/js/components/coming-soon.tsx`
- `resources/js/components/admin/user-form.tsx`, `use-flash-toast.ts`, `confirm-dialog.tsx` (reusable confirmation modal; use it instead of `window.confirm`)
- `resources/js/layouts/app-layout.tsx` mounts the single `<Toaster />`; `HandleInertiaRequests.php` shares `flash.success` (set with `->with('success', '...')`, shown by `useFlashToast()`)
- `resources/js/pages/`: `purchase-orders`, `deliveries`, `property-documents`, `dv-finance`, `reports`, `exceptions/{cancellations,replacements,delays}`, `admin/audit-log` (placeholders); `suppliers/{index,create,edit}.tsx` and `admin/users/{index,create,edit}.tsx` (real; both `index.tsx` pages use the 1.2b components)
- `resources/js/pages/auth/login.tsx`, `resources/js/layouts/auth/auth-simple-layout.tsx`, `public/images/zcmc.png`, `resources/css/app.css` (hides Edge reveal-password icon)
- Settings pages still in place: `profile`, `security`, `appearance`

## Decisions log
- 2026-10-29: 5 roles with per-user permissions; employee-number login; skip Inertia SSR; BizBox integration deferred behind an adapter.
- 2026-11-11: Final flow received. Schedule Term = scheduled delivery dates. "Fetch from BizBox" disabled, structure kept. One consolidated PDF per PO. Emails and automation moved to Phase 11. Supplier ownership question skipped for now. See CONTEXT.md for the full list.
- 2026-11-11: Fortify `lowercase_usernames` turned off so employee numbers match exactly on any database collation.
- 2026-11-11: Access model: no approval permissions; MMS Staff role is a small base and each job is a preset applied as direct permissions; `cancel.finalize` covers cancellation and overdue termination; dummy users one per MMS job (DUMMY-0003 to 0005, 0008, 0009). (34 permissions then; 35 after 2.1.)
- 2026-11-11: Menu and route access: sections in `config/mms_access.php` become Gates `section.<n>`; routes use `can:section.<n>`; the sidebar uses `auth.sections`. Dashboard open to all logged-in users. Reports sits at the end of Main Menu.
- 2026-11-11: Step 1.2 design: role permissions show ticked and locked; job presets and per-person changes are direct permissions; admin types passwords (no email reset); last active Admin cannot be deactivated or demoted.
- 2026-11-11: UI conventions set in 1.2: confirmations are modals (`ConfirmDialog`), not `confirm()`; success messages are toasts via `flash.success`; pages use plain URLs (no Wayfinder imports) and live in `resources/js/pages/...` (components in `resources/js/components/...`; mixing these folders caused a blank page once); new Inertia pages need a Vite restart.
- 2026-11-11: Responsive UI convention for ALL list pages (users, POs, suppliers, deliveries, audit log, ...): table from the `md` breakpoint up; below `md` each row becomes an expandable card (collapsed: main identifier, status badge, one key field; expanded: remaining fields and actions). No horizontal scrolling on phones. Search is live (debounced, server-side, no Enter, no page reload) and pagination keeps the search. Build it once as a reusable component and reuse it.
- 2026-11-11: Step 1.2 build choices: locked role ticks are read from the database roles (filled by `RolePermissionSeeder` from the config); a job preset REPLACES the person's direct permissions; job label in the list is derived (largest preset contained in the user's direct permissions, MMS Staff only), no new column; a user with `users.manage` but not `roles.manage` can create users only as MMS Staff with no direct permissions; direct permissions never duplicate role permissions; password reset also rotates `remember_token`.
- 2026-11-11: Step 1.2b: typing searches use `replace` (no history spam); Previous/Next push history so Back returns to the earlier page with the same search. Cards allow several open at once. Search term is trimmed in the browser and again in the controller.
- 2026-11-12: Step 1.3: Spatie Activitylog `^5.0` (Laravel 13 supported; needs PHP 8.4+). `Audit` writes rows directly through the package `Activity` model (the `activity()` helper does not exist here), all values in `properties` (`old`, `new`, `reason`, `ip`, `subject_label`, `permissions_added/removed`). Logging lives in the Services (and one listener for auth events), not in controllers. Subject label is stored as a snapshot so the log stays readable. App timezone stays UTC; times are converted to Asia/Manila only for display (`config/mms.php` `display_timezone`, `DisplayTime`); date filters are read as Manila days. Deactivated users are logged out on their next request by `EnsureUserIsActive`; deactivation also rotates `remember_token`. Blocked guard attempts are not logged. Audit Log viewing needs `audit.view` (Admin and MMS Head).
- 2026-11-12: Final flowchart (Draft v1.0) received and compared. It is authoritative over the briefing. Changes applied in step 1.4: PO status `for_termination_approval` added and `for_termination` -> `verified` removed (disapproval comes from the approval step); grace period range 3 to 7 calendar days (`grace_period_min_days`, `grace_period_max_days`; the value used, `grace_period_days`, still null); `extension_counting` = working; `ics_min_value` = 1000 added next to `par_threshold` = 50000, both unresolved. Other changes are recorded in CONTEXT.md and the development guide for the steps they affect.
- 2026-11-12: Step 1.4 APPROVED by the user: status model above. Stored vs derived split; all changes through `StatusTransition::apply()` (row lock, reason rules, audit with enum labels, rollback together); `InvalidStatusTransition` is a ValidationException so messages reach the form; permissions stay in Policies, not in the enums. Config: PAR/ICS threshold 50000 (strictly above = PAR), IAR 3 days, notice 3 days, term counting `calendar` (provisional), grace period `null` until MMS answers, upload 10240 KB per file, PDF only. `audit.events` and `audit.fields` unchanged in 1.4: `status_changed` and `status` already exist and `statusChanged()` always uses them.
- 2026-11-12: Step 2.1 Suppliers built and verified. New permission `suppliers.view` (view only) added to MMS Staff, MMS Head and Finance; `suppliers.manage` (Procurement) also allows viewing; section `suppliers` opens with either. Suppliers are deactivated, never deleted; the list shows inactive suppliers with a badge (the PO form in Phase 3 should offer active suppliers only). Simple table, easy to change if IT decides supplier ownership differently (decision 10). Admin has no supplier permission (can be added to the Admin role in the config).
- 2026-11-12: Step 2.2 item catalog built. Permissions `catalog.view` (view only: MMS Staff base, MMS Head, Finance) and `catalog.manage` (Procurement; also allows viewing), one `catalog` section for Items, Categories and Units (37 permissions now). The Inventory Team job preset is NOT changed: `equipment.decide` is the Part 3 per-item decision (step 6.2), which starts from the category flag; the flag itself is catalog data maintained by Procurement. Items are unique on name + unit (the same name may exist with another unit). Deactivate, never delete; new items can only use active categories and units, an existing item keeps its current ones. Audit log name `catalog`; item audit entries show category and unit names, not ids.
- 2026-11-12: Sorting on list pages deferred. Lists are alphabetical by name for now. Build sortable headers once, in Phase 3 for the PO list: clickable `ResponsiveList` headers plus `sort` / `direction` query parameters checked against a fixed list of allowed columns in each repository. Possible later extras for Items: category filter and a show-inactive toggle.

- 2026-11-13: Step 3.1 PO data model built (full detail in CONTEXT.md decision 20). Tables `purchase_orders`, `po_items`, `po_schedules`, `po_extensions`; no delivery tables, no new permissions. The app stays online (localhost in development, hospital server later), plain auto-increment ids, no offline sync; offline can be added later (revisit before 5.1). The due date is stored, counted from the emailed date with `term_counting` and extended with `extension_counting`, only through `App\Support\DueDate`; it is null when there is no emailed date or the term is 0. `closed_at` is stored and set only by a future `syncClosed()` (7.3 and 8.1). Schedule Term stays dates only; quantity per date and item is recommended later as a separate `po_schedule_items` table. Enum `PurchaseOrderSource` (manual, bizbox) added, so the BizBox interface of step 3.4 will be named `PurchaseOrderProvider`. Dev seeder: 60 POs over all 8 statuses with past, future and missing due dates.
- 2026-11-13: Step 3.2 PO list and detail built (details in the 3.2 line above and CONTEXT.md decision 21). The 2026-11-12 sorting decision is applied: `sort` and `direction` query parameters, checked in the repository against a fixed list (po_number, supplier, status, due_date, po_date, items); an unknown value falls back to due date ascending. Status sorts alphabetically by stored value, not by lifecycle. List filters are `search`, `status`, `supplier`, `from`, `to`, `view` (`closed`; absent means active) and `sort` / `direction`; invalid values are ignored silently. The date range filters on PO date (due date would suit chasing deliveries; one-line change). Read only: no create, edit, status or extension buttons until 3.3, 3.5 and 3.6.
- 2026-11-13: Step 3.3 decisions (details in CONTEXT.md decision 22). PO number: trimmed, required, unique, at most 30 characters, letters / digits / `- / . _`, no spaces (lenient; a stricter rule is still to confirm with Procurement). Editable statuses: draft, uploaded, escalated; locked from verified on; BizBox source never editable here. Editing emailed date or term after an extension was approved is allowed: the due date is recalculated with the extensions and the stored extension dates stay as recorded. PO date is required and a PO needs at least one item line. Schedule Term stays dates only (quantity per date: ask MMS later). Category and unit come from the catalog item on the server; an unchanged line keeps its snapshot. Success toast uses `->with('success')` (CONTEXT.md convention); the uploaded `use-flash-toast.ts` reads `flash.toast`, so check which is live if a toast is missing. The BizBox interface of step 3.4 is `PurchaseOrderProvider`.

- 2026-11-13: Step 3.4 BizBox-ready structure built (details in CONTEXT.md decision 23). Interface `PurchaseOrderProvider` takes a `$reference` (the PO number), not an external id, because manual POs have no `external_id`. Local provider reads by `po_number` (trimmed). The button appears on the create form only (not on the PO list; a list button would suggest importing existing POs, which is designed in Phase 13). Data objects leave out category and unit (BizBox probably does not know our catalog ids; the future import maps them). Flag `BIZBOX_ENABLED` defaults to false. All BizBox-specific code is to live only in `BizBoxPurchaseOrderProvider`.

## Open questions
- BizBox (3.4): everything on the BizBox side is unconfirmed; the questions for IT are in `docs/INTEGRATION-BIZBOX.md` (access, authentication, read-only or read-write, how POs are identified, how updates are noticed). `po_items` requires a category and a unit, so the Phase 13 import needs a mapping or a fallback for lines BizBox cannot classify.
- Date range on the PO list: PO date (built) or due date? Easy to switch or to offer both.
- Grace period for Flow C: the flowchart gives 3 to 7 calendar days. One fixed number or per PO? (`mms.delivery.grace_period_days`, null for now.) Also: what "access auto-closes" locks, who reopens it (Head of MMS; new permission, for example `po.reopen`), and the reminder schedule.
- Offline functionality and sync (new in the final flowchart): built for now as an online app (localhost in development, hospital server later; no sync, plain auto-increment ids). To confirm with MMS: does it only mean working when the internet is down but the hospital network is up (a locally hosted app already does this), or encoding on a device disconnected from the server (a large change)? Revisit before 5.1 (IAR numbering).
- PO data model (3.1): contract and donation PO numbers need a unique number assigned; BRAND not stored; line-level cancellation not modeled; `recorded_by` and `approved_by` on extensions; quantity per schedule date and item (see CONTEXT.md decision 20 and Assumptions). PO number rule for contracts and donations (3.3 built the lenient rule; confirm a stricter pattern with Procurement).
- Flow A: approving authority (HOPE / Head / MCC) users and permission; end-user request role; whether Flow C termination uses the same approval.
- Part 3: PAR/ICS limits overlap (flagged in the flowchart); who generates them (flowchart shows PO Reviewer only; the permission comments in `config/mms_access.php` still quote the briefing's 50,000 rule).
- Part 2: which supporting documents are required.
- Part 4: signature + photo handover and DPU clearance; Finance continuation not restated.
- Delivery Term counting: calendar or working days (config default `calendar`, provisional).
- Catalog: should the Inventory Team be able to change a category's `is_equipment` flag (built as no: Procurement only)? Should Admin see the catalog (built as no, like Suppliers)?
- Cancelled vs Terminated meaning (assumed above).
See "Assumptions to confirm" in CONTEXT.md. Includes: who generates PAR / Sub-PAR / ICS (Inventory Team built; slide 17 says PO Reviewer). Forced password change at first login: not for now.

## Known issues
- `php artisan test` needs `extension=pdo_sqlite` and `extension=sqlite3` enabled in the CLI `php.ini` (`php -r "echo php_ini_loaded_file();"` shows which file). Without them every database test fails with "could not find driver". Do not point the tests at the MySQL database: `RefreshDatabase` would wipe it.
- Three kit tests still fail (Phase 10.1): `AuthenticationTest` "users can authenticate using the login screen" and "users are rate limited" (they log in with email; the login uses the employee number), and `ExampleTest` "returns a successful response" (it expects `/` to return 200; `/` redirects to the login page). Two Fortify two-factor tests are skipped because that feature is off.
- Upload limits: `php artisan serve` (used by `composer dev`) reads the CLI `php.ini`, not Apache's. Check the one `php --ini` shows. `post_max_size` must cover all files in one request. 
- `last_login_at` is stored in UTC and the app timezone stays UTC (decided in 1.3). Wherever it is shown, use `DisplayTime::format()`.
- The package config `delete_records_older_than_days` defaults to 365 and `activitylog:clean` is not scheduled until Phase 11. Set it high (or null) in `config/activitylog.php` so the audit trail is never pruned by accident; retention period is still an open question for MMS.
- Other kit tests in `tests/Feature/Auth` may fail because they log in with email; fix in Phase 10.1.
- A blocked page shows Laravel's plain 403 screen; a friendlier Inertia error page can come later.
- Step 1.2 guards (self-deactivate, own Admin role, own `users.manage`/`roles.manage`, last active Admin, unique employee no. error) were not explicitly confirmed by the user; recheck.
- After adding or renaming a permission in `config/mms_access.php`, rerun `php artisan config:clear` and `php artisan db:seed --class=RolePermissionSeeder`; until then existing roles keep the old permissions (seen in 2.1: MMS Staff did not see Suppliers).