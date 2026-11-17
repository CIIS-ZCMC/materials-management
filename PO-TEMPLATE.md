# PO print templates: analysis and engine decision (step 4.0)

Written 2026-11-16. Analysis only. No code, no database change, no library installed.
Sources: four scans from the office (files 01 to 04), `CONTEXT.md`, `PROGRESS.md`, the PO models, migrations and `po-form.tsx`.

## 1. Summary

| Topic | Result |
|---|---|
| Forms | Two: **ZCMC-F-PROC-13 Rev. 12** (standard PO) and **ZCMC-F-PROC-04A Rev. 0** (PO with Delivery Schedule + a fixed Terms and Conditions page). |
| Biggest finding | Form 04A prints a **quantity per item per delivery column**. On all 20 sample lines the split adds up exactly to the line's Total Quantity (zeros are allowed). We store delivery dates only, so 04A cannot be printed from today's database. |
| Second finding | Form 04A also has a first schedule column **"7 Days from Rec. of P.O."** that is not a date. |
| Third finding | The scans are not all the same paper size (see 2.1). The brief says A4; please confirm. |
| Engine (recommended, needs your approval) | **(b) Blade/HTML to PDF with dompdf**, with our own pagination: one HTML block per printed page, split in PHP. |
| Why | The office has **no .docx** of either form, so options (a) and (c) have no template to start from; we would draw one from the scans anyway. dompdf needs nothing installed on the hospital server except normal PHP extensions. |
| Server requirement | See the ONE install list in `docs/PDF-MERGE.md` section 8 (updated in step 4.1): PHP `dom`, `mbstring`, `gd`, `zlib`; `memory_limit` about 256M; `proc_open` allowed; **qpdf** installed (needed for the PDF merge). No LibreOffice, no Word, no Node. `intl` is not needed (see section 8 below). |
| Terms and Conditions | Cannot be taken from the scan. We need a clean digital copy or approved typed text from the office (section 11). |
| Needs your approval before any build | One library (dompdf), several migrations (section 7), no new permission so far. |

## 2. How to read the scans

- Files: 01 = form 13, one page. 02 = form 13, four scanned pages (page 4 is a second copy of page 1, with pen strokes). 03 = form 04A, first page. 04 = form 04A, last item page + Terms page.
- Handwriting, signatures, check marks, diagonal pen strokes through empty cells and the rubber stamp "ZCMC BUDGET SECTION / RECEIVED / RETURNED / RECEIVED / RELEASED" are **not part of the form**. The stamp is applied by hand to the paper copy and must not be printed.
- **Unreadable or uncertain** (not guessed):
  - File 02 is very noisy. P.R. No. and CAF No. values, the ORS/BURS number, and most of the Amount column cannot be read reliably. The line amounts were checked by quantity x unit cost: the three pages add up to the printed total, 234,820.00.
  - In file 01 the values on the right of the header sit one row lower than their labels (the long address wrapped and pushed the left side down). By format (same as 04A), P.R. No. is probably `26-11-0579` and CAF No. `2026-1771`. To confirm.
  - Form 04A first and last pages are cut at the right edge (the form-number lines). The Terms page shows them in full.
  - Grey discs next to the header text (left of the text in 01 and 02, right of the logos in 04A) could be a faded logo or a scan artifact.
  - Part of the word "agrees" in the 04A Conforme line is covered by a signature. Best reading: "agrees to abide by".
  - The Telephone line of 04A reads "(062) 991-0573, 992-0160" but the digits are noisy.
- **Not in the samples:** middle pages of a 04A PO; a 13 PO that has a Terms page; the back of any page. Everything about those is unknown.
- All measurements below are taken from the scans (about 200 dpi). Treat them as +/- 1 mm or 1 percentage point.

### 2.1 Paper size

| File | Scan size | Looks like |
|---|---|---|
| 01 (form 13) | 214 x 332 mm | Long bond / Folio, 8.5 x 13 in |
| 02 (form 13) | 209 x 300 mm | A4 |
| 03, 04 (form 04A) | 214 x 332 mm | Long bond / Folio, 8.5 x 13 in |

The brief says A4 portrait. The scans suggest the medicine POs (01, 03, 04) are on a taller sheet. A scanner can crop or stretch, so this is only a hint. **Ask the office which paper each form is printed on.** dompdf can do either; it changes how many lines fit on a page (04A shows 10 lines per page).

## 3. Form ZCMC-F-PROC-13 Rev. 12 (standard PO)

### 3.1 Page setup
- Portrait. Side margins about 8 mm. Content width about 193 mm on the 214 mm scan. First element about 8 mm from the top; footer text about 11 mm from the bottom.
- Black and white except the logos. Thin black lines (about 0.5 to 0.75 pt).

### 3.2 Header (repeats on every page)
- Two seals flank the centered text, close to it (not at the page edges): ZCMC seal on the left (about 13 x 16 mm), DOH seal on the right (about 17 mm).
- Four centered lines of serif text (Times-like, about 9 to 10 pt), exactly:
  1. `Republic of the Philippines`
  2. `Department of Health`
  3. `ZAMBOANGA CITY MEDICAL CENTER`
  4. `Dr. D. Evangelista St., Sta. Catalina, Zamboanga City, 7000`
- Title below, centered, bold serif, about 14 pt: `PURCHASE ORDER`. The title is **outside** the bordered box.
- No form-number box at the top. The form number is in the footer.

### 3.3 Supplier box (repeats on every page)
A rectangle with two halves. Values sit on a line under each row (field lines).

| Left half (label : value) | Right half (label : value) |
|---|---|
| `Supplier :` | `P.O. No. :` |
| `Address:` | `P.O. Date :` |
| `Supply Chain Role:` | `P.R. No. :` |
| `Contact No:` | `CAF No. :` |
| `TIN:` and, on the same row, `Taxes :` | `Mode of Procurement:` |

- Supplier name is bold. The address may wrap to a second line. In scan 01 the labels did not move when the address wrapped, so the values ended up one row below their labels. In our version the rows must grow with the content.
- The `Taxes :` label has no value in any of the three samples.
- PO number is printed without hyphens (`26111037`); PO date as `11/05/2026` (mm/dd/yyyy).

### 3.4 Lines under the box (repeat on every page)
- Row: `Gentlemen:` then `Please furnish this Office the following articles subject to the terms and conditions contained herein:`
- Second box, three lines:
  - `Place of Delivery: Zamboanga City Medical Center` and, below it, `Dr. Evangelista Street Sta. Catalina Zamboanga City Zamboanga Del Sur Philippines 7000` (as printed, no commas)
  - `Date of Delivery:` with the printed note `(To be filled by the MMS)` (blank in all samples)
  - On the right: `Delivery Term: 7 Days Term` (02: `15 Days Term`) and `Payment Term: Refer to Terms and Conditions`

### 3.5 Item table (header row repeats on every page)

| Column | Printed heading | Width (share of table) | Content |
|---|---|---|---|
| 1 | `Stock/` `Property` `No.` (three lines) | 7% | Code such as `MED00084`, `SUP11025` |
| 2 | `Unit` | 6% | `Ampule`, `Vial`, `Piece`, `Loaf` |
| 3 | `Description` | 48% | See below |
| 4 | `Quantity` | 12.5% | `15,000` |
| 5 | `Unit Cost` | 12.5% | `200.00` (bold) |
| 6 | `Amount` | 14% | `3,000,000.00` |

- Full grid of thin lines. Headings bold sans, centered. Numbers have thousands separators and two decimals for money.
- Description cell, medicines (file 01): item name; a blank line; `***Supplier's Specifications:`; then `Brand Name: ...`, `Manufacturer: ...`, `Country of Origin: ...` on separate lines.
- Description cell, food (file 02): item name on top; below it `***Supplier's Specifications:` and the text of the specification (for example `soft, densed, at least 40g, preferably Halal`); last line `PICK & GO CONVENIENCE STORE, PHILS.` (supplier name + `PHILS.`). In file 02 the description column has a vertical divider about half way, with the label on the left and the text on the right. File 01 has no such divider.
- After the last line, one row `x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x-x` (centered) carrying the **total amount** in the Amount column; then an empty row; then a centered free-text line (`for Pharmacy use` in 01, `Baked Goods` in 02). Last row: `(Total Amount in Words)` on the left and the amount in words, for example `Five Million Four Hundred Thousand and 0/100 Cents Only` and `Two Hundred Thirty-Four Thousand Eight Hundred Twenty and 0/100 Cents Only`.

### 3.6 Signature block (repeats on every page)
A full-width box:
1. Centered `Very Truly yours,` (capital T), space for a signature, then bold `AFDAL B. KUNTING, MD, MPH, FPCP`, then `Medical Center Chief II`.
2. Left `Conforme:`.
3. Bold small text, two lines, the second line centered:
   `I hereby certify that I am authorized to receive this document and that the company confroms to the Terms and Conditions set forth on the final page of this document.`
   (`confroms` is printed that way on the form. Ask the office whether the typo is kept.)
4. Centered: space for the supplier's signature, then the supplier's printed name (typed in the scan, larger and in a different font, for example `ALFRED RYAN HAMOY`), then `Signature over Printed Name of Supplier`, then the date received (typed in the scan, `NOVEMBER 09, 2026`) above the label `Date Received`.

### 3.7 Fund block (repeats on every page)
Separated from the signature box by a heavier line. Two columns:
- Left: `Fund Cluster:` (line), `Funds Available:` (line), then a signature line with the printed `FRITZIE LYNN T. CABILIN` and `Accountant IV - Accounting Section`.
- Right: `ORS/BURS No.:` (line), `Date of the ORS/BURS:` (line), `Amount:` (line).
- In the scans all of these are handwritten (blue ink), for example `04 (DM)`, `P 5,400,000.00`, `03-207503-2026-11-000116`, `05 NOV 2026`. Only the accountant's name and title are printed.
- In file 02 the same values appear on every page.

### 3.8 Footer (repeats on every page)
Small sans, about 7 pt:
- Left, italic: `(Adopted from Government Accounting Manual: Appendix 61)`; below it `ZCMC-F-PROC-13`
- Next: `Rev. 12`
- Next: `Effectivity date: February 24, 2025`
- Right: `Page:1` (colon, no "of N")

### 3.9 Fonts (best reading of the scan, not confirmed)
Header and title: Times New Roman style. Labels and values: Tahoma or Verdana style, about 8 to 9 pt. Footer: same sans, smaller. I cannot identify the exact fonts from a scan.

### 3.10 Multi-page behavior (file 02)
- Every page repeats: header, title, supplier box, Gentlemen line, place/term box, column headings, signature block, fund block, footer with `Page:N`.
- Pages 1 and 2 have **no** subtotal and **no** "continue to page" row. Only the last page has the `x-x-x` row, the free-text line, the total and the amount in words.
- Page breaks are decided by content height, not by a fixed number of lines (7, 6 and 3 lines on the three real pages).

## 4. Form ZCMC-F-PROC-04A Rev. 0 (Purchase Order with Delivery Schedule)

### 4.1 Header
- Four images in the scan: "Bagong Pilipinas" logo and DOH seal on the left, ZCMC seal and one more seal (unreadable) on the right of the text. The brief uses only `zcmc.png` (left) and `DOH.png` (right). **Confirm that dropping the other two is accepted.**
- Centered text (smaller than in form 13), five lines: `Republic of the Philippines`, `Department of Health`, `ZAMBOANGA CITY MEDICAL CENTER` (larger), `Dr. D. Evangelista St., Sta. Catalina, Zamboanga City 7000` (no comma before 7000), `Tel. No(s): (062) 991-0573, 992-0160`.
- Top right, four right-aligned lines, small bold sans:
  - `Form No: ZCMC-F-PROC-04A`
  - `Revision No: 0`
  - `Effectivity Date: May 25, 2026`
  - `Adopted from Government Accounting Manual: Appendix 61`
- The scans show **no drawn border** around these four lines. The brief says "box". Ask the office.
- The title is **inside** the big bordered box, as its first row: `PURCHASE ORDER WITH DELIVERY SCHEDULE`, bold condensed sans, about 18 pt, centered.

### 4.2 Supplier box (repeats on every page, including the Terms page)
Same two halves as form 13, with these labels: `Supplier :`, `Address :`, `Supply Chain Role:`, `Contact No.:`, `TIN:` and `P.O. No. :`, `Date :`, `P.R. No.`, `CAF No.`, `Mode of Procurement:`. Values are underlined. There is no `Taxes` label. The PO date is written long: `July 28, 2026`. The PO number is hyphenated: `26-07-0592` (form 13 has no hyphens). P.R. No. `26-03-0112`, CAF No. `2026-0860`, Mode of Procurement `Competitive/Public Bidding`.

### 4.3 First page only
Below the supplier box:
- `Gentlemen: Please furnish this Office the following articles subject to the terms and conditions contained herein:`
- `Place of Delivery :` (blank line) and `Date of Delivery :` with `(To be filled by MMS)` (no "the", unlike form 13)
- Right: `Payment Term:` (blank) and `Delivery Schedule :`

These rows are **not** repeated on later pages. Later pages go straight from the supplier box to the column headings.

### 4.4 Item table (headings repeat on every page)

| Column | Printed heading | Width | Content |
|---|---|---|---|
| 1 | `Stock/` `Propert` `y No.` | 4.7% | Running line number: `1` to `10` on page 1, `81` to `90` on the last page (first digit of 81 inferred from the sequence) |
| 2 | `Unit` | 7% | `Vial`, `Tab`, `Jar`, `Ampule`, `Bottle`, `Cap` |
| 3 | `Description` | 32% | Item name, `Brand Name: ...`, `Manufacturer: ...`, `Country of Origin: ...` (4 lines, no `***Supplier's Specifications:` label) |
| 4 | `Total Quantity` | 8% | `170` |
| 5 | `Unit Cost` | 9% | `208.00` |
| 6 | `Total Amount` | 12% | `35,360.00` |
| 7 | `7 Days from Rec. of P.O.` | 9% | Quantity delivered within 7 days of receiving the PO |
| 8, 9, ... | a date, for example `10/5/2026`, `12/5/2026` | 9% to 10% each | Quantity per date |

- Full grid. 10 lines per page in the sample (taller rows than form 13). Headings bold, centered, small.
- Date columns are written `m/d/yyyy` without leading zeros. The sample has two date columns; the maximum is unknown (ask).
- Check on all 20 sample lines: quantities in columns 7 to 9 add up exactly to column 4 (for example 60 + 60 + 50 = 170; 1,200 + 550 + 0 = 1,750; 100 + 200 + 0 = 300).
- Check on the amounts: quantity x unit cost matches Total Amount on all 20 lines.

### 4.5 Bottom of an item page
- Row `x-x-x-x-x-x-x-x-x-x-x-x-x`.
- Page 1: row `continue to page 2...`, then `(Sub Total Words)` with the amount in words (`Nine Hundred Ninety-Eight Thousand Five Hundred Twenty-Five and 0/100 cents only`) and the figure `998,525.00` at the far right.
- Last page: free-text row `for Pharmacy use`; the **page** sum (`2,255,935.00`, verified as the sum of its 10 lines) in the Total Amount column; then `(Total Amount in Words)` with `Twenty-One Million Four Hundred Thousand Nine Hundred Fifty-One and 80/100 cents only` and the **grand total** `21,400,951.80` at the far right. A different total is handwritten next to the printed one, so totals are checked by people.
- So the far-right figure is a running total (`Sub Total` on non-final pages, `Total` on the last page). Whether middle pages also show a page sum in the Total Amount column is unknown.
- `cents only` is lowercase in 04A; form 13 prints `Cents Only`.

### 4.6 Signature block (seen on page 1 and on the last page; assumed on every page)
`Very truly yours,` (lowercase t), signature, bold `AFDAL B. KUNTING, MD, MPH, FPCP`, `Medical Center Chief II`; `Conforme:`; bold small text `I hereby certify that I am authorized to receive this document and that the company agrees to abide by the Terms and Conditions set forth on the final page of this document.`; the supplier's printed name and title (scan: `JULIUS ... YUCOR`, `General Manager`, partly covered by a signature) above `Signature over Printed Name of Supplier`; a handwritten date. There is no `Date Received` label in the sample.

### 4.7 What 04A does not have
No Fund Cluster / Funds Available / ORS-BURS block, no accountant, no footer, and no printed page number in the samples.

### 4.8 Terms and Conditions page (last page, fixed)
- Header, title and supplier box repeat as on every page. No item table.
- Heading `TERMS AND CONDITIONS`, bold, centered, about 11 pt.
- 12 numbered clauses. The number is in a narrow left column, vertically centered against its clause. Text is small (about 8 pt), tightly spaced, in a narrow sans, justified. Clause 2 has sub-items a. to e. (the scan shows the letter `d.` twice). Clause 3 has sub-items a. and b. Everything fits on one page.
- Not transcribed here on purpose (section 11).

## 5. Differences at a glance

| | Form 13 Rev. 12 | Form 04A Rev. 0 |
|---|---|---|
| Title | Outside the box | Inside the box, bold condensed |
| Form number | Footer, with revision and effectivity | Top right, four lines |
| Logos in scan | 2 seals | 4 images |
| Header text | 4 lines | 5 lines (adds Tel. No(s)) |
| Supplier box | Includes `Taxes :` | No `Taxes` |
| First column | Stock/Property code | Running line number |
| Quantity | One column | Total + per-date columns |
| Description | `***Supplier's Specifications:` | Brand / Manufacturer / Country of Origin |
| Subtotals | None; total on last page | `continue to page N...`, running sub total |
| Fund / ORS block | On every page | None |
| Footer / page no. | `Page:N` | None seen |
| Terms page | None in samples | Fixed last page |
| Signature block | Every page | Every page (assumed) |
| PO number style | `26111037` | `26-07-0592` |
| PO date style | `11/05/2026` | `July 28, 2026` |

## 6. Field maps

Status words: **STORED** (we have it), **COMPUTED** (from stored data), **FIXED** (same on every PO), **NOT STORED**, **BLANK** (printed empty, filled by hand).

### 6.1 Form 13

| Field | Source | Status |
|---|---|---|
| Logos, four header lines, title, `Gentlemen` line, place of delivery, `Payment Term: Refer to Terms and Conditions`, certification text, footer | Configuration / template | FIXED |
| Supplier | `suppliers.name` via `purchase_orders.supplier_id` | STORED |
| Address | `suppliers.address` | STORED |
| Supply Chain Role | none | NOT STORED (blank in 01 and 02) |
| Contact No. | none (`suppliers.contact_person` is a name, not a number) | NOT STORED |
| TIN | none | NOT STORED |
| Taxes | none | label only, no value seen |
| P.O. No. | `purchase_orders.po_number` | STORED |
| P.O. Date | `purchase_orders.po_date` (printed `mm/dd/yyyy`) | STORED |
| P.R. No. | none | NOT STORED |
| CAF No. | none | NOT STORED |
| Mode of Procurement | none | NOT STORED |
| Date of Delivery | `(To be filled by the MMS)` | BLANK |
| Delivery Term | `purchase_orders.delivery_term_days` + wording ` Days Term` (empty when null) | COMPUTED |
| Stock/Property No. | none | NOT STORED |
| Unit | `po_items.unit_id` -> `units.name` | STORED |
| Description (item name) | `po_items.description` | STORED |
| `***Supplier's Specifications:` text, or Brand / Manufacturer / Country of Origin | none | NOT STORED |
| Quantity | `po_items.quantity_ordered` | STORED |
| Unit Cost | `po_items.unit_price` | STORED |
| Amount | quantity x unit price (`App\Support\Money`) | COMPUTED |
| Total amount | sum of line amounts | COMPUTED |
| Total in words | helper (section 8) | COMPUTED |
| Free-text line (`for Pharmacy use`, `Baked Goods`) | none | NOT STORED |
| Signatory (Medical Center Chief II) and title | none | NOT STORED (configuration, changes over time) |
| Supplier's printed name, signature, `Date Received` | typed or written by the supplier side | BLANK (to confirm) |
| Fund Cluster, Funds Available, ORS/BURS No., date, amount | Accounting, by hand | BLANK (to confirm) |
| Accountant name and title | none | NOT STORED (configuration) |
| `Page:N` | the PDF | COMPUTED |

### 6.2 Form 04A

| Field | Source | Status |
|---|---|---|
| Logos, header text, form-number lines, title, `Gentlemen` line, certification, Terms page | Configuration / template | FIXED |
| Supplier, Address | `suppliers.name`, `suppliers.address` | STORED |
| Supply Chain Role, Contact No., TIN | none | NOT STORED |
| P.O. No. | `purchase_orders.po_number` | STORED |
| Date | `purchase_orders.po_date` (printed `July 28, 2026`) | STORED |
| P.R. No., CAF No., Mode of Procurement | none | NOT STORED |
| Place of Delivery | `(blank line)` | BLANK on the sample |
| Date of Delivery | `(To be filled by MMS)` | BLANK |
| Payment Term | blank on the sample | BLANK (to confirm) |
| Line number (column 1) | position in the PO | COMPUTED |
| Unit | `units.name` | STORED |
| Description (item name) | `po_items.description` | STORED |
| Brand Name, Manufacturer, Country of Origin | none | NOT STORED |
| Total Quantity | `po_items.quantity_ordered` (also the sum of the per-date columns) | STORED |
| Unit Cost | `po_items.unit_price` | STORED |
| Total Amount | quantity x unit price | COMPUTED |
| Column `7 Days from Rec. of P.O.` | none (not a date; maybe `delivery_term_days`) | NOT STORED, meaning to confirm |
| Date columns (headings) | `po_schedules.scheduled_date` | STORED (printed `m/d/yyyy`) |
| **Quantity per item per column** | none | **NOT STORED** |
| Page sum, running sub total, grand total, amounts in words | computed after pagination | COMPUTED |
| `continue to page N...` | computed after pagination | COMPUTED |
| Free-text line (`for Pharmacy use`) | none | NOT STORED |
| Signatory and title | none | NOT STORED (configuration) |
| Supplier's printed name and title (`... YUCOR`, `General Manager`), signature, date | supplier side | BLANK (to confirm) |

## 7. Missing fields: your list confirmed, corrected, extended

| Your suspicion | Verdict |
|---|---|
| Supplier TIN | Confirmed: not stored. Needed by both forms. |
| Supplier contact number | Confirmed: not stored. `contact_person` is a name. |
| Supplier supply chain role | Confirmed: not stored. Sample value `Distributor`; blank on 13. Need the list of allowed values. |
| P.R. No. | Confirmed: not stored. Format looks like `26-03-0112`. |
| CAF No. | Confirmed: not stored. Format looks like `2026-0860`. |
| Mode of Procurement | Confirmed: not stored. Three values seen: `Competitive/Public Bidding`, `Nego. Proc. - Emergency Cases`, `Nego. Proc. - Small Value Procurement`. Probably a fixed list. |
| Stock/Property No. per line | Confirmed for form 13 (`MED00084`, `SUP11025`). **Correction:** on 04A the first column is a running line number, not a code. |
| Supplier's specifications (brand, manufacturer, country of origin) | Confirmed: not stored. Form 13 sometimes uses free text instead (food items). **Correction to CONTEXT.md:** the note "BRAND not stored" no longer holds for printing; 04A prints brand, manufacturer and country on every line. |
| Place of delivery | Confirmed: not stored. It is the same text on every sample, so it is configuration, not per-PO data, unless some POs deliver elsewhere (ask). |
| Delivery term wording (`7 Days Term`) | **Partly wrong:** the days are stored (`delivery_term_days`); the wording is derived. Nothing to add. A PO with no term prints an empty value. |
| Payment term | Confirmed: not stored. Fixed text `Refer to Terms and Conditions` on every form 13 sample; blank on 04A. Configuration. |
| Signatory (Medical Center Chief) | Confirmed: not stored. Configuration with an effective date, because the person changes. Signature image or blank for a wet signature: ask. |
| Fund Cluster, Funds Available, ORS/BURS No., date, amount | Confirmed: not stored. Handwritten by Accounting in every sample (form 13 only). |
| Quantity per delivery date (04A) | Confirmed and **now required**: 04A cannot be printed without it. |

Added by this analysis:

| Item | Note |
|---|---|
| Free-text line under the items (`for Pharmacy use`, `Baked Goods`) | On both forms, typed per PO. Probably the requesting unit or purpose. |
| Accountant name and title | Printed in the fund block of form 13. Configuration. |
| Supplier's printed name and position | Printed under the supplier signature (`ALFRED RYAN HAMOY`; `General Manager`). Typed by whom? |
| `Date Received` | Filled when the supplier receives the PO. Not known when we print. |
| `Taxes :` | Label only on form 13. |
| `7 Days from Rec. of P.O.` column | Not a date. Needs its own representation (section 9, question 4). |
| Which form a PO uses | Not stored. See section 9. |
| Paper size | Unknown (section 2.1). |

Proposed storage (**nothing is built; each needs your approval before any migration**):

| Data | Proposal | Typed by (to confirm) |
|---|---|---|
| Supplier TIN, contact number, supply chain role | New nullable columns on `suppliers` (`tin`, `contact_no`, `supply_chain_role`) | Procurement, in the supplier form |
| P.R. No., CAF No., mode of procurement, free-text line | New nullable columns on `purchase_orders` (`pr_no`, `caf_no`, `mode_of_procurement`, `remarks`) | Procurement, in the PO form |
| Stock/Property No. | Nullable `stock_no` on `items`, copied as a snapshot to `po_items` | Procurement, in the catalog |
| Brand, manufacturer, country of origin, specification text | Nullable columns on `po_items` (`brand`, `manufacturer`, `country_of_origin`, `specifications`) | Procurement, per line |
| Quantity per item per column | The table already planned in CONTEXT.md decisions 20 and 24: `po_schedule_items` (schedule row, PO item, quantity). Required for 04A. The rule "schedule dates cannot exceed total quantity" (decision 24) must be revisited, because the sample has zeros. | Procurement, in the PO form |
| The non-date column (`7 Days from Rec. of P.O.`) | Option 1: a nullable `days_from_receipt` on `po_schedules`, with a null `scheduled_date`. Option 2: derive it from `delivery_term_days`. Depends on question 4. | Procurement |
| Form type | Derived by a rule, or a stored choice, depending on question 1 | Procurement |
| Signatories (Chief, Accountant), place of delivery, payment term text, form numbers, revisions, effectivity dates | `config/mms.php` (or a small table with an effective date if the people change often) | Admin or IT |
| Fund block (Fund Cluster, Funds Available, ORS/BURS) | **Not stored**, printed blank, unless Accounting wants to type it in the system (a later decision) | Accounting |

## 8. Total amount in words

- Wording seen (title case, hyphen between tens and ones, `and`, centavos as `NN/100`, `0/100` when there are none):
  - Form 13: `Five Million Four Hundred Thousand and 0/100 Cents Only`
  - Form 04A: `Twenty-One Million Four Hundred Thousand Nine Hundred Fifty-One and 80/100 cents only` (lowercase `cents only`)
  - Labels: `(Total Amount in Words)` on both; `(Sub Total Words)` on non-final 04A pages.
- PHP `intl` (`NumberFormatter` with `SPELLOUT`) can produce the words, but it is an optional extension (on the dev machine the CLI uses `C:\php85\php.ini`, and the hospital server may differ).
- **Proposal:** a small pure-PHP helper `App\Support\AmountInWords` (about 40 lines, no dependency, same result on every server), with a unit test using the sample amounts above. `intl` is not required. The form-specific suffix (`Cents Only` / `cents only`) stays in the template.
- Check on your machine (PowerShell):

```powershell
php -r "echo class_exists('NumberFormatter') ? 'intl: yes' : 'intl: no';"
php -m | findstr /i "intl gd mbstring dom zip fileinfo"
php --ini
php -r "echo ini_get('memory_limit');"
```

## 9. Which form is used when, and the questions

### 9.1 Which form

Your guess: 04A when the PO has more than one delivery date. The samples agree with it but also fit another rule:

| Sample | Form | Mode of procurement | Delivery dates |
|---|---|---|---|
| 01 | 13 | Negotiated, emergency | None (7 Days Term) |
| 02 | 13 | Negotiated, small value | None (15 Days Term) |
| 03, 04 | 04A | Competitive / Public Bidding | 3 columns (7 days from receipt, 10/5/2026, 12/5/2026) |

So the rule could be "more than one delivery date", or "bidding POs", or a free choice by Procurement. Ask (question 1).

### 9.2 Questions for Procurement

1. Which form do you use when? Is it by number of delivery dates, by mode of procurement, or your choice? What if a PO has exactly one date? Can a PO switch form after it is created?
2. Which paper is each form printed on: A4 or long bond (8.5 x 13 in)?
3. Who types these today, and in which order: supplier TIN, contact number, supply chain role (allowed values?), P.R. No., CAF No., mode of procurement (fixed list?), Stock/Property No., brand, manufacturer, country of origin, the free-text line (`for Pharmacy use`, `Baked Goods`)?
4. On 04A, what is the column `7 Days from Rec. of P.O.`? Is it always first? Is the 7 always the delivery term of the PO? Is the number of date columns limited? Is quantity per date always stated (zeros allowed)? Which date format: `10/5/2026` is October 5 or 5 December?
5. Form 13: is `Supplier's Specifications` free text, or always brand/manufacturer/country for medicines? What is the last line (`PICK & GO CONVENIENCE STORE, PHILS.`)?
6. Why is the PO number `26111037` on form 13 and `26-07-0592` on 04A? Is the hyphen a real difference? (The PO number rule in 3.3 allows both.)
7. Place of Delivery: always the hospital address? Payment Term: always `Refer to Terms and Conditions` on form 13, and blank on 04A?
8. Date of Delivery and Taxes: always blank?
9. Is the signature of the Medical Center Chief handwritten on the printout, or should the system print a signature image? Who is the signatory when the Chief is absent (OIC)?
10. The supplier's printed name and position under the supplier signature: who types it, and is it the supplier's contact person?
11. Does form 13 always come with a Terms and Conditions page? The certification mentions "the final page of this document", but the form 13 samples have none.
12. Are the forms printed from BizBox? If yes, can you export one PO from BizBox as a **PDF that is not scanned** (text can be selected)? That would give us the exact fonts and wording.
13. Is the form-number text of 04A in a drawn box? Are the Bagong Pilipinas logo and the fourth image required, or are `zcmc.png` and `DOH.png` enough?
14. Does a middle page of a 04A PO also show the page sum and `continue to page N...`? Please send a scan of a middle page, and of a 13 PO with a Terms page if one exists.

### 9.3 Questions for Accounting

1. Do you fill the Fund Cluster, Funds Available, ORS/BURS No., Date of the ORS/BURS and Amount by hand on the printed PO? Do you do it on every page of a multi-page PO, or on one page?
2. Is `Funds Available` the total of the PO or the amount of that page? (In file 02 the same figure is on every page.)
3. Should these fields print blank, or do you want to type them in the system? If typed in the system, who types and who may change them?
4. Who is the printed accountant (name and title) on the form? Does it change?
5. Is the budget stamp (`RECEIVED / RETURNED / RECEIVED / RELEASED`) always applied by hand? (We assume yes and do not print it.)

### 9.4 Questions for the Medical Center Chief's office and IT

- Chief's office: exact name and title for the signature line, the OIC rule, and who approves a change to the Terms and Conditions or to the form revision.
- IT: PHP version and `php.ini` of the hospital server (extensions `dom`, `mbstring`, `gd`, `memory_limit`), whether `storage/` is writable by the web user, and whether Microsoft fonts may be used on the server (section 10).

## 10. Ways to produce the documents

| | (a) .docx from a template (PhpWord `TemplateProcessor`) | (b) Blade/HTML to PDF (dompdf) | (c) .docx to PDF with LibreOffice |
|---|---|---|---|
| Starting point | A .docx template. **The office has none**; we would draw it from the scans. | HTML/CSS written by us from the scans | Same .docx template as (a) |
| IT must install | Composer package; PHP `zip` and `xml` extensions | Composer package; PHP `dom`, `mbstring`, `gd` | Composer package or shell call, **plus LibreOffice (Writer) on the server**, permission for PHP to start it, fonts |
| Works on your Windows XAMPP | Yes | Yes | Yes, if LibreOffice is installed and the path is set |
| Output | .docx (the user prints from Word) | PDF, ready for the consolidated PDF of step 4.3 | PDF |
| Look | Depends on Word, its version and installed fonts | Same on every machine. Fonts are embedded. Not editable by the office | Close to Word, but a missing font (Tahoma, Arial Narrow) is replaced and the layout shifts |
| Repeated table header on every page | Yes (Word "repeat header row" in the template) | Yes for `<thead>` (to confirm in the spike); we avoid relying on it (below) | Yes (reads the Word setting) |
| Block repeated on every page (signature, fund block, footer) | Yes, through the Word page footer | Yes, with our own page blocks | Yes |
| 04A "continue to page N..." and running sub total | **Not possible** from the template: the template does not know where the page ends | Possible, because we decide the page breaks | Not possible, same as (a) |
| Maintenance | Binary .docx outside code review; someone must know Word | Code in the repository | Binary .docx plus a server component |
| Main risk | No template to start from; output varies by Word | We must match the look ourselves; fonts | Heaviest for IT; slow start per document; one conversion at a time unless configured |

Notes:
- PhpWord can also write PDF, but it converts through HTML and loses the layout. Not recommended.
- Page breaks: 04A prints a page sum, a running total and `continue to page N...`. No automatic page-break engine tells us which line ends a page. The same holds for form 13 if we want an exact look. So the recommendation is to **paginate ourselves**: PHP splits the lines into pages using an estimate of how many text lines each row needs (with a safety margin), and each page becomes one fixed-size block with its own header, signature block and footer. dompdf then only draws. This also removes the dependence on repeated headers. A spike must prove it with the sample data (a long Description, many dates).
- Fonts: Times-like and Tahoma-like fonts are needed. Do not copy Microsoft fonts into the repository. Use free look-alikes (for example Liberation Serif and Liberation Sans, or Arimo and Tinos) unless IT and the hospital agree that the fonts installed on a Windows server may be used. I am not giving a legal opinion.
- Logos: `public/images/DOH.png` is 1500 x 1490 px (1.2 MB). Embedding it on every page of a PDF is heavy. Use a small copy (about 300 px) for printing; keep the original. `zcmc.png` is 600 x 600 px (34 KB), fine.
- Fallback if dompdf cannot hold the look: headless Chrome through a Laravel package. It needs Node and Chrome on the server, so it is a second library and a bigger IT request. Only after dompdf fails the spike and IT agrees.

### 10.1 Recommendation

**(b) Blade/HTML to PDF with dompdf, with our own pagination.**

- No template exists, so (a) and (c) give no advantage and add Word or LibreOffice dependencies.
- The result is a PDF with the same look on every machine, which is what the consolidated PDF (step 4.3) needs.
- The "exact look" means the same layout, wording, borders and proportions as the scans, matched by measurement. It cannot be pixel-identical to a Word file we do not have. To get closer, we need a born-digital file or PDF of the form (question 12).

### 10.2 Server requirement

**Updated in step 4.1.** The single install list for IT, covering the PO print (dompdf) and the PDF merge (qpdf), is in `docs/PDF-MERGE.md`, section 8. In short:

- PHP with extensions `dom`, `mbstring`, `gd`, `zlib`; `memory_limit` about 256M (to confirm in the PO print spike); `proc_open` allowed; `storage/` writable.
- **qpdf** 11.x or newer (new since 4.0: the PDF merge needs one small program on the server).
- Composer packages in the project: dompdf (PO print) and `setasign/fpdf` (image pages). Both need your approval; neither is added yet.
- Not needed: LibreOffice, Microsoft Word, Node, Ghostscript, `intl`.

The earlier wording "no `exec()`, no program on the server" no longer applies because of qpdf. The PO print itself still needs no program.

## 11. Terms and Conditions (04A, last page)

- The Terms are fixed text of about 12 clauses with sub-items. The scan is noisy and legal wording, numbers and amounts must be exact, so **the text must not be copied from the scan** (and OCR would add errors).
- **What I need from the office, in this order of preference:**
  1. A born-digital file of form 04A Rev. 0 (Word, Excel or PDF with selectable text). A PO exported from BizBox as PDF also helps.
  2. Otherwise, the Terms and Conditions typed and **approved** by Procurement (or Legal) as plain text, with the clause numbers and sub-letters as they must print, and a statement of what to do with the visible oddities (the letter `d.` appears twice in clause 2; `confroms` in form 13).
  3. A flat, straight, 300 dpi scan of the Terms page (black and white is enough) as a check.
  4. The same for form 13 if it has a Terms page (question 11).
- The text will be stored as a versioned template (one per form revision, for example `04A rev 0`), so a new revision does not change old printouts.

## 12. Decision and what needs your approval

- Decision (recommended, not yet approved): produce both forms as PDF with dompdf, own pagination, own `AmountInWords` helper, signatories and fixed wording in configuration, Terms as a versioned template.
- Before any build step I need from you:
  - Approval to add **one library** (dompdf, for example `barryvdh/laravel-dompdf`). Not added now.
  - Approval of the **migrations** in section 7 (suppliers, purchase_orders, po_items, items, and `po_schedule_items`). None created now. The one for quantity per column is not optional for 04A.
  - **Permissions:** none needed so far. Printing a PO could reuse `po.view`. A separate `po.print` is your choice.
  - The answers to the questions in section 9, or at least questions 1, 2, 4 and 12.
- Suggested next step after your answers: a print spike with dummy data for both forms (no migration needed for the first look), then the migrations, then the PO form changes, then the real print button.