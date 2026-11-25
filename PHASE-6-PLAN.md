# PHASE-6-PLAN: Part 3, monitoring and property documents

Written 2026-11-25, after step 5.3 was built (DeliveryDocumentTest 24 passed, browser check by the user: working).
Replaces Phase 6 of DEVELOPMENT-GUIDE.docx (written 2026-11-12, before Phases 4 and 5 existed).
Sources: DEVELOPMENT-GUIDE.docx, PHASE-4-PLAN-after-4.4.md, PHASE-5-PLAN.md, and the project files as they stood when step 5.3 was written (CONTEXT.md decisions 1 to 48, PROGRESS.md).
Where this file differs from the guide, this file wins. Same use as before: one step per chat, attach CONTEXT.md and PROGRESS.md (updated after the previous step) plus the files listed.

---

## 1. Where we are

| Step | Status | Result |
|---|---|---|
| 5.0 | Done | `personnel` directory behind `PersonnelDirectory` (roles `inspector`, `end-user`, `property-custodian`). Decision 44. |
| 5.1 | Done | IAR numbering, three series. Decision 46. |
| 5.2 | Done | `deliveries` and `delivery_signatories`: the delivery HEADER only (Invoice #, IAR #, Fund Cluster, Amount in centavos, date received, signatories). Decision 47. |
| 5.3 | Done 2026-11-25 | DR, OR, IAR files per delivery, completeness gate ("Mark complete" / "Reopen for uploading"), PO combined PDF with delivery files, IAR window audit event. No migration, no new permission. Decision 48. |
| 6.1, 6.2, 6.3 | Not started | See below. |

Numbers to remember: 40 permissions, CONTEXT.md decisions run to 48 (the next new one is 49), the Encoder preset holds `delivery.encode`, `delivery.upload-docs`, `documents.void`.

**Before 6.1 (do these first, 15 minutes):**
1. Run `php artisan test` once and note the result in PROGRESS.md (only the three known kit failures are accepted).
2. Copy the latest `PROGRESS.md` and `CONTEXT.md` (step 5.3 ticked, decision 48) into the project, and make sure the small change (the file-type label on the delivery page) is in.
3. Decide or accept the defaults of Q1 to Q8 (section 4). Q1 and Q2 shape the data model of 6.1.

---

## 2. What changed since the guide was written

| Guide text (Phase 6) | What exists now | Change to the prompt |
|---|---|---|
| 6.1: "linked to a delivery batch. Create delivery_items." | `deliveries` exists (5.2) and holds only the header. Decision 43: per-item delivered quantities (decimal) belong to 6.1. `po_items.quantity_ordered` is an unsigned integer; `po_items.term_quantity` and `po_schedule_items` hold the planned split per delivery column (3.3c). | Name the real tables and models. Delivered quantity is a decimal (kilo, bundle). The planned split (`po_schedule_items`) is NOT used by 6.1; it is for 8.3 (missed batch vs delivered). |
| 6.1: "Derive the PO status (partially or fully delivered) from the numbers." | `DeliveryProgress` enum (not delivered / partial / full) exists since 1.4 and is DERIVED, never stored. List and dashboard badge for a PO: stored status, except `verified` shows the progress label. | 6.1 computes the progress and feeds the badge on the PO list and detail page. Nothing is added to `purchase_orders.status`. |
| 6.1 does not say when a delivery may receive items. | `DeliveryStatus` is `for_uploading`, `complete`, `voided`. The guide says the delivery "moves on to Part 3" once its documents are complete (5.3). 5.3 can send a complete delivery back to `for_uploading` automatically when a required file is voided without a replacement. | Ask first (Q1). Default below. This is the main interaction with 5.3. |
| 6.1: voiding. | `DeliveryService::void()` voids the delivery, its IAR number and (5.3) its files. It has a marked TODO for `PurchaseOrderService::syncClosed()` (built with 7.3 and 8.1). | A voided delivery's items drop out of every total. `syncClosed()` is still NOT built in 6.1. |
| 6.2: threshold from config. | `config/mms.php` has `property.par_threshold` = 50000 and `property.ics_min_value` = 1000, both provisional. The comments in `config/mms_access.php` still quote the briefing's 50,000 rule. | Unchanged: do not build 6.2 until MMS gives ONE rule (Q4). |
| 6.2: "Inventory Team step". | Preset `inventory-team` holds `equipment.decide`, `par.generate`, `ics.generate`. It holds NO `delivery.*` permission, so it is OUTSIDE `section.deliveries` (noted in decision 44). `item_categories.is_equipment` exists (Procurement edits it); the per-item override belongs to 6.2. The final flowchart shows only a PO Reviewer lane in Part 3. | 6.2 needs its own section (for example `property`) and its own routes. Who generates PAR / ICS is still open (Q5). |
| 6.2: "Sub-PAR naming the actual user chosen from the personnel directory." | The directory has the role `end-user`, `inspector` and `property-custodian`, not a role "actual user". | Ask which role the Sub-PAR user comes from (Q6). Default: `end-user`. |
| 6.3: "blank scans or PDFs of the hospital's forms". | dompdf, the fonts, `App\Support\Print\*` (Form13, Paginator, Renderer, `Html::lines()` with a fixed line height), `AmountInWords`, the audit event pattern (`po_printed`) and the print controller/policy/rule pattern exist from 4.6. Decision 40: the printed PO is generated and downloaded only, never stored. Print PO is switched off in the UI (decision 45) but the code stays. | Reuse that code. Do not start a second print stack. Ask whether printed PAR/ICS are stored (Q7). |

---

## 3. Carry-over notes from Phases 4 and 5 (do not lose them)

1. **Section gate.** Admin, MMS Head, Finance and Procurement are outside `section.deliveries`, so the View links of the PO Deliveries tab give them 403 (a 5.2 limitation, unchanged by 5.3). The PO Reviewer IS inside it (`delivery.update-items`). If MMS wants the Head to see item totals, widen the section or put the totals on the PO page (6.1 does the second anyway).
2. **Auto-reopen interplay.** Voiding a required file of a complete delivery sends it back to `for_uploading` (5.3). If 6.1 only accepts items on `complete` deliveries, the rule for "what happens to items already entered" must be written down (Q1).
3. **`syncClosed()`** is still not built. It is called after a delivery is voided or reopened, and after DV payment (7.3, 8.1). 6.1 must not build it, but must not make it harder: totals are computed from rows, never stored on the PO.
4. **Lock order.** Everywhere: PO row first, then delivery row. A new item service follows it.
5. **Rules are classes.** `PoDocumentRule`, `DeliveryRule`, `DeliveryDocumentRule` return a block reason or null; the Policy and the Service both call them (the Service again under locks). A new `DeliveryItemRule` follows the same pattern.
6. **Status changes** only through `StatusTransition::apply()`. Audit through `Audit::log()` with labels added to `config/mms.php`.
7. **Test helper prefixes in use:** `delivery*` (DeliveryTest), `ddoc_` (DeliveryDocumentTest), `po_doc_*`, `po_types_*`. New tests use a new prefix (for example `ditem_`).
8. **Open from Phase 5:** Advance Notice Letter placement (optional delivery type now; the flowchart files it under Flow B); Q4 (name of the downloaded combined PDF, default unchanged); Q5 (required supporting documents, default DR, OR, IAR; to change it set `required => true` on a line of `documents.types`); the IAR window has no consequence (log and event only).
9. **Known gaps kept:** the deliveries list does not use `useLiveSearch` / `PaginationBar`; the PO Documents tab shows the file type as small grey text (the delivery page shows it as a label).
10. **Real data.** Never attach real office files. The IAR scans and the real sequencing sheet stay out of chats; use sanitized samples.
11. **Environment.** Windows, PowerShell, Pest. Code is written without running it, so the first test run may show small errors in the tests themselves: paste the output and the session fixes it. Tests use SQLite; the `deliveries` foreign-key migration skips SQLite.

---

## 4. Questions to settle (ask MMS, or accept the default)

| # | Question | Default if nobody answers | Blocks |
|---|---|---|---|
| Q1 | Which deliveries accept delivered items? | Only a `complete` delivery (documents done = "moved on to Part 3"). Items already entered stay counted if the delivery is later reopened; adding or voiding items needs `complete` again. | 6.1 |
| Q2 | What is "date" on each item line, and how is it limited? | A date per line (guide: "each with its own delivery date"): not in the future (Manila date), not before the PO date. It may differ from the delivery's date received. | 6.1 |
| Q3 | Over-delivery: who may allow it and what is needed? | Anyone with `delivery.update-items` may enter more than ordered ONLY by ticking "over-delivery" and writing a reason (5 to 500 characters); it is audited and shown with a badge. No new permission. | 6.1 |
| Q4 | PAR / ICS rule: ONE rule from MMS. Briefing: above PHP 50,000 PAR + Sub-PAR, at or below ICS. Flowchart: PAR mandatory for equipment, ICS from PHP 1,000, below 1,000 neither, with mandatory remarks. | None. Do not build 6.2 without it. | 6.2 |
| Q5 | Who generates PAR / Sub-PAR / ICS: the Inventory Team (built as such) or the PO Reviewer (flowchart lane)? | Inventory Team (`equipment.decide`, `par.generate`, `ics.generate` already exist in its preset). | 6.2 |
| Q6 | Where does the Sub-PAR "actual user" come from? | The personnel directory, role `end-user`. | 6.2 |
| Q7 | Document numbering of PAR, Sub-PAR and ICS (format, series per year, who may reset). | None. The step asks before inventing a scheme (reuse the 5.1 pattern if MMS agrees). | 6.2 |
| Q8 | Are printed PAR / Sub-PAR / ICS only generated and downloaded (like the printed PO, decision 40) or stored as documents? Which paper size? | Generated and downloaded only; paper `bond` as in `print.paper`, `a4` available. | 6.3 |

---

## 5. Paste at the top of every session

> Attach CONTEXT.md and PROGRESS.md (updated after the previous step).
>
> I'm Vvv, building the MMS Procurement Monitoring System (Laravel + React/Inertia starter kit, TypeScript, Tailwind, Spatie permissions, MySQL) for the Materials Management Section at ZCMC. I'm new to React, so explain React and Inertia parts briefly in plain words.
>
> Rules: (1) Work only on the step named below. Do not change completed steps except where this step needs it. (2) Give complete files with full paths, only new or changed files, and exact PowerShell commands (config:clear, route:clear, wayfinder:generate, npm run build, restart composer dev). (3) Follow CONTEXT.md; if something is missing or conflicts, ask me before guessing. (4) No email: where the flow says "email", fire a Laravel event or record a pending notice only. (5) BEFORE CODING, show me the migration summary, new permissions, routes and rules, and wait for my OK. Tell me before adding a library, a permission or a migration. (6) Tell me which existing tests your change could break, and say so if you changed an existing test. (7) The code may not be run in the session: the first test run can show small errors in the tests, I will paste the output and you fix it; say clearly what was and was not run. (8) At the end give: how to test (what to click, what I should see), the updated PROGRESS.md and CONTEXT.md (new decision number 49 and up) as find-and-replace blocks, and the files to attach next time. (9) If the chat runs long, stop at a safe point, update PROGRESS.md with what is done and the next micro-step. (10) Environment: Windows, PowerShell, Pest with SQLite; if a test needs a real program (qpdf), use a closed temp file and skip the test when the program is missing. (11) Start your answer with: files you used, files changed, files created.
>
> Project rules to keep: Controller -> Service -> Repository, Policies and Form Requests; rules in `App\Support\*Rule` classes used by both Policy and Service; every status change through `StatusTransition::apply()`; audit through `App\Support\Audit`; money in centavos through `App\Support\Money`; PO row locked before delivery row; derived values are computed, never stored (decision in 1.4).

---

## 6. Revised steps

### 6.1 Update delivered items (running totals)

**Status:** Not started.

**Dependencies:** 5.2 and 5.3 (built). Q1 to Q3.

**Attach**

- CONTEXT.md, PROGRESS.md
- The briefing PDF pages 8 and 9 and `MMS_PO_to_DV_Workflow.pdf` (the flowchart wins where it differs)
- Models: `PurchaseOrder.php`, `PoItem.php`, `Delivery.php`, `DeliverySignatory.php`; migrations of `po_items` and `deliveries`; factories `PoItemFactory.php`, `DeliveryFactory.php`
- Delivery code: `DeliveryService.php`, `DeliveryRepository.php` and its interface, `DeliveryController.php`, `DeliveryPolicy.php`, `app/Support/DeliveryRule.php`, `app/Support/DeliveryDocumentRule.php`, `DeliveryDocumentService.php` (for the gate and the auto-reopen), `app/Enums/{DeliveryStatus,DeliveryProgress,DeliveryTiming}.php`
- PO code: `PurchaseOrderService.php`, `PurchaseOrderController.php`, `PurchaseOrderPolicy.php` (only to see how the PO page props are built), and the PO list/detail badge code
- `app/Support/{Audit,StatusTransition,Money}.php`, `app/Support/ScheduleSplit.php` (only to read how quantities per line are validated)
- `config/mms.php`, `config/mms_access.php`, `routes/web.php`, `AppServiceProvider.php`
- React: `resources/js/pages/deliveries/show.tsx`, `resources/js/pages/purchase-orders/show.tsx`, `resources/js/components/deliveries/{delivery-types.ts,po-deliveries-panel.tsx,delivery-documents-panel.tsx}`, the PO status badge component, `ResponsiveList` usage example
- Tests as patterns: `DeliveryTest.php` (helpers), `DeliveryDocumentTest.php` (helpers and the Encoder/403 patterns)

**Files to modify (expected):** `DeliveryService.php` (void drops items from totals; load totals), `DeliveryController.php` and `DeliveryPolicy.php` (item actions), `PurchaseOrderController.php` (totals and progress on the PO page), the PO list query/badge, `config/mms.php` (audit labels, over-delivery limits if any), `routes/web.php`, `po_items` is NOT altered unless you approve it, delivery `show.tsx`, PO `show.tsx`, `delivery-types.ts`.

**Files to create (expected):** one migration for `delivery_items` (shown to you first), model `DeliveryItem`, `DeliveryItemRule`, `DeliveryItemService` (+ repository if the pattern needs it), Form Requests, controller, a delivered-items panel (React), `DeliveryProgressCalculator` (or similar single place for the totals), factory, `DeliveredItemsTest` (new helper prefix).

**Prompt**

> Step 6.1. Build the PO Reviewer's delivery update. The delivery header exists (step 5.2) and its documents are gated (step 5.3); now the quantities. Read CONTEXT.md decisions 20, 35, 43, 47 and 48 first.
>
> BEFORE CODING, tell me and wait for my OK: (1) the exact migration for `delivery_items` (proposal: `delivery_id` restrict, `po_item_id` restrict, `quantity` decimal, `delivered_on` date, `status` active|void, `void_reason`, over-delivery flag and reason, recorded_by, voided_by and at; say which decimal scale you choose and why; unique or not on delivery + item); (2) the rule for which deliveries accept items (my default: only `complete`; items already entered stay counted if the delivery is reopened; adding or voiding items needs `complete` again; tell me what happens with the 5.3 auto-reopen); (3) who may use it (proposal: `delivery.update-items`, PO Reviewer preset, no new permission; say how the PO Reviewer reaches the page, since the section `section.deliveries` already contains `delivery.update-items`); (4) the rule for corrections (proposal: a line is never edited; it is voided with a reason and entered again, like documents); (5) the totals and how they are computed (running total delivered per PO line across ALL non-voided deliveries and ACTIVE rows, undelivered = ordered minus delivered, never stored on `po_items` or `purchase_orders`); (6) routes.
>
> Then build: select several PO lines at once in one save, each with its own delivery date and the quantity delivered today, linked to ONE delivery of that PO (the lines must belong to the delivery's PO). Show per line: ordered, delivered before, delivered now, running total delivered, total undelivered. Prevent over-delivery unless the person ticks over-delivery and writes a reason (5 to 500 characters); it is audited and shown with a badge. Quantities may be fractional (decimal). A voided delivery's rows drop out of every total (update `DeliveryService::void()` and keep the `syncClosed()` TODO, do NOT build `syncClosed()`). Show each line's delivery history (date, delivery IAR, quantity, who, void rows with their reason) on the PO page and on the delivery page.
>
> Derive the PO progress with the existing `DeliveryProgress` enum (not delivered / partial / full) from the numbers; it is NEVER stored. Show it on the PO list badge (stored status `verified` shows the progress label, as in CONTEXT.md decision on the status model) and on the PO detail page. Do not change `purchase_orders.status`.
>
> All writes in one transaction with the PO row locked first, then the delivery row; the rule class `DeliveryItemRule` is used by the Policy and again by the Service under the locks. Audit every save and void through `Audit::log` (log `delivery`, labels in `config/mms.php`). No email. Use the existing ResponsiveList and flash-toast conventions.
>
> Pest tests (new helper prefix, not `delivery*`, `ddoc_`, `po_doc_`, `po_types_`): an item of 1,000 pieces delivered as 500, 300, 200 shows the right totals after each save; fractional quantity (for example 2.5 kg); over-delivery refused without the flag and reason, accepted with them; two lines in one save, all or nothing; a line of another PO is refused; a delivery that is not `complete` refuses items; a voided delivery refuses items and its rows drop out of the totals; voiding a row with a reason updates the totals; permission 403 for roles without `delivery.update-items`; the Encoder rule from 5.3 is unchanged; progress is not delivered / partial / full at the right moments; existing `DeliveryTest` and `DeliveryDocumentTest` still pass (tell me if one needs a change).
>
> Done when: an item of 1,000 pieces delivered as 500, 300 and 200 shows the right totals at every step, a voided delivery or row changes the totals, the PO badge shows the derived progress, and the tests pass.

**How I will check it (to put in PROGRESS.md):** record a delivery, upload DR/OR/IAR, mark it complete, open the delivered-items panel as the PO Reviewer, enter 500, then 300, then 200 for one line and watch the totals; try 1 more without the over-delivery box; void the middle row; void the whole delivery and watch the totals and the PO badge.

---

### 6.2 Equipment decision and property records

**Status:** BLOCKED until MMS gives one PAR / ICS rule (Q4), and answers Q5 to Q7.

**Dependencies:** 6.1, 5.0 (personnel directory), Q4 to Q7.

**Attach**

- CONTEXT.md, PROGRESS.md, the briefing PDF pages 8 and 9, `MMS_PO_to_DV_Workflow.pdf` (Part 3)
- MMS's written answer to Q4 to Q7 (paste it in the prompt)
- Models: `ItemCategory.php`, `Item.php`, `PoItem.php`, `PurchaseOrder.php`, `Delivery.php`, `DeliveryItem.php` (from 6.1), the personnel files from 5.0 (`PersonnelDirectory`, `LocalPersonnelDirectory`, `PersonData`, `PersonnelController`, `PersonnelPolicy`)
- `config/mms.php` (the `property.*` keys), `config/mms_access.php` (presets `inventory-team`, `po-reviewer`; the `sections` list), `AppServiceProvider.php`, `routes/web.php`
- `IarNumberService.php` and the IAR models (a pattern if numbering is similar), `Audit.php`, `StatusTransition.php`
- A reference module for list pages and pickers: `delivery-form.tsx`, `personnel-picker.tsx`

**Prompt**

> Step 6.2. Read CONTEXT.md (Part 3 notes, decision 44 about the personnel directory and the Inventory Team, decision 19 about the category flag) and the final flowchart. MMS's answer to the PAR / ICS rule is: <PASTE MMS's ONE RULE, who generates, Sub-PAR user role, numbering>. Do not choose the rule yourself.
>
> BEFORE CODING, tell me and wait for my OK: the migrations (property document tables; one header table or three; per delivered line; status; numbering columns), new permissions or sections (the Inventory Team is outside `section.deliveries`; propose a `property` section and its routes), the rule class, and where the numbering lives. Ask me about document numbering before inventing a scheme.
>
> Build: for each delivered line (from 6.1), decide "Equipment?" (default from `item_categories.is_equipment`, editable by the person who generates, recorded with a reason when it differs from the category). For equipment apply MMS's rule to the unit price (read the limits from `config/mms.php` `property.*`; correct the comments in `config/mms_access.php` that still quote the 50,000 rule): create the PAR (and Sub-PAR with the actual user chosen from the personnel directory) or the ICS as MMS says; below the ICS limit neither applies and a remarks / justification field is mandatory. Create the tables, a "Property documentation pending" list (delivered equipment lines without their document), audit, and tests. Existing flows must not change.
>
> Done when: examples from MMS's rule give the right document (for instance a PHP 60,000 item produces a PAR and Sub-PAR, a PHP 30,000 item an ICS, an item below the ICS limit needs remarks, if that is the final rule), numbering is unique, permissions return 403, and the tests pass.

---

### 6.3 Printable PAR, Sub-PAR, ICS

**Status:** Not started. Needs 6.2 and the blank forms.

**Dependencies:** 6.2, the hospital's blank PAR, Sub-PAR and ICS forms (scans or PDFs), Q8.

**Attach**

- CONTEXT.md decisions 32, 33, 40 and 45 and the latest `docs/PO-PRINT-SPIKE.md`
- Blank sanitized scans or PDFs of the three forms (no real names or numbers)
- The 4.6 print code: `app/Support/Print/*` (Form13, Paginator, Renderer, Html, Fonts, Paper, Config and the others), `PoPrintService.php`, `PoPrintController.php`, `PoPrintRule.php`, `PurchaseOrderPolicy::printOrder`, `AmountInWords.php`, the print tests, the fonts folder reference, `public/images/zcmc.png`
- The property document models and service from 6.2, `config/mms.php` (the `print.*` keys), `config/mms_access.php`, `routes/web.php`, the property page where the Print button will sit

**Prompt**

> Step 6.3. Build print-ready PAR, Sub-PAR and ICS that match the attached blank forms (layout, fields, signature areas), reusing the dompdf stack of step 4.6 (fonts, `Renderer`, `Html::lines()` with a fixed line height, own pagination). Do not add a new library. Fill the fields from the system data; missing values print empty, never invented; no budget stamp.
>
> Approvals I give: paper size = <bond / a4 / test both>; the printed document is <only generated and downloaded, as decision 40 / also stored>; permission = reuse `par.generate` and `ics.generate`. Audit every generation with a new event (for example `property_printed`, labels in `config/mms.php`), subject the document. Tell me which existing tests could break. Show me a description or screenshot of how each page prints.
>
> Tests: the generated PDF contains the document number, item, unit price, total and the signer names; page count for a long item list (use `App\Support\Qpdf::pageCount`); 403 for roles without the permission; a voided or non-existent document is refused.
>
> Done when printed pages look like the real forms with the right data and the tests pass.

---

## 7. Edits to make in your own files

### PROGRESS.md

Replace the Phase 5 and Phase 6 lines with (step 5.3 is already ticked in the file you were sent):

```
- [x] 5.3 DR/OR/IAR upload and completeness gate. Built 2026-11-24, DeliveryDocumentTest 24 passed, browser check 2026-11-25: working; CONTEXT.md decision 48.

## Phase 6: Part 3, monitoring and property documents (plan: PHASE-6-PLAN.md)
- [ ] 6.1 Update delivered items (running totals). Needs Q1 to Q3 of the plan (defaults allowed).
- [!] 6.2 Equipment decision and property records. BLOCKED until MMS gives one PAR / ICS rule, the numbering, and who generates (Q4 to Q7).
- [ ] 6.3 Printable PAR / Sub-PAR / ICS. Needs 6.2 and the blank forms.
```

Add to "Open questions":

```
- 2026-11-25 (6.1): Q1 to Q3 of PHASE-6-PLAN.md (which deliveries accept items, the date on each line, over-delivery rule). Defaults are allowed.
- 2026-11-25 (6.2): Q4 to Q7 of PHASE-6-PLAN.md (ONE PAR/ICS rule, who generates, Sub-PAR user role, numbering). Nothing in 6.2 is built without them.
- 2026-11-25 (6.3): Q8 (stored or only generated, paper size) and the blank PAR, Sub-PAR and ICS forms.
```

### CONTEXT.md

Add after decision 48 (renumber if you added others):

```
49. **Phase 6 plan (2026-11-25, `PHASE-6-PLAN.md` replaces Phase 6 of the guide):** 6.1 builds `delivery_items` against the 5.2 delivery header; delivered quantity is a decimal; totals and the PO progress (`DeliveryProgress`) are computed from active rows of non-voided deliveries and never stored; a delivery accepts items only while `complete` (default, Q1); over-delivery needs a flag and a reason (Q3); a voided delivery drops out of the totals; `syncClosed()` stays a TODO until 7.3 and 8.1. 6.2 is blocked until MMS gives ONE PAR / ICS rule, the numbering and who generates; it needs its own section because the Inventory Team is outside `section.deliveries`. 6.3 reuses the 4.6 print stack.
```

---

## 8. Order to follow

1. Section 1 "Before 6.1" checklist.
2. Answer or accept Q1 to Q3, then run 6.1 in its own chat with the attachments above.
3. Send the Q4 to Q7 questions to MMS now, so 6.2 is not blocked when 6.1 is finished. Until the answers arrive, 6.2 and 6.3 stay parked.
4. If 6.2 stays blocked for long, the next unblocked work is Phase 7 (7.1 checklist review and MMS Head override, 7.2 DV, 7.3); read its rows in the guide's "Changes in the final flowchart" table first, and its open questions, before choosing. Phase 6 does not block 7.1.
5. After each step: update PROGRESS.md and CONTEXT.md, run `php artisan test`, and tick the box only after the tests pass and you have checked it in the browser.
