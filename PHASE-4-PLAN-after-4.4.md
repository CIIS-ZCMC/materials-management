# Phase 4 plan, updated after step 4.4

UPDATE 2026-11-18: step 4.4 (print spike) is done, verdict: dompdf is workable (see docs/PO-PRINT-SPIKE.md, CONTEXT.md decisions 32 and 33). The boss wants the PO printed directly from the system, so 4.5, 4.6 and 4.7 go ahead. NAZ is ignored. Procurement prints, signs on paper, scans and uploads the scan as the PO Bundle. Changes below are marked UPDATED. Attach only the latest docs/PO-PRINT-SPIKE.md, never the old one.

MMS Procurement Monitoring System. Updated 2026-11-17, after steps 4.2, 4.2b and 4.3 were built and their tests passed (PoDocumentTest 45 passed, PoConsolidatedPdfTest 28 passed, including the two real office scans with the real qpdf).

This file replaces the "Steps" part of PHASE-4-PLAN-updated.docx from step 4.2 on. Sections 2 and 5 of the old plan (what 4.0 and 4.1 changed; template files to attach) are unchanged, except for the note about the test scans below. The prompts for 4.4, 4.5 and 3.3c are the old ones; 4.6 and 4.7 have changes, marked CHANGED or ADDED.

# 1. What changed in the plan, and why

1. **4.2 and 4.3 are built, so their prompts are removed.** The old 4.2 prompt is also wrong now: it said the first upload moves a draft PO to Uploaded. Step 4.2b replaced that with a "Submit to Reviewer" button (CONTEXT.md decision 30). Do not paste the old 4.2 prompt again.
2. **The paste-at-the-top rules (section 4) now protect 4.2, 4.2b and 4.3**, and carry the Windows and test lessons from this step.
3. **Step 4.6 has real changes.** Its attach list used names that do not exist (it asked for "the Documents tab component"); the question "is the generated PDF stored as a document?" now has concrete consequences from the new document rules, and I added a recommendation (option A: generate and download only).
4. **Step 4.7 and 4.5** only get small additions (test patterns; the `#[Fillable]` warning).
5. **Phase 5 will collide with the 4.2b/4.3 document rules.** Nothing to do now, but it is written down in section 3 so it is not forgotten.
6. **New permission:** `documents.view-originals` (Admin and MMS Head). There are now 38 permissions.

# 2. Where we are

| Step | Status | Result |
|---|---|---|
| 3.6 | Built, checks pending | Verify, escalate, send back, History tab. |
| 4.0 | Done | docs/PO-TEMPLATE.md |
| 4.1 | Done | docs/PDF-MERGE.md. Still to test: a real phone photo and a real Word-exported PDF. |
| 4.2 | Built, tests pass | Table `po_documents`, upload service, qpdf check, image to PDF page (FPDF). CONTEXT.md decision 29. |
| 4.2b | Built, tests pass (45) | Document rules, Submit to Reviewer, remove, reorder, per-file View. Decision 30. |
| 4.3 | Built, tests pass (28), browser checks pending | One combined PDF per PO rebuilt after every change, page index, void, replace, permission `documents.view-originals`. Decision 31. |
| 4.4 | Done 2026-11-18 | Print spike. dompdf workable for both forms; the 04A extra-page spill fix is to confirm before 4.7. docs/PO-PRINT-SPIKE.md, decision 32. |
| 4.5, 3.3c, 4.6, 4.7 | Not started | See below. |

**Before the next step:** do the browser checks of step 4.3 (PROGRESS.md, "Step 4.3 checks") and tell the session what you saw. The tests prove the logic; only the browser shows the viewer, the buttons and the mixed page sizes.

# 3. Decisions

| # | Decision | Status |
|---|---|---|
| 1 | Install qpdf | Done (C:\tools\qpdf\bin\qpdf.exe on the dev machine). **Still to confirm for the hospital server** (IT). |
| 2 | Library setasign/fpdf | Done (used by `ImagePdf`). |
| 3 | jpg, jpeg, png uploads | Done. |
| 4 | dompdf (PO print) | UPDATED: spike done, workable. Added to the project in 4.6 (tell the user before adding it). |
| 5 | Paper size of each form | Open (Procurement question 2). |
| 6 | Fonts | Open. |
| 7 | Document types Notice of Delivery and Guarantee Letter | Done (added in 4.2b). |
| 8 | Which form is used when | Open (Procurement question 1). |
| 9 | Permission for printing | UPDATED: recommendation now `po.create` (Procurement only), not `po.view`. To confirm. |
| 10 | Migrations from PO-TEMPLATE.md section 7 | Open. |
| 11 (new) | Is the printed PO stored as a document? | UPDATED: recommendation option A (no), because the signed scan is uploaded as the PO Bundle. Decided in 4.6. |

## Carry-over notes (not blocking, but do not lose them)

0. **UPDATED 2026-11-18, open and left open:** which documents MMS staff complete by hand besides DR, OR and IAR (for example a clearance), and whether the system only collects their signed scans or generates some. The answer is the "required supporting documents" list for step 5.3. Also confirm with Procurement: Print PO limited to Procurement (`po.create`), and the signed scan is the PO Bundle (question 15).

1. **Phase 5 and the document rules.** `PoDocumentRule` allows adding documents only on draft, uploaded and escalated POs. Phase 5 uploads DR, OR and IAR on verified or later POs, with a `delivery_id`. Phase 5 needs its own rule for delivery documents; do not simply relax the PO Bundle rule.
2. **Who may void in Phase 5.** Void and replace need `documents.void` AND `po.upload-files` today, so only Procurement can do it. The Encoder (who holds `documents.void` but not `po.upload-files`) cannot. Phase 5 must decide the Encoder's rule for DR, OR and IAR.
3. **One combined PDF or one per delivery.** The combined PDF takes ALL active documents of a PO in sort order. Phase 5 should decide whether delivery documents join that same PDF or get their own.
4. **Command-line length.** qpdf receives all file paths on one command line. Windows allows about 8,000 characters, so roughly 60 documents per PO. If a PO ever has more, qpdf accepts an `@file` argument list. Not needed now.
5. **Synchronous merge.** One place to move it to a queue: `PoDocumentController::afterChange()` (a job that calls `PoConsolidatedPdfService::rebuild`). The page already shows "out of date" until the new file is ready.
6. **Test scans in git.** `tests/Fixtures/pdf/` holds two real scans with real supplier and staff names. Decide whether they stay in the repository.
7. **Anything that counts permissions** must now expect 38.
8. **The hospital server** still needs: qpdf installed, `proc_open` allowed, `MMS_QPDF_PATH`, PHP `upload_max_filesize` 40M and `post_max_size` 50M.

# 4. Paste at the top of every session

> Attach CONTEXT.md and PROGRESS.md (updated after the previous step).
>
> Rules: the final flowchart is authoritative. Work only on the step named. Do not change completed steps (1.x to 3.6, 4.0, 4.1, 4.2, 4.2b, 4.3) except where this step needs it. No email. Tell me BEFORE adding a library, a permission or a migration. Deliver complete files with the full path above each code block, only new or changed files, no placeholders, exact PowerShell commands (config:clear, route:clear, wayfinder:generate, npm run build, restart composer dev), test steps, and PROGRESS.md and CONTEXT.md as find-and-replace blocks. Explain React/Inertia parts briefly in plain words. Start your answer with: files you used, files changed, files created.
>
> CHANGED, add these lines: (1) If an attached file shows no content, read it from disk before telling me it is missing. (2) Tell me which existing tests your change could break, and say so if you changed an existing test. (3) The code cannot be run in the session, so the first test run may show small errors in the tests themselves: I will paste the output and you fix it. (4) Environment: Windows, PowerShell, qpdf at C:\tools\qpdf\bin\qpdf.exe, tests with Pest. This Laravel version has no Process::recorded(); UploadedFile::fake()->createWithContent() keeps the file open and a real program (qpdf) cannot read it on Windows, so use a closed temp file for tests with a real program.

# 5. Steps (4.2 and 4.3 are done: no prompts)

## Step 4.4: Print spike for both PO forms (new)

**Status:** Not started. Can run at any time (4.2 and 4.3 are done and nothing in them changes this step).

**Changed after 4.3:** nothing.

**Dependencies:** Decisions 4, 5 and 6 from section 3. Nothing in the project changes.

### Files to attach

-   docs/PO-TEMPLATE.md

-   From the ZIP extracts: 01, 02, 03, 04

-   public/images/zcmc.png and public/images/DOH.png

### Files to modify

-   None in the project (the spike runs in a separate folder with its own composer.json).

### Files to create (expected)

-   docs/PO-PRINT-SPIKE.md (verdict, list of differences, server needs, what to reuse)

-   In the separate spike folder: generator script, two HTML/Blade samples, AmountInWords with a test, sample PDFs and PNG previews

### Prompt to paste

> Step 4.4: print spike for both PO forms (dummy data, no project change).
>
> Read docs/PO-TEMPLATE.md first (sections 3 to 5 for the layout, 8 for the amount in words, 10 for the method). The decision there: generate both forms as PDF with dompdf and OUR OWN pagination (PHP splits the lines into pages; each page is one fixed-size HTML block with its own header, signature block, fund block and footer).
>
> Approvals I give in this session: dompdf for the spike = <YES/NO>; paper size = <A4 / long bond 8.5 x 13 in / test both>; fonts = free look-alikes (Liberation Serif and Liberation Sans, or Tinos and Arimo), no Microsoft fonts in the repository.
>
> Do the spike OUTSIDE the project: a separate folder (for example C:print-spike) with its own composer.json. Do not touch the project composer.json, database, routes or any app file. Write only docs/PO-PRINT-SPIKE.md into the project.
>
> Build with dummy data:
>
> 1. Form ZCMC-F-PROC-13 Rev. 12: one-page PO, and a multi-page PO like file 02 (header and supplier box repeated on every page, sub total and "continue to page N" where the scan shows them, signature, Conforme and fund block, footer with form number, revision, effectivity date and page).
>
> 2. Form ZCMC-F-PROC-04A Rev. 0: 10 item lines per page as in file 03, the schedule columns (including the non-date column "7 Days from Rec. of P.O."), zeros allowed, sub total per page, last page with the total and words, then the fixed Terms page (use placeholder text, mark it TEMPORARY).
>
> 3. Logos: public/images/zcmc.png (left) and a small copy (about 300 px) of public/images/DOH.png (right), real colors.
>
> 4. A pure-PHP AmountInWords helper with a unit test using the sample amounts in docs/PO-TEMPLATE.md section 8 (no intl).
>
> 5. Pagination test: a very long Description, many delivery columns, many lines, a PO with no items, a PO with one line.
>
> Render each result to PNG and compare it side by side with the scans (01 to 04): list every difference (position, size, font, wrapping). Report memory_limit used, time per PDF and PDF size. Say whether dompdf holds the look; if not, say what fails and propose the fallback (headless Chrome needs Node and Chrome on the server, only if IT agrees).
>
> Done when docs/PO-PRINT-SPIKE.md has the verdict for both forms, the list of differences, the measured server needs, and the exact files and approach to reuse in 4.6 and 4.7.

## Step 4.5: PO print fields (new)

**Status:** Not started

**Dependencies:** 4.4 verdict; Procurement answers to questions 3 and 5; decision 10 (migrations).

**Changed after 4.3 (two small additions):** attach `PoDocumentTest.php` and `PoConsolidatedPdfTest.php` only if you want them re-run (they need no change); and see the new rule in the prompt about the `#[Fillable]` list.

### Files to attach

-   docs/PO-TEMPLATE.md and docs/PO-PRINT-SPIKE.md

-   Migrations of suppliers, purchase_orders, po_items, items

-   app/Models/{Supplier,Item,PoItem,PurchaseOrder}.php

-   The supplier form and the catalog item form (resources/js/pages/suppliers and catalog), and their Form Requests

-   po-form.tsx, SavePurchaseOrderRequest.php, PurchaseOrderService.php, PurchaseOrderRepository.php and its interface, PurchaseOrderController.php, show.tsx

-   config/mms.php, the PO seeder and the factories

### Files to modify

-   The files above that match the fields you confirm

-   config/mms.php (print settings and audit labels)

### Files to create (expected)

-   Migrations (shown to you first)

-   PoPrintFieldsTest

### Prompt to paste

> Step 4.5: PO print fields (data model for the printed forms).
>
> Read docs/PO-TEMPLATE.md sections 6 and 7 (field maps and proposed storage) and docs/PO-PRINT-SPIKE.md. Do NOT build the print and do NOT touch schedule quantities (that is step 3.3c).
>
> Tell me first the exact migrations before writing them. Proposal from docs/PO-TEMPLATE.md section 7:
>
> - suppliers: tin, contact_no, supply_chain_role (nullable)
>
> - purchase_orders: pr_no, caf_no, mode_of_procurement, remarks (nullable)
>
> - items: stock_no (nullable); po_items: stock_no snapshot, brand, manufacturer, country_of_origin, specifications (nullable)
>
> - config/mms.php: place of delivery text, payment term text, signatory (Medical Center Chief) with an effective date, accountant name and title, form numbers, revisions and effectivity dates for both forms, list of modes of procurement. The fund block (Fund Cluster, Funds Available, ORS/BURS) is NOT stored and prints blank.
>
> Procurement's answers (paste): <typed by whom and in which order: question 3; supply chain role values; modes of procurement list; free-text line meaning; specifications free text or brand/manufacturer/country: question 5>.
>
> Add the fields to the supplier form, the catalog item form and the PO form (line level), the Form Requests, the PO detail page, the audit labels in config/mms.php, the seeder and the tests. Existing POs keep working with empty values. BizBox-source POs stay read-only.
>
> ADDED after 4.3: PurchaseOrder.php now has columns that are NOT mass-assignable on purpose (status, due_date, closed_at and the five consolidated_* columns from step 4.3). When you add the print fields to the #[Fillable] list, add only the new print fields and leave every one of those out. Run the whole test suite before and after; PoDocumentTest (45 tests) and PoConsolidatedPdfTest (28 tests) must still pass.
>
> Done when a PO can be created and edited with all the fields the printed forms need and they show on the detail page and in the audit log.

## Step 3.3c: Quantity per delivery column

**Status:** Not started (planned since 3.3; now required by form 04A)

**Changed after 4.3:** nothing.

**Dependencies:** 3.3 (built) and Procurement answer to question 4; decision 10.

### Files to attach

-   docs/PO-TEMPLATE.md

-   From the ZIP extracts: 03

-   CONTEXT.md decisions 20 and 24

-   po-form.tsx, PurchaseOrder.php, PoItem.php, PoSchedule.php, PurchaseOrderService.php, PurchaseOrderRepository.php and its interface

-   PurchaseOrderController.php, SavePurchaseOrderRequest.php, show.tsx, the PO seeder, PurchaseOrderScheduleLimitTest.php

### Files to modify

-   po-form.tsx, SavePurchaseOrderRequest.php, PurchaseOrderService.php (assertDatesFitQuantity), repository and interface

-   PurchaseOrderController.php (formData and detail), show.tsx (Schedule tab), the PO seeder, config/mms.php (audit labels)

### Files to create (expected)

-   Migration po_schedule_items (and days_from_receipt if chosen); PoScheduleItem model and factory

-   PoScheduleQuantityTest

### Prompt to paste

> Step 3.3c: quantity per delivery column (needed by form 04A).
>
> Read docs/PO-TEMPLATE.md sections 4, 6.2 and 7. Form 04A prints, for every item line, a quantity per delivery column; on all 20 sample lines the split adds up exactly to the line's Total Quantity, and zeros are allowed. We store dates only (CONTEXT.md decisions 20 and 24; option B, table po_schedule_items).
>
> Procurement's answers (paste): <question 4: what the first column "7 Days from Rec. of P.O." means, whether it is always first and always the PO delivery term, the limit of date columns, the date format, whether a quantity is always stated>.
>
> Tell me first the migration and the rules. Proposal: table po_schedule_items (schedule row, PO item, quantity); the non-date column stored as a nullable days_from_receipt on po_schedules with a null scheduled_date, or derived from delivery_term_days (choose after the answer); optional per PO (a PO with dates only stays valid); when a split is used, each line's quantities across columns must add up to its quantity ordered; replace PurchaseOrderService::assertDatesFitQuantity only where the new rule makes it redundant and update its tests.
>
> Form: quantity inputs per column and line on the Schedule Term card. Detail page: the schedule table on the Schedule tab. Audit changes, seeder update, tests. Do not touch other items or schedule code.
>
> Done when a PO can store, edit and show the quantity of each line per column and the line totals check out.

## Step 4.6: Print the standard PO (form 13)

**Status:** Not started

**Dependencies:** 4.4 and 4.5 (4.2, 4.2b and 4.3 are done). Decisions 4, 6 and 9.

### Files to attach

-   docs/PO-TEMPLATE.md and the latest docs/PO-PRINT-SPIKE.md, plus the spike code from C:\print-spike: Form13.php, Paginator.php, Ttf.php, Fonts.php, Ab.php, Renderer.php, Config.php, Money.php, Paper.php, Html.php, AmountInWords.php (UPDATED)

-   From the ZIP extracts: 01, 02. Logos: public/images/zcmc.png and DOH.png

-   config/mms.php, config/mms_access.php, routes/web.php, PurchaseOrder.php, PoItem.php, Supplier.php

-   PurchaseOrderController.php, PurchaseOrderPolicy.php, PurchaseOrderService.php, show.tsx

-   CHANGED (4.2 and 4.3 are built; these are the real names): PoDocumentService.php, PoConsolidatedPdfService.php, PoDocumentRule.php, PoDocument.php, PoDocumentType.php, Qpdf.php, Audit.php, po-documents-panel.tsx, PoConsolidatedPdfTest.php (as the model for tests that use a fake or a real program)

-   CONTEXT.md decisions 30 and 31 (they are in the CONTEXT.md you attach anyway; ask the session to read them)

### Files to modify

-   composer.json (dompdf, after you approve)

-   config/mms.php (form numbers and revisions if not added in 4.5; audit event label `po_printed`), routes/web.php

-   PurchaseOrderController.php, PurchaseOrderPolicy.php (only if you choose po.print), show.tsx

-   ONLY if you decide the PDF is also stored as a document (see the prompt, option B): PoDocumentService.php, PoDocumentRule.php, PoDocumentType.php, po-documents-panel.tsx

### Files to create (expected)

-   PoPdfGenerator and the page-splitting service

-   resources/views/pdf/po-standard.blade.php (or the structure the spike chose)

-   App/Support/AmountInWords.php; font files in the repository; a small DOH logo copy

-   GeneratePoDocumentTest, AmountInWordsTest

### Prompt to paste

> Step 4.6: print the standard PO (ZCMC-F-PROC-13 Rev. 12) from the system.
>
> Use the verdict and the code approach in docs/PO-PRINT-SPIKE.md. Approvals I give: dompdf in the project = <YES/NO>; the fonts chosen in the spike = <YES/NO>; permission for printing = <reuse po.view / new po.print>.
>
> Add a "Print PO" button on the PO detail page for POs with source manual (BizBox POs keep their own document and are refused), generated only from stored data: the fields from step 4.5, supplier, lines, totals, the total in words (AmountInWords), signatory and accountant from config. Missing values print empty, never invented. The fund block prints blank. Do not print the budget stamp.
>
> Match files 01 and 02 as in the spike: header with the ZCMC logo (public/images/zcmc.png) and a small copy of the DOH logo, the four lines of text, the title, supplier box, lines under the box, item table, X-X-X end line, signature block, Conforme text, fund block, footer. Pagination as built in the spike.
>
> Server side: composer.json, the font files in the repository, the PHP settings from docs/PDF-MERGE.md section 8 (dom, mbstring, gd, zlib, memory_limit). Give exact PowerShell commands.
>
> Decide with me whether the generated PDF is also stored as a document and becomes pages of the consolidated PDF; do not decide alone. Audit every generation (new audit event po_printed in config/mms.php, through Audit::log, subject the PO).
>
> What step 4.3 now means for that decision (read CONTEXT.md decisions 30 and 31 first). Option A, my recommendation: the printed PO is only generated and downloaded, never stored. Reason: the PO in a real bundle is the SIGNED paper copy (Conforme signature, fund block) that Procurement scans and uploads as the PO Bundle; an unsigned system PDF in the same bundle would be a second, different "PO". Option B: store it as a document. If you choose B, say what it needs before building: (1) PoDocumentRule::addBlockReason allows adding only on draft, uploaded and escalated POs, so a PO printed after verification could not be stored; (2) a new PoDocumentType case (for example Generated PO; the column is a string, so no migration, but tell me before adding it); (3) PoDocumentService::persist() takes an UploadedFile, so a method that accepts PDF bytes is needed; (4) po_documents.uploaded_by is a required user, so it would be the person who clicked Print; (5) page position: reorder is allowed only in draft and escalated, so say where the new file goes (real bundles put the PO last); (6) printing again should replace the earlier generated file through the existing replace rules, not add a second one; (7) the combined PDF is rebuilt by PoDocumentController::afterChange() after each change, so a stored print would be picked up with no extra code.
>
> Tests: the PDF contains PO number, supplier, items and totals; the total in words is correct; page count for a multi-page PO (use App\Support\Qpdf::pageCount on the generated file, as PoConsolidatedPdfTest does, instead of reading the text); permission 403; a BizBox PO is refused. Environment notes learned in step 4.3 (Windows): this Laravel version has no Process::recorded(), so a fake program must record its own commands; UploadedFile::fake()->createWithContent() keeps the file open, and on Windows another program (qpdf) cannot read it, so tests that give a real file to a real program must copy it to a normal closed temp file first; tests that need a real program should skip themselves when it is not installed, as PoConsolidatedPdfTest does.
>
> Done when a PO created in the system prints in the hospital format of form 13, and the decision about storing it is written in CONTEXT.md.

## Step 4.7: Print the PO with Delivery Schedule (04A)

**Status:** Not started

**Dependencies:** 4.6, 3.3c, the Terms and Conditions text from the office, Procurement answer to question 1.

### Files to attach

-   docs/PO-TEMPLATE.md and the latest docs/PO-PRINT-SPIKE.md, plus Form04A.php from C:\print-spike (UPDATED, after the extra-page fix is confirmed)

-   From the ZIP extracts: 03, 04. The approved Terms and Conditions text (or a born-digital copy)

-   From 4.6: PoPdfGenerator.php, the page-splitting service, po-standard.blade.php, AmountInWords.php

-   PoScheduleItem.php, PurchaseOrderController.php, PurchaseOrderPolicy.php, routes/web.php, show.tsx, config/mms.php

-   CHANGED: Qpdf.php and PoConsolidatedPdfTest.php (for page-count assertions and the fake-program pattern), plus whatever 4.6 changed for storing the PDF (if option B was chosen)

### Files to modify

-   PoPdfGenerator.php (picks the form), PurchaseOrderController.php, show.tsx

-   config/mms.php (04A form number, revision, effectivity date), routes/web.php

### Files to create (expected)

-   resources/views/pdf/po-delivery-schedule.blade.php

-   resources/views/pdf/partials/po-terms.blade.php (versioned template for 04A rev 0)

-   GeneratePoDeliverySchedulePdfTest

### Prompt to paste

> Step 4.7: print the PO with Delivery Schedule (ZCMC-F-PROC-04A Rev. 0).
>
> Reuse the generator from 4.6. Match files 03 and 04: form-number box at the top right, supplier box, item table with Total Quantity, Unit Cost, Total Amount and the delivery columns (including "7 Days from Rec. of P.O."), quantities from po_schedule_items, sub total per page with "continue to page N", last page with total, words, signature and Conforme, then the Terms and Conditions page.
>
> Terms and Conditions: use ONLY the text I attach (approved by Procurement or Legal, or from a born-digital file). Do not copy it from the scan. Store it as a versioned template (one per form revision, for example 04A rev 0) so a new revision does not change old printouts. Mark anything you cannot read instead of guessing.
>
> Form choice: <paste Procurement's rule from question 1>. The generator picks the form by that rule and the page shows which form was used. Same permission, audit and BizBox restriction as 4.6; same decision about storing the file as a document.
>
> Tests: schedule quantities and totals appear, every page has the header, the Terms page is last (check the page count with Qpdf::pageCount), the right form is chosen, permission 403. Use the same test patterns and Windows notes as step 4.6.
>
> Done when a PO with a delivery schedule prints in the 04A layout with the Terms and Conditions page.

# 6. Questions to the office

The full list is in docs/PO-TEMPLATE.md section 9. These are the ones that block steps (unchanged except the IT line and the new Phase 5 question):

| Question | Topic | Blocks |
|---|---|---|
| Procurement 1 | Which form is used when (number of delivery dates, mode of procurement, or choice); can a PO switch form | 4.7 |
| Procurement 2 | Paper of each form: A4 or long bond (8.5 x 13 in) (both tested in 4.4; form 13 with 17 lines: 3 pages on long bond, 4 on A4) | 4.6 |
| Procurement 3 | Who types TIN, contact number, supply chain role, P.R. No., CAF No., mode of procurement, stock number, brand and so on; allowed values | 4.5 |
| Procurement 4 | UPDATED: "Rec." = received (confirmed 2026-11-18). Still open: is the 7 always the PO delivery term, number of date columns, date format (scan: m/d/yyyy), quantities always stated (recommended yes, zeros allowed) | 3.3c |
| Procurement 5 | Supplier's Specifications: free text or brand, manufacturer, country; the last text line | 4.5 |
| Procurement 12 | Born-digital copy or BizBox PDF export of each form | Optional (better look than the Liberation fonts) |
| Procurement 15 (new) | The printed PO: is it only a working copy, with the SIGNED paper copy scanned and uploaded as the PO Bundle? (This decides option A or B in 4.6.) | 4.6 |
| Terms and Conditions | Approved text of the 04A Terms (clause numbers and sub-letters as they must print) | 4.7 |
| Accounting 1 to 5 | Fund block, ORS/BURS, who types it, accountant name | 4.6 (prints blank until answered) |
| IT | CHANGED: qpdf installed on the hospital SERVER (the dev machine already works), proc_open allowed, PHP version and php.ini (upload limits 40M and 50M), fonts | 4.6 (and the real use of 4.2 and 4.3) |

# 7. Later phases (do not attach now)

-   Files 05 and 06 (Notice of Delivery, IAR): Phase 5. The final flowchart has the IAR prepared on paper by Receiving; the Notice of Delivery is not in the flowchart, so ask MMS whether the system should produce it. When Phase 5 starts, read carry-over notes 1 to 3 in section 3 first.

-   Files 07 and 08 (routing slip, Guarantee Letter, supplier letter): Flows A, B and C.