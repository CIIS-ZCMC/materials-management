# PHASE-5-PLAN: Part 2, delivery, receiving, IAR

Written 2026-11-23. Replaces Phase 5 of DEVELOPMENT-GUIDE.docx (it was written 2026-11-12, before Phase 4 was built).
Sources: DEVELOPMENT-GUIDE.docx, PROGRESS.md (last updated 2026-11-20), CONTEXT.md decisions 1 to 40.
Where this file differs from the guide, this file wins. Use it the same way as the guide: one step per chat, attach CONTEXT.md and PROGRESS.md plus the files listed.

---

## 1. Can you skip 4.6 and 4.7 and go to Phase 5?

**4.6 is already done.** The standard PO (form 13) prints, with 39 print tests and 28 combined-PDF tests green (PROGRESS.md, 2026-11-23). Nothing to skip. Only the browser checks are pending.

**4.7 (form 04A): yes, park it.** Phase 5 does not depend on it:
- Phase 5 reads verified POs, their lines and the document service from 4.2/4.3. It never calls the print code.
- The real PO in a bundle is the signed paper copy that Procurement scans and uploads (CONTEXT.md decision 40). The system PDF is a convenience for Procurement, not part of the flow.
- 04A is blocked by two things only the office can answer: the approved Terms and Conditions text (you cannot get a text file, only the scan) and Procurement's rule for which form is used when. Neither answer arrives faster by waiting in this step.

How to park it safely:
1. In PROGRESS.md change `[ ] 4.7` to `[!] 4.7 PARKED 2026-11-23: needs the approved Terms text (Procurement has scans only) and the form-choice rule. Not a dependency of Phase 5.`
2. Do NOT merge the 4.7 files from the last session yet (`Form04A.php`, `SpecLines.php`, `TermsLoader.php`, the changed `PoPrintService.php` and `config/mms.php`, `PoPrint04ATest.php`). None of it has been run. Keep them in a folder outside the project (for example `C:\parked\step-4.7`), so form 13 stays exactly as the tests left it. When 4.7 is reopened, start from those files.
3. Add the two open questions below to PROGRESS.md "Open questions" (text in section 6).

When to reopen 4.7: when someone gives you either (a) a born-digital Terms file or (b) a written statement of who approved the scanned text and which clause is correct where the scan repeats itself. Until then Procurement can keep using form 13 for printing, and 04A stays a paper form.

---

## 2. What must be true before Phase 5 starts

| # | Check | Why | Status in your files |
|---|---|---|---|
| 1 | **Step 2.3 Personnel directory (dummy UMIS) is built.** | Step 5.2 selects inspectors and the end-user from it. The guide lists 2.3 in Phase 2, but PROGRESS.md still shows `[ ] 2.3`. | NOT built. Do it first (prompt 5.0 below). |
| 2 | **Offline decision recorded.** | CONTEXT.md (decision 17) says: decide before 5.1. IAR numbering is the first place where the choice (plain ids vs client-side uuid) matters. | Open. Recommended answer: stay online, plain ids, record it as a decision (section 6). Ask MMS only if they insist on working disconnected. |
| 3 | Full test suite run once and the result noted. | PROGRESS.md says several 4.x tests were "not yet run" (`PoDocumentReorderTest`, 4.5 tests). Phase 5 builds on those rules. | Run `php artisan test`. Known failures to ignore: the three kit tests listed in PROGRESS.md "Known issues". |
| 4 | Browser checks of 4.2d, 4.2e, 4.3 and 4.6 done or consciously skipped. | Phase 5 reuses the Documents tab and the consolidated PDF. | Pending in PROGRESS.md. |
| 5 | qpdf path set (`MMS_QPDF_PATH`) and PHP upload limits raised (40M / 50M). | 5.3 uploads and merges more files. | Done on your dev machine per decisions 29 and 31. |

---

## 3. What the guide says vs what exists now

| Guide text (Phase 5) | What your project already has | Change to the prompt |
|---|---|---|
| 5.1: tables `iar_series` and `iar_numbers`, lock, void with reason. | Decision 6 (three series per year: regular `26-1398`, contract `C26-0015`, donation `D26-0001`), config `iar.processing_days` = 3, `DeliveryStatus` enum exists. No IAR tables. | Keep, add: use the config and enum that exist; format of the three series; `Audit` helper for events; Pest test with a real concurrent-request check as far as SQLite allows; no `delivery_id` foreign key yet (the delivery table comes in 5.2). |
| 5.2: delivery batch record, personnel from the directory. | Table `po_documents` has a nullable `delivery_id` **without a foreign key**, waiting for Phase 5 (decision 29). `DeliveryStatus`: for_uploading to complete / voided; complete back to for_uploading needs a reason (decision 17). Placeholder page `deliveries` (`can:section.deliveries`) and the Encoder job (DUMMY-0004) exist. Personnel directory does NOT exist yet. | Add 2.3 as prerequisite. Add the migration that puts the foreign key on `po_documents.delivery_id`. Say which PO statuses may receive a delivery (verified only). Define who may create deliveries (permission list in `config/mms_access.php`). Define when the 3-day clock starts (new field, see open question Q1). |
| 5.2: "Amount" and "Fund Cluster" on the delivery. | Form 13 prints the fund block blank; nothing stores a fund cluster yet. | Keep as typed text on the delivery. Amount stored in centavos through `App\Support\Money` (the same as PO lines). |
| Decision 20 says "step 5.2 must store delivered quantity as a decimal". | The guide's 5.2 prompt has no per-item quantities; Part 3 (6.1) is where "the PO Reviewer updates delivered items per item and per date". | **Conflict. Recommendation:** 5.2 stores only the delivery header (Invoice #, IAR #, Fund Cluster, Amount, signatories, dates). Per-item delivered quantities (decimal) are built in 6.1. Record this in CONTEXT.md and fix the wording of decision 20. Tell me if you want quantities in 5.2 instead. |
| 5.3: upload DR, OR, IAR and supporting documents using the Phase 4 service. | `PoDocumentType` already has DR, OR, IAR, Supporting Document, Advance Notice Letter. `documents.types` in `config/mms.php` is a SAMPLE list with a `stage` per type, and the upload only accepts stage `po` (decision 37a). Only PO Bundle is "required". | Prompt now says: add stage `delivery` types, accept them with a `delivery_id`, and make the required list configurable (DR, OR, IAR required; supporting documents list empty until MMS answers). |
| 5.3: "append them to the PO's consolidated PDF". | One combined PDF per PO, built by `qpdf` from ACTIVE documents in `sort_order` (decision 31). Document rules lock the PO Bundle after verified (decision 30). Reorder rules are in `PoDocumentRule`. | Prompt now names the rules to extend (`PoDocumentRule`, `PurchaseOrderPolicy`), and asks for the page order of delivery files before coding. |
| 5.3: who voids a wrong DR/OR/IAR? | Decision "Who may void documents" is left open for Phase 5 (CONTEXT.md open questions). | Prompt asks me to propose the rule first and wait for your OK. |
| 5.3: "When the window is exceeded, log it only." | Matches CONTEXT.md open question (consequence undefined). `DeliveryTiming` enum is derived, not stored. | Keep. Log one audit event per delivery, not one per page view. |
| Changes table: "4.2, 5.3 The Part 2 gate now includes all required supporting documents... The list is not defined." | Still open (PROGRESS.md open questions). | Attach the final flowchart to 5.3 and say "flowchart wins". |

The file you attached last (`IAR_26-2190_PO_26-07-0592_SAVELINK_PHARMA_AND_TRADING_CORP_0020782.pdf`) is a real office file, so do not attach it to a chat. Its name gives two useful facts: IAR numbers like `26-2190` match the regular series of decision 6, and the office may name a PO's IAR file as `IAR_<IAR no>_PO_<PO no>_<SUPPLIER>_<id>.pdf`. Question Q4 asks whether the downloaded combined PDF should use that name.

---

## 4. Questions to settle (ask MMS, or accept the recommended default)

| # | Question | Recommended default if nobody answers |
|---|---|---|
| Q1 | When does the 3-day IAR processing clock start: the date the goods are received, or the date the delivery record is created? | Add a "Date received" field on the delivery (typed by the Encoder) and count `iar.processing_days` from it. |
| Q2 | Which PO statuses accept a delivery? | `verified` only, not closed, not cancelled/terminated. |
| Q3 | Which permission lets the Encoder create deliveries and upload DR/OR/IAR? | Two new permissions, `delivery.create` and `delivery.upload-files`, added to the `encoder` preset in `config/mms_access.php` (the permission count goes from 39 to 41; tell me first, as always). |
| Q4 | Should the downloaded combined PDF be named like the office files (`IAR_...`)? | No change for now (keep the current name). |
| Q5 | Which supporting documents are required at the Part 2 gate? | Required: DR, OR, IAR only. Supporting Document stays optional until MMS gives the list. |
| Q6 | Can one PO have a delivery that is voided and its IAR number reused? | No. Voided numbers are never reused (decision 6). |

---

## 5. Revised steps

Use the Standard Preamble of the guide, with two additions at the end of its rules:
"(8) Tests are Pest; the dev machine uses SQLite for tests (`php artisan test`). (9) If a step needs a new permission, migration, library or config key, tell me first and show the summary before writing code."

### 5.0 Personnel directory (dummy UMIS), step 2.3

**Attach:** CONTEXT.md, PROGRESS.md, `config/mms.php`, `app/Providers/AppServiceProvider.php`, one existing reference module as a pattern (Supplier: model, repository, service, policy, controller, form request, list page), the dummy seeders.

**Prompt:**

Step 2.3 (done before Phase 5). Build the dummy personnel directory behind an interface, as in CONTEXT.md decision 4: a `personnel` table (name, position, office/department, employee no., is_active), `PersonnelDirectory` interface with a local implementation bound in `AppServiceProvider`, a seeder with about 30 fake people (clearly fake names), and a read-only API for the delivery screen: search by name with a role filter (inspector, end-user, property custodian). A later Phase 13 step replaces the implementation with real UMIS. No screen to edit people for now (they come from UMIS). Follow the repository, service, policy and Form Request pattern of Suppliers. Tell me first if you need a new permission. Add Pest tests.

**Done when:** the interface resolves to the local implementation, a search returns people filtered by role, and tests pass.

### 5.1 IAR numbering

**Attach:** CONTEXT.md, PROGRESS.md, `config/mms.php`, `app/Support/StatusTransition.php`, `app/Support/Audit.php`, `app/Enums/DeliveryStatus.php`, briefing PDF pages 6 and 7, a sanitized sample of the IAR sequencing sheet (fake numbers, about 30 rows). Do not attach the real sheet or the IAR scan.

**Prompt:**

Step 5.1. Build IAR numbering for three series per year (regular `26-1398`, contract `C26-0015`, donation `D26-0001`, CONTEXT.md decision 6). Tables `iar_series` (type, year, next number; unique on type + year) and `iar_numbers` (number unique, series, status used / void, nullable `delivery_id` WITHOUT a foreign key until step 5.2, void reason, who and when). A service suggests the next number inside a database transaction with a row lock, reserves it when saved, rejects a duplicate with a clear message, and voids a number with a required reason (a voided number is never reused). An Admin screen shows series, lets the Admin set the starting counter for a series and year, and lists voided numbers. Seed counters from my sample. Write audit events through the `Audit` helper. Show the migration summary and any new permission first. Add Pest tests, including: two suggestions in a row give different numbers; a duplicate is rejected; a voided number is never suggested again; changing the counter writes an audit entry. Decision on offline: the app stays online with plain ids (record it).

**Done when:** two simultaneous requests cannot get the same number, a duplicate is rejected, voided numbers stay on the list, and the tests pass.

### 5.2 Delivery record (Encoder)

**Attach:** CONTEXT.md, PROGRESS.md, `PurchaseOrder`, `PoItem`, `PoDocument` models, `app/Enums/DeliveryStatus.php`, `PurchaseOrderPolicy.php`, `PoDocumentRule.php`, `config/mms_access.php`, `config/mms.php`, `routes/web.php`, `resources/js/pages/purchase-orders/show.tsx`, `app/Services/PoDocumentService.php`, the IAR service from 5.1, the personnel files from 5.0, briefing PDF pages 6 and 7.

**Prompt:**

Step 5.2. Build the delivery batch record. Rules: a delivery belongs to a verified PO that is not closed, cancelled or terminated (not other statuses). A PO can have many deliveries. The Encoder (job preset `encoder`) creates a delivery with: Invoice #, IAR # (suggested by the 5.1 service, editable, checked for duplicates), Fund Cluster (typed text), Amount (centavos, through `App\Support\Money`), Date received (the start of the 3-day IAR clock, config `iar.processing_days`), and selects the inspectors and the end-user from the personnel directory of 5.0 (a `delivery_signatories` link with the role of each person). Status starts as `for_uploading` through `StatusTransition::apply()`. Add the migration that puts the foreign key on `po_documents.delivery_id` and on `iar_numbers.delivery_id`. Voiding a delivery needs a reason, frees nothing (the IAR number is voided too), and calls the place where `PurchaseOrderService::syncClosed()` will go later (leave a clearly marked TODO, do not build `syncClosed()` now). Store only the delivery header: per-item delivered quantities (decimal) belong to step 6.1. Show the migration summary, new permissions (proposal: `delivery.create` for the Encoder) and routes first. Replace the placeholder `deliveries` page with a list of deliveries (ResponsiveList) and add a Deliveries tab or card on the PO detail page. Audit events for created, edited and voided. Pest tests for the rules above and for permissions (403).

**Done when:** a PO can have three deliveries with three different IAR numbers, an unverified or closed PO cannot get one, a duplicate IAR is rejected, and the tests pass.

### 5.3 DR, OR, IAR upload and completeness gate

**Attach:** CONTEXT.md, PROGRESS.md, `MMS_PO_to_DV_Workflow.pdf` (the final flowchart wins where it differs from the briefing), `PoDocumentService.php`, `PoConsolidatedPdfService.php`, `PoDocumentRule.php`, `PoDocumentType.php`, `PoDocumentChecklist.php`, `config/mms.php`, `PurchaseOrderPolicy.php`, `po-documents-panel.tsx`, `po-document-upload-form.tsx`, the delivery files from 5.2, briefing PDF pages 6 and 7.

**Prompt:**

Step 5.3. Before coding, tell me (1) the page order you propose for delivery files inside the PO's consolidated PDF, (2) who may void or replace a wrong DR, OR or IAR (CONTEXT.md leaves the Encoder's rule open for this step), and (3) the new rules and permissions it needs. Wait for my OK. Then: add document types of stage `delivery` to `documents.types` (DR, OR, IAR required; Supporting Document and Advance Notice Letter optional, list configurable because MMS has not defined the required supporting documents), accept them in the upload service with a `delivery_id` (a delivery-stage file must belong to a delivery of that PO; a PO-stage file must not), extend `PoDocumentRule` and `PurchaseOrderPolicy` so the Encoder can upload to a delivery that is `for_uploading` on a verified PO, and keep the PO Bundle rules unchanged. Show per delivery the checklist (which required types are uploaded and which are missing, by name). A delivery cannot move to `complete` until every required type has an active file; the block message names the missing files. When complete, the files are part of the PO's consolidated PDF (rebuilt through the existing service, nothing changed in how qpdf is called) and the page index shows them under their delivery. `complete` back to `for_uploading` needs a reason (decision 17). Show the 3-day IAR window indicator from the date received, and when it is exceeded log one audit event per delivery (log only, the consequence is not defined). Use `StatusTransition::apply()` for every status change. No email: fire a Laravel event or record a pending notice only. Pest tests: cannot complete with a missing file, can complete with all, a void removes the pages, the Encoder cannot touch the PO Bundle and Procurement cannot upload delivery files, permissions (403), and the window event is written once.

**Done when:** a delivery cannot advance until all required files exist, the PO's PDF grows with each upload, voiding a delivery file removes its pages, and the tests pass.

---

## 6. Edits to make in your own files

### PROGRESS.md

Replace the Phase 4 and Phase 5 checklist lines with:

```
- [!] 4.7 Print the PO with Delivery Schedule, form 04A. PARKED 2026-11-23. Needs: the approved Terms and Conditions text (Procurement only has scans; the scan repeats clauses) and Procurement's rule for which form is used when. Work files kept outside the project (C:\parked\step-4.7). Not a dependency of Phase 5.

## Phase 5: Part 2, delivery, receiving, IAR (plan: PHASE-5-PLAN.md)
- [ ] 5.0 Personnel directory (dummy UMIS), step 2.3 (prerequisite of 5.2)
- [ ] 5.1 IAR numbering
- [ ] 5.2 Delivery record (Encoder)
- [ ] 5.3 DR/OR/IAR upload and completeness gate
```

Add to "Open questions":

```
- 2026-11-23 (4.7): Terms and Conditions text for form 04A: Procurement has only paper scans; no born-digital file and nobody to confirm approval. The scan repeats the payment paragraph and sub-item "d.". Reopen 4.7 when a text file or a written approval statement exists.
- 2026-11-23 (4.7): form choice (13 or 04A): Procurement's rule is not known. No code guesses it.
- 2026-11-23 (5.x): Q1 to Q6 of PHASE-5-PLAN.md section 4 (IAR clock start, PO statuses that accept a delivery, Encoder permissions, file name, required supporting documents, voided IAR numbers).
```

### CONTEXT.md

Add:

```
41. **Step 4.7 (form 04A) parked, Phase 5 first (2026-11-23):** the PO with Delivery Schedule is not printed from the system for now. Reasons: the approved Terms and Conditions exist only as a scan (decision: the Terms text is never copied from a scan), and Procurement's rule for choosing the form is unknown. The data for 04A already exists (decision 35). Form 13 printing (decision 40) is unchanged. Phase 5 does not depend on printing.
42. **Offline (2026-11-23):** the app stays online with plain auto-increment ids (decision 17). IAR numbering (5.1) relies on a database lock, not on client-side ids.
43. **Delivered quantities (2026-11-23):** step 5.2 stores only the delivery header. Per-item delivered quantities (decimal) are built in step 6.1. This corrects the wording of decision 20.
```

(Decision numbers 41 to 43 assume 40 is your latest; renumber if you added more.)

---

## 7. Order to follow

1. Run the full test suite once; note the result in PROGRESS.md.
2. Make the three edits of section 6 (and keep the 4.7 files out of the project).
3. Answer or accept the defaults of Q1 to Q6 (section 4).
4. 5.0 then 5.1, 5.2, 5.3, each in its own chat with the attachments listed.
5. After 5.3, update PROGRESS.md and continue with Phase 6 as in the guide. Remember that 6.2 stays blocked until MMS gives one PAR/ICS rule, and 7.1/7.3 have open questions in the "Changes in the final flowchart" table.
