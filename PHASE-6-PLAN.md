# PHASE-6-PLAN: Part 3, monitoring and property documents

First written 2026-11-25 (after step 5.3). **Updated 2026-11-25 (after step 6.1 was built and checked)** with: the 6.1b revision (one delivery = one IAR = one set of quantities), provisional defaults that unblock 6.2, and the decision to leave roles and permissions as they are until the real MMS staff are set up.
Replaces Phase 6 of DEVELOPMENT-GUIDE.docx (written 2026-11-12, before Phases 4 and 5 existed).
Sources: DEVELOPMENT-GUIDE.docx, PHASE-4-PLAN-after-4.4.md, PHASE-5-PLAN.md, and the project files as they stand now (CONTEXT.md decisions 1 to 54, PROGRESS.md).
Where this file differs from the guide, this file wins. Same use as before: one step per chat, attach CONTEXT.md and PROGRESS.md (updated after the previous step) plus the files listed.

---

## 1. Where we are

| Step | Status | Result |
|---|---|---|
| 5.0 | Done | `personnel` directory behind `PersonnelDirectory` (roles `inspector`, `end-user`, `property-custodian`). Decision 44. |
| 5.1 | Done | IAR numbering, three series. Decision 46. |
| 5.2 | Done | `deliveries` and `delivery_signatories`: the delivery HEADER (Invoice #, IAR #, Fund Cluster, Amount in centavos, date received, signatories). One delivery = one IAR. Decision 47. |
| 5.3 | Done | DR, OR, IAR files per delivery, completeness gate ("Mark complete" / "Reopen for uploading"), combined PO PDF, IAR window audit event. Decision 48. |
| 6.1 | **Done 2026-11-25** | `delivery_items`, running totals, over-delivery, void, derived PO progress. DeliveryItemTest 29 passed, DeliveryTest 38, DeliveryDocumentTest 24, browser check by the user: working. Decisions 49 to 54. |
| **6.1b** | **Not started (do first)** | Revision found after talking to an MMS staff member: every delivery has its own IAR, quantity and documents. See 6.1b below. |
| 6.2 | **Unblocked on PROVISIONAL defaults** (section 4) | Equipment decision, PAR / Sub-PAR / ICS records. Built so MMS's final answer is a config change. |
| 6.3 | Blocked | Needs 6.2 and the blank PAR, Sub-PAR and ICS forms (scans or PDFs). |

Numbers to remember: 40 permissions, CONTEXT.md decisions run to 54 (the next new one is **55**), the Encoder preset holds `delivery.encode`, `delivery.upload-docs`, `documents.void`; the PO Reviewer preset holds `delivery.update-items`; the Inventory Team preset holds `equipment.decide`, `par.generate`, `ics.generate`.

**Before 6.1b (5 minutes):**
1. Make sure the latest `PROGRESS.md` and `CONTEXT.md` (6.1 done, decisions 49 to 54) are in the project and attached.
2. Optional: run `php artisan test` once and note the result (only the 3 known kit failures are accepted; it was not rerun after 6.1).

---

## 2. What changed since the guide was written

| Guide text (Phase 6) | What exists now | Change to the prompt |
|---|---|---|
| 6.1: "linked to a delivery batch. Create delivery_items." | Built (decisions 49 to 54). | Done. 6.1b adjusts one rule (below). |
| 6.1: "Derive the PO status from the numbers." | `DeliveryProgress` is derived by `DeliveryTotals`, never stored; `verified` shows the progress label. | Done. Unchanged by 6.1b. |
| 6.2: threshold from config. | `config/mms.php` has `property.par_threshold` = 50000 and `property.ics_min_value` = 1000, both provisional. The comments in `config/mms_access.php` still quote the briefing's 50,000 rule. | Build on the provisional rule of section 4 (Q4). Fix those comments. |
| 6.2: "Inventory Team step". | Preset `inventory-team` holds `equipment.decide`, `par.generate`, `ics.generate`, but NO `delivery.*` permission, so it is OUTSIDE `section.deliveries` (decision 44). `item_categories.is_equipment` exists. | 6.2 needs its own section (for example `property`) and its own routes. |
| 6.2: "Sub-PAR naming the actual user from the personnel directory." | The directory has the roles `end-user`, `inspector`, `property-custodian`. | Default: `end-user` (Q6). |
| 6.3: "blank scans or PDFs of the hospital's forms". | dompdf, fonts, `App\Support\Print\*`, `AmountInWords`, the audit pattern (`po_printed`) and the print controller/policy/rule pattern exist from 4.6. Decision 40: generated and downloaded only. | Reuse that code. Do not start a second print stack. |

---

## 3. Carry-over notes (do not lose them)

1. **Section gate.** Admin, MMS Head, Finance and Procurement are outside `section.deliveries`; they see totals and history on the PO Deliveries tab only. The Inventory Team is also outside it (6.2 needs its own section).
2. **Auto-reopen interplay (settled in 6.1, decision 50).** Items already entered stay counted when a delivery is reopened (including the 5.3 auto-reopen); adding or voiding items waits until the delivery is "Documents complete" again.
3. **`syncClosed()`** is still not built (steps 7.3 and 8.1). Its TODO stays in `DeliveryService::void()`. Totals are computed from rows, never stored, so nothing in Phase 6 makes it harder.
4. **Lock order.** Everywhere: PO row first, then delivery row.
5. **Rules are classes** (`PoDocumentRule`, `DeliveryRule`, `DeliveryDocumentRule`, `DeliveryItemRule`): they return a block reason or null; the Policy and the Service both call them (the Service again under locks). A new property rule (6.2) follows the same pattern.
6. **Status changes** only through `StatusTransition::apply()`. Audit through `Audit::log()` with labels added to `config/mms.php`.
7. **Test helper prefixes in use:** `delivery*`, `ddoc_`, `po_doc_*`, `po_types_*`, `ditem_` (6.1 tests; check the real prefix in `DeliveryItemTest`). New tests use a new prefix (`ditem2_` for 6.1b; pick another for 6.2).
8. **Open from Phase 5:** Advance Notice Letter placement; Q4 of Phase 5 (name of the downloaded combined PDF); Q5 of Phase 5 (required supporting documents, default DR, OR, IAR); the IAR window has no consequence (log and event only).
9. **Known gaps kept:** the deliveries list does not use `useLiveSearch` / `PaginationBar`; the PO Documents tab shows the file type as small grey text.
10. **Real data.** Never attach real office files. IAR scans and the real sequencing sheet stay out of chats; use sanitized samples.
11. **Environment.** Windows, PowerShell, Pest. Code is written without running it, so the first test run may show small errors in the tests themselves: paste the output and the session fixes it. Tests use SQLite; the `deliveries` foreign-key migration skips SQLite.
12. **Roles and permissions are left as they are (decision of 2026-11-25).** All current users are dummy users. Before go-live, ask the real MMS staff what each person does and set their job presets then. The system already allows a user to hold more than one job (CONTEXT.md, access rules), so a colleague can cover an absent staff member by adding a job, and the audit log records the change. Open question to add (see section 7): who records the delivery (IAR number, documents) and who enters the quantities, and who covers for whom. Today: the Encoder records the delivery and files, the PO Reviewer enters the quantities (`delivery.update-items`). Do NOT change permissions in 6.1b.

---

## 4. Questions, with the defaults we will build on

### 6.1 (all built; kept for the record)

| # | Question | Built as |
|---|---|---|
| Q1 | Which deliveries accept delivered items? | Only a `complete` delivery. Items stay counted if it is reopened. |
| Q2 | What is the date on each item line? | Built as a date per line. **Superseded by 6.1b**: the row date comes from the delivery's date received. |
| Q3 | Over-delivery? | Needs a tick and a reason (5 to 500 characters); audited and badged. No new permission. |

### 6.2 and 6.3: PROVISIONAL defaults (build now, confirm with MMS later)

These are NOT final. They are built so that MMS's final answer is a configuration change, not a rewrite.

| # | Question | Provisional default | How it stays easy to change |
|---|---|---|---|
| Q4 | PAR / ICS rule (briefing: above 50,000 = PAR + Sub-PAR, at or below = ICS; flowchart: PAR mandatory for equipment, ICS from 1,000, below 1,000 neither, remarks mandatory) | Combine them. Equipment with unit price **above 50,000** gets a PAR plus Sub-PAR. Equipment from **1,000 up to and including 50,000** gets an ICS. Below **1,000** neither applies and remarks are mandatory. | The limits are read only from `config/mms.php` (`property.par_threshold`, `property.ics_min_value`, or a `property.bands` list if the session finds that cleaner; it tells me which before coding). One rule class is the only place that applies them. A flag `property.provisional` = true. |
| Q5 | Who generates PAR / Sub-PAR / ICS | The Inventory Team (`equipment.decide`, `par.generate`, `ics.generate`, already in its preset). | A permission can be added to any user at any time. |
| Q6 | Where the Sub-PAR "actual user" comes from | The personnel directory, role `end-user`. | One constant. |
| Q7 | Numbering of PAR, Sub-PAR, ICS | Reuse the IAR pattern of step 5.1: the system suggests the next number, staff confirm or edit, the database rejects duplicates, voided numbers are kept and never reused, the Admin sets the starting counter. One series per document type per year. Prefix, padding and the series list live in config. | Config only. The session must show the format and ask before building it. |
| Q8 | Printed forms stored or only generated? Paper? | Generated and downloaded only (as the printed PO, decision 40), paper `bond` as in `print.paper` (`a4` available). | One config value. Only matters for 6.3. |

**Safeguards that apply to every provisional default**
1. **Snapshot on each record.** Each property record stores the unit price, the document type and band that applied, and a `rule_provisional` marker. When MMS changes the rule, existing records stay as they are; only new decisions use the new limits.
2. **Wrong record = void and reissue.** Use the existing pattern (void with a reason, then create the right document). No in-place edit.
3. **A visible notice** on the property screens: "Thresholds and numbering are provisional, pending MMS confirmation." The notice disappears when `property.provisional` is set to false.
4. **Boundary tests:** 999.99, 1,000, 50,000 and 50,000.01. Changing the config breaks a test on purpose, so the change is noticed.
5. **Open questions stay open.** Q4 to Q8 stay in PROGRESS.md "Open questions" as "built on provisional defaults, confirm with MMS". One thing to verify with Accounting or the Inventory Team: that 50,000 is the right line (probably the PPE line under the current COA rules; not verified) and that the 1,000 floor is correct.

---

## 5. Paste at the top of every session

> Attach CONTEXT.md and PROGRESS.md (updated after the previous step).
>
> I'm Vvv, building the MMS Procurement Monitoring System (Laravel + React/Inertia starter kit, TypeScript, Tailwind, Spatie permissions, MySQL) for the Materials Management Section at ZCMC. I'm new to React, so explain React and Inertia parts briefly in plain words.
>
> Rules: (1) Work only on the step named below. Do not change completed steps except where this step needs it. (2) Give complete files with full paths, only new or changed files, and exact PowerShell commands (config:clear, route:clear, wayfinder:generate, npm run build, restart composer dev). (3) Follow CONTEXT.md; if something is missing or conflicts, ask me before guessing. (4) No email: where the flow says "email", fire a Laravel event or record a pending notice only. (5) BEFORE CODING, show me the migration summary, new permissions, routes and rules, and wait for my OK. Tell me before adding a library, a permission or a migration. (6) Tell me which existing tests your change could break, and say so if you changed an existing test. (7) The code may not be run in the session: the first test run can show small errors in the tests, I will paste the output and you fix it; say clearly what was and was not run. (8) At the end give: how to test (what to click, what I should see), the updated PROGRESS.md and CONTEXT.md (new decision numbers start at **55**) as find-and-replace blocks, and the files to attach next time. (9) If the chat runs long, stop at a safe point, update PROGRESS.md with what is done and the next micro-step. (10) Environment: Windows, PowerShell, Pest with SQLite; if a test needs a real program (qpdf), use a closed temp file and skip the test when the program is missing. (11) Start your answer with: files you used, files changed, files created.
>
> Project rules to keep: Controller -> Service -> Repository, Policies and Form Requests; rules in `App\Support\*Rule` classes used by both Policy and Service; every status change through `StatusTransition::apply()`; audit through `App\Support\Audit`; money in centavos through `App\Support\Money`; quantities through `App\Support\Quantity`; PO row locked before delivery row; derived values are computed, never stored (decision in 1.4).

---

## 6. Steps

### 6.1 Update delivered items (running totals)

**Status: DONE 2026-11-25.** See CONTEXT.md decisions 49 to 54 and PROGRESS.md. The original prompt was removed from this file because it is built; the one rule that changes is handled by 6.1b.

---

### 6.1b One delivery = one IAR = one set of quantities (revision of 6.1)

**Status:** Not started. Do this BEFORE 6.2.

**Why.** An MMS staff member who processes POs said: every delivery comes with its own documents and gets its own new IAR number. Example: Potato, ordered 1,000, first delivery 500. The second delivery must be a NEW delivery record with a NEW IAR, its own quantity and its own documents. In the system, a PO Reviewer who opens delivery #1 and clicks "Update delivered items" can add the next 500 inside delivery #1, so both rows sit under the first IAR. Decision 49 allowed this ("a line may arrive on several dates inside one delivery") and Q2 gave each row its own date. That does not match the real process. Phases 4 and 5 are NOT reopened: 5.2 already supports many deliveries per PO, each with its own IAR (reserved at save, locked afterwards, duplicates rejected) and its own files.

**Dependencies:** 6.1 (built), 5.2, 5.3.

**Attach**

- CONTEXT.md, PROGRESS.md
- The 6.1 code: `DeliveryItem.php`, `DeliveryItemRule.php`, `DeliveryItemService.php`, `DeliveryItemRepository.php` and its interface, `DeliveryItemController.php`, `SaveDeliveryItemsRequest.php`, `DeliveryTotals.php`, `Quantity.php`, `DeliveryPolicy.php`, `DeliveryService.php` (`panel()`, `void()`)
- React: `resources/js/pages/deliveries/items/create.tsx`, `resources/js/pages/deliveries/show.tsx`, `resources/js/components/deliveries/{delivery-items-panel,delivery-line-history,po-deliveries-panel,po-progress-badge,delivery-types.ts}`
- Tests as patterns and to update: `DeliveryItemTest.php`, `DeliveryTest.php`, `DeliveryDocumentTest.php`
- `config/mms.php`, `routes/web.php`

**Prompt**

> Step 6.1b: one delivery = one IAR = one set of quantities (revision of step 6.1). Read CONTEXT.md decisions 47 and 49 to 54 first.
>
> WHY: every real delivery has its own IAR, quantity and documents. Today a PO Reviewer can add the next 500 of a line inside the first delivery, so two real deliveries share one IAR.
>
> WHAT TO CHANGE (6.1 only; do not touch 5.1, 5.2 or 5.3 logic):
> 1. One quantity per PO line per delivery. A delivery may have at most ONE ACTIVE `delivery_items` row per PO line. Saving a second active row for the same line in the same delivery is refused with a clear message: "This line already has a quantity on this IAR. Void it and enter it again, or record a new delivery with a new IAR." Enforce it in `DeliveryItemRule` / `DeliveryItemService` under the existing locks (PO row, then delivery row) and in the form. A voided row does not count, so a corrected line can be entered again. Do NOT add a unique database index (voided rows share the same line).
> 2. Remove the per-row date input from the Update delivered items form. The row's `delivered_on` is set from the delivery's `date_received` (no migration; keep the column). Existing rows keep their dates. Keep the rules that the date is not in the future and not before the PO date by relying on the delivery's own date checks (tell me if a check is lost).
> 3. Make the screen explain the process. Delivered items panel on the delivery page: show the IAR number in the heading and a short note: "One IAR = one delivery. If more items arrive later with a new IAR, record a new delivery from the PO page." Show a "Record another delivery" link to `deliveries.create?purchase_order=ID` ONLY to users who hold `delivery.encode`; others see the note without the link ("Ask the Encoder to record it"). PO page, Deliveries tab: add the IAR number and delivery status to each history row, grouped by delivery, so 500 / 300 / 200 appear as three IARs with the running total. The totals logic (`DeliveryTotals`) stays the same.
> 4. Do NOT change permissions, sections, PO status, totals or over-delivery logic. The Encoder still records the delivery and files; the PO Reviewer (`delivery.update-items`) still enters quantities. Do NOT add quantities to the 5.2 form. (Roles are dummy for now and will be confirmed with the real staff later; see PHASE-6-PLAN section 3, note 12.)
>
> 5. Existing rows that already break the rule (two active rows for one line in one delivery, for example dummy delivery 26-7017 with 500 and 200) stay as they are; the rule only blocks NEW saves. Do not migrate or void them. Tell me how the panel shows them, and that they still count in every total.
>
> BEFORE CODING, tell me and wait for my OK: the exact rule change, which existing tests change and why, and whether any route or prop changes.
>
> Pest tests (new prefix `ditem2_`) and rerun `DeliveryItemTest`, `DeliveryTest`, `DeliveryDocumentTest`: a second active row for the same line in the same delivery is refused and the first stays; after voiding the first row the line can be entered again; two deliveries of one PO, 500 then 300 (different IARs), give totals 500 / 800 and progress "Partially delivered", a third of 200 gives "Fully delivered"; existing `DeliveryItemTest` cases that saved two rows for one line in one delivery are updated to the new rule (tell me which and why); the "Record another delivery" link shows only with `delivery.encode`; the row date equals the delivery's date received.
>
> Deliver: files changed, short command list (migrate is not needed; `wayfinder:generate` and `npm run build` probably are), CONTEXT.md decision 55 (supersedes the "same line on several dates inside one delivery" part of decision 49 and the date part of Q2), and a PROGRESS.md entry. Ask before inventing anything not listed here.

**Baseline (2026-11-25, run by the user before 6.1b):** `DeliveryItemTest` 29 passed (341 assertions), `DeliveryTest` 38 passed (230), `DeliveryDocumentTest` 24 passed (158). Any failure after 6.1b comes from this change.

**Existing tests that will probably need a change** (guessed from the test names; the session must say which it changed and why):
- "it shows the right totals and progress after each save of 500, 300 and 200 pieces of 1,000": if it saves all three in one delivery, it becomes three deliveries with three IARs.
- "it keeps each line on its own date in one save": the per-row date goes away, so this is replaced.
- "it refuses a delivery date in the future or before the PO date": the date now comes from the delivery's date received, so the check moves to the delivery tests (or stays only where it still applies).
- "it counts every delivery of the PO in the running total of a line": should keep working; check it.
- "it keeps items counted when the delivery is reopened...", the void-row tests and "it voids a row with a reason...": may fail if they add several rows for one line in one delivery.
- Possibly "it sends the update form, the delivery panel, the PO tab and the PO list badge their numbers" (props change) and "it hides the Update button and says why..." (new note and link).

**What the screens look like today (browser check 2026-11-25):** the update form says "Tick each PO line that arrived, then type the quantity delivered today and its date" and has a "Delivery date" field; the delivery page shows delivery 26-7017 with two rows under one IAR (500 and 200) and an Update button. 6.1b removes the date field, rewords that text, adds the "One IAR = one delivery" note and (for `delivery.encode`) the "Record another delivery" link. The old rows of 26-7017 stay and still count.

**How I will check it (to put in PROGRESS.md):** record delivery A (IAR 1) for a PO, upload DR/OR/IAR, mark complete, enter 500 of a 1,000 line as the PO Reviewer; try to enter another 500 on the same delivery and see the refusal; record delivery B (new IAR), complete it, enter 500 there; the PO Deliveries tab shows two IARs and totals 500 then 1,000 "Fully delivered"; void the first row and enter it again.

---

### 6.2 Equipment decision and property records

**Status:** Not started. UNBLOCKED on the provisional defaults of section 4 (Q4 to Q7). Do after 6.1b.

**Dependencies:** 6.1 and 6.1b, 5.0 (personnel directory), section 4 defaults.

**Attach**

- CONTEXT.md, PROGRESS.md, the briefing PDF pages 8 and 9, `MMS_PO_to_DV_Workflow.pdf` (Part 3)
- Models: `ItemCategory.php`, `Item.php`, `PoItem.php`, `PurchaseOrder.php`, `Delivery.php`, `DeliveryItem.php`; the personnel files from 5.0 (`PersonnelDirectory`, `LocalPersonnelDirectory`, `PersonData`, `PersonnelController`, `PersonnelPolicy`)
- `config/mms.php` (the `property.*` keys), `config/mms_access.php` (presets `inventory-team`, `po-reviewer`; the `sections` list), `AppServiceProvider.php`, `routes/web.php`
- `IarNumberService.php`, the IAR models and the IAR numbers admin screen (the numbering pattern), `Audit.php`, `StatusTransition.php`, `DeliveryTotals.php`
- A reference module for list pages and pickers: `delivery-form.tsx`, `personnel-picker.tsx`

**Prompt**

> Step 6.2. Read CONTEXT.md (Part 3 notes, decision 44 about the personnel directory and the Inventory Team, decision 19 about the category flag, decisions 49 to 55 about delivered items) and the final flowchart.
>
> MMS has NOT given the final rule yet. Build on these PROVISIONAL defaults, keep them in configuration, and mark them provisional everywhere:
> - **Rule:** for equipment, unit price ABOVE 50,000 = PAR plus Sub-PAR; from 1,000 up to and including 50,000 = ICS; below 1,000 = neither, with mandatory remarks / justification. Read the limits only from `config/mms.php` (`property.par_threshold`, `property.ics_min_value`, or a `property.bands` list if cleaner; tell me which before coding) and add `property.provisional` = true. One rule class (for example `PropertyDocumentRule`) is the only place that applies them. Correct the comments in `config/mms_access.php` that still quote the 50,000 rule.
> - **Who:** the Inventory Team (`equipment.decide`, `par.generate`, `ics.generate`). It is outside `section.deliveries`, so propose a `property` section and its routes.
> - **Sub-PAR user:** chosen from the personnel directory, role `end-user`.
> - **Numbering:** reuse the IAR pattern of step 5.1 (system suggests, staff confirm or edit, the database rejects duplicates, voided numbers kept and never reused, Admin sets the starting counter, one series per document type per year, prefix and padding in config). Show me the exact format you propose and ask before building it.
>
> BEFORE CODING, tell me and wait for my OK: the migrations (property document tables; one header table or three; per delivered line; status; numbering columns), new permissions or sections, the rule class and config keys, and where the numbering lives.
>
> Build: for each delivered line (from 6.1), decide "Equipment?" (default from `item_categories.is_equipment`, editable by the person who generates, recorded with a reason when it differs from the category). For equipment apply the rule to the unit price and create the PAR (and Sub-PAR with the actual user) or the ICS; below the ICS limit neither applies and remarks are mandatory. Each property record SNAPSHOTS the unit price (centavos), the document type and band that applied, and a `rule_provisional` marker, so a later change of the limits never rewrites old records. A wrong record is voided with a reason and reissued, never edited. Show a visible notice on the property screens ("Thresholds and numbering are provisional, pending MMS confirmation") that disappears when `property.provisional` is false. Create the tables, a "Property documentation pending" list (delivered equipment lines without their document), audit, and tests. Existing flows must not change.
>
> Tests (new helper prefix): boundary cases 999.99 (neither, remarks required), 1,000 (ICS), 50,000 (ICS), 50,000.01 (PAR + Sub-PAR); changing the config limits changes the result for NEW decisions and leaves old records untouched; numbering is unique and a voided number is never reused; 403 for roles without the permission; the Sub-PAR user must be an active `end-user` of the directory; the equipment flag override needs a reason; a voided delivery or voided delivered row is not offered for a property document.
>
> Done when: the examples above give the right document, numbering is unique, permissions return 403, the provisional notice shows, and the tests pass.

---

### 6.3 Printable PAR, Sub-PAR, ICS

**Status:** Blocked: needs 6.2 and the blank forms (scans or PDFs, sanitized).

**Dependencies:** 6.2, the hospital's blank PAR, Sub-PAR and ICS forms, Q8 (default: generated and downloaded only, paper `bond`).

**Attach**

- CONTEXT.md decisions 32, 33, 40 and 45 and the latest `docs/PO-PRINT-SPIKE.md`
- Blank sanitized scans or PDFs of the three forms (no real names or numbers)
- The 4.6 print code: `app/Support/Print/*` (Form13, Paginator, Renderer, Html, Fonts, Paper, Config and the others), `PoPrintService.php`, `PoPrintController.php`, `PoPrintRule.php`, `PurchaseOrderPolicy::printOrder`, `AmountInWords.php`, the print tests, the fonts folder reference, `public/images/zcmc.png`
- The property document models and service from 6.2, `config/mms.php` (the `print.*` keys), `config/mms_access.php`, `routes/web.php`, the property page where the Print button will sit

**Prompt**

> Step 6.3. Build print-ready PAR, Sub-PAR and ICS that match the attached blank forms (layout, fields, signature areas), reusing the dompdf stack of step 4.6 (fonts, `Renderer`, `Html::lines()` with a fixed line height, own pagination). Do not add a new library. Fill the fields from the system data; missing values print empty, never invented; no budget stamp.
>
> Approvals I give: paper size = bond (config value, `a4` available); the printed document is only generated and downloaded, as decision 40 (provisional until MMS confirms, Q8); permission = reuse `par.generate` and `ics.generate`. Audit every generation with a new event (for example `property_printed`, labels in `config/mms.php`), subject the document. Tell me which existing tests could break. Show me a description or screenshot of how each page prints.
>
> Tests: the generated PDF contains the document number, item, unit price, total and the signer names; page count for a long item list (use `App\Support\Qpdf::pageCount`); 403 for roles without the permission; a voided or non-existent document is refused.
>
> Done when printed pages look like the real forms with the right data and the tests pass.

---

## 7. Edits to make in your own files

### PROGRESS.md

Replace the Phase 6 lines with:

```
## Phase 6: Part 3, monitoring and property documents (plan: PHASE-6-PLAN.md)
- [x] 6.1 Update delivered items (running totals). Built 2026-11-25, DeliveryItemTest 29 passed (341 assertions), browser check by the user: working; CONTEXT.md decisions 49 to 54.
- [ ] 6.1b One delivery = one IAR = one set of quantities (revision of 6.1). Found 2026-11-25 after talking to an MMS staff member: every delivery has its own IAR, quantity and documents. At most one active row per PO line per delivery; row date = delivery date received; screen explains "record a new delivery for a new IAR". No permission change. CONTEXT.md decision 55.
- [ ] 6.2 Equipment decision and property records. UNBLOCKED on PROVISIONAL defaults (PHASE-6-PLAN section 4); confirm with MMS later (config change only).
- [!] 6.3 Printable PAR / Sub-PAR / ICS. Needs 6.2 and the blank forms.
```

Add to "Open questions":

```
- 2026-11-25 (roles): all users are dummy users. Before go-live confirm with the real MMS staff who records the delivery (IAR number, documents), who enters the quantities, and who covers for whom. A user may hold several jobs (cover for an absent colleague); nothing to build. Today: Encoder records the delivery, PO Reviewer enters the quantities.
- 2026-11-25 (6.2, PROVISIONAL): Q4 to Q7 of PHASE-6-PLAN.md are built on provisional defaults (above 50,000 = PAR + Sub-PAR; 1,000 to 50,000 = ICS; below 1,000 neither, remarks required; Inventory Team generates; Sub-PAR user = end-user; numbering like the IAR pattern). Confirm with MMS (and with Accounting that 50,000 and 1,000 are the right lines); the final answer is a config change.
- 2026-11-25 (6.3): Q8 (stored or only generated, paper size; default generated and downloaded, bond) and the blank PAR, Sub-PAR and ICS forms.
```

Remove the older open-question lines for Q1 to Q3 and Q4 to Q7 of this plan if they are still there.

### CONTEXT.md

Add after decision 54 (renumber if you added others):

```
55. **One delivery = one IAR = one set of quantities (step 6.1b, planned 2026-11-25; fill in when built):** after talking to an MMS staff member: every delivery has its own documents and its own IAR. A delivery has at most ONE ACTIVE `delivery_items` row per PO line (a voided row does not count; no unique index). The row's `delivered_on` comes from the delivery's `date_received`. A second quantity for the same line belongs to a NEW delivery with a NEW IAR, recorded by the Encoder from the PO page. Supersedes the "same line on several dates inside one delivery" part of decision 49 and the date part of Q2. Permissions unchanged.
56. **Phase 6.2 on provisional defaults (planned 2026-11-25; fill in when built):** MMS has not given the final PAR / ICS rule. Built on: equipment above 50,000 = PAR + Sub-PAR; 1,000 up to and including 50,000 = ICS; below 1,000 neither, remarks required. Limits only in `config/mms.php` with `property.provisional` = true, applied by one rule class; each property record snapshots the price, document type, band and `rule_provisional`; wrong records are voided and reissued; numbering follows the IAR pattern per document type and year. Inventory Team generates; Sub-PAR user = personnel directory role `end-user`. Confirm with MMS later; the final rule is a config change.
57. **Roles are dummy for now (2026-11-25):** all current users are dummy users. Job presets and permissions stay as built until the real MMS staff are set up; then confirm each person's job. A user may hold several jobs (absence cover), already supported.
```

---

## 8. Order to follow

1. Section 1 "Before 6.1b" checklist.
2. Run **6.1b** in its own chat (prompt in section 6, attachments listed there).
3. Run **6.2** on the provisional defaults, in its own chat. Send Q4 to Q7 to MMS in the meantime, so the final answer can replace the defaults by changing the config.
4. **6.3** waits for the blank PAR, Sub-PAR and ICS forms. If it stays blocked, the next unblocked work is Phase 7 (7.1 checklist review and MMS Head override, 7.2 DV, 7.3); read its rows in the guide's "Changes in the final flowchart" table first. Phase 6 does not block 7.1.
5. After each step: update PROGRESS.md and CONTEXT.md, run `php artisan test`, and tick the box only after the tests pass and you have checked it in the browser.