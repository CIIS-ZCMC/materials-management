# Step 6.3 prompt (paste in a new chat)

Attach: CONTEXT.md, PROGRESS.md, PHASE-6-PLAN.md (the three updated files), the two real Excel forms (ZCMC-F-MM-01 PAR, ZCMC-F-MM-04 ICS), and the files listed under "Attach" in PHASE-6-PLAN.md section 6.3 (the 4.6 print code, the 6.2 property files, config/mms.php, config/mms_access.php, routes/web.php, show.tsx of property-documents).

Paste the block at the top of section 5 of PHASE-6-PLAN.md ("Paste at the top of every session"), then this:

Step 6.3. Build print-ready PAR, Sub-PAR and ICS that match the two attached real Excel forms (layout, fields, signature areas, header, footer text), reusing the dompdf stack of step 4.6 (fonts, `Renderer`, `Html::lines()` with a fixed line height, own pagination). Do not add a new library. Fill the fields from the system data; the fields the system does not hold (Fund Cluster, Property Number, Inventory Item No., Est. Useful Life) print empty, never invented; the issuer name and position are config values. No budget stamp.

First open the Excel forms and show me, per form, the fields you will fill, the fields left empty, and how a PAR and its Sub-PAR are laid out (one or two pages). Ask only if it blocks the build; otherwise state your assumption.

Approvals I give: paper A4 as the forms are set (a config value); the printed document is only generated and downloaded, as decision 40 (provisional until MMS confirms, Q8); permission = reuse `par.generate` and `ics.generate`. Audit every generation with a new event (for example `property_printed`, labels in `config/mms.php`), subject the document. Voided documents are refused. Tell me which existing tests could break. Show me a description or screenshot of how each page prints.

BEFORE CODING, show me the new routes, permissions (if any), config keys and rules, and wait for my OK. New decision numbers start at 60.

Tests: the PDF contains the document number, item, unit price, total and signer names; page count for a long item list (`App\Support\Qpdf::pageCount`); 403 for roles without the permission; a voided or non-existent document is refused.

Done when printed pages look like the real forms with the right data and the tests pass.
