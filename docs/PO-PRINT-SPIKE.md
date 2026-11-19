# PO print spike (step 4.4)

Written 2026-11-18. Spike folder `C:\print-spike` (outside the project). No project file, migration, permission or library was changed. dompdf approved for the spike folder only; paper: A4 and long bond (8.5 x 13 in) both tested; fonts: Liberation Serif and Sans (SIL Open Font License, shipped in the spike folder).

Goal agreed on 2026-11-18: the printout does not have to match the scan pixel for pixel (the office has no digital original); the **content must be correct and readable**, with small layout adjustments.

## 1. Status

| Item | Result |
|---|---|
| dompdf v3.1.6 on the dev machine (PHP 8.5.12) | Works. 24 PDFs (2 forms x 6 cases x 2 papers) rendered without error in every run. |
| Fonts | Run 3 (2026-11-18): Liberation Serif and Sans are **embedded** (subsets) after the font URLs were changed to plain `C:/...` paths. |
| Form 13 page count (plan = PDF) | 12 of 12 in runs 1 to 3. |
| Form 04A page count (plan = PDF) | Run 1: 12 of 12. Run 3: **each item page spilled onto an extra PDF page** (plan 2, PDF 3, and so on). Fix applied in the next version: every block of a 04A page is placed with explicit top and height inside a page 6 pt shorter than the paper (like the grid cells), so nothing can be pushed over the edge. **To confirm in the next run.** |
| `AmountInWords` and `Paginator` tests | 28 checks pass (`php tests\run-tests.php`). |
| Layout adjustments asked by the user | Done: space under the header (04A address/Tel line no longer touches the box), space under the "PURCHASE ORDER" titles, table left border visible, larger Terms text (8.5 pt). |

## 2. Measured (run 1, dompdf, Windows)

| Measure | Value |
|---|---|
| memory_limit | Runs fine at **64M** (also 96M, 128M, 192M, 256M). Peak real memory 46 MB for all 24 PDFs in one process. 64M is the lowest value tried; recommend 128M for the server. |
| Time per PDF | Run 1: 1.0 to 1.9 s; run 3 (fonts embedded): 1.3 to 3.2 s (1 page about 1.7 s, 5 to 6 pages about 2.8 s); the first PDF after a fresh start is slower (font cache). |
| PDF size | Run 1 (fallback fonts): 326 to 348 KB. Run 3 (Liberation embedded): about 380 to 410 KB. |
| Logos | `zcmc.png` 34 KB and `DOH-small.png` 88 KB (300 px), repeated on every page without a problem. |

`run.php` prints the fonts found inside the PDFs (`fonts embedded in the PDFs:`); it must list Liberation.

## 3. What the scan comparison showed (run 1) and what was done

1. **Fonts ignored.** dompdf did not use Liberation (text came out in Times). Cause: my own font registration code. Text widths were also only estimates, because measuring ran before registration. Fix: fonts are loaded with `@font-face` (file URLs inside the spike folder), and text widths are now read from the same `.ttf` files by our own small reader (`Ttf.php`, exact: 145.84 pt for the test string, checked against the font file). The run prints a self-test line.
2. **Table column widths ignored.** dompdf made the columns almost equal, so descriptions overflowed into the quantity column (very visible on form 04A). Fix: no HTML tables for the item grid, the supplier box, the fund block and the footer; every cell is placed with explicit left/top/width/height (`Ab.php`). We own the page geometry anyway because we paginate ourselves.
3. **No space under the header** (and Terms page). Fix: 8 pt (form 13) and 10 pt (form 04A) of space between the header and the title box, and 20 pt above "TERMS AND CONDITIONS".
4. Also fixed: the 04A header text was clipped (the text block is now wide enough and does not wrap), the supplier name wraps by our own measuring, the form 13 footer no longer wraps, the Terms numbers sit next to their text.

## 4. Differences from the scans that remain (accepted: content is what matters)

- Fonts are Liberation look-alikes, not Times New Roman / Tahoma; line breaks inside descriptions can differ from the scan.
- Logos: two (ZCMC left, DOH right). The 04A scan has four images (Bagong Pilipinas and one more seal are missing).
- Form 13 food items: the scan has a vertical divider between the label and the specification text; ours prints them on one line.
- 04A title in bold regular width (scan: condensed bold). The `X-X-X` and `x-x-x` runs are shorter than the scan.
- Row heights and the exact positions of labels are close, not identical.
- The stamp, handwriting and signatures are not printed (by design). Fund Cluster, Funds Available, ORS/BURS stay blank lines.
- Terms page: TEMPORARY placeholder text (marked in red); the real text must come from the office.
- Page count differs by paper: form 13 with 17 lines is 3 pages on long bond (7/7/3, same as scan 02) and 4 pages on A4.

## 5. Corrections to PO-TEMPLATE.md found while building the test data

- Scan 02 has 17 lines in 3 pages: **7 / 7 / 3** (not 7 / 6 / 3); with those 17 lines the total 234,820.00 adds up exactly.
- 04A page 1 line 2 (`Allopurinol 300mg tablet`) has quantity 1,700, split 600 + 1,100 + 0. The 1,750 example in section 4.4 is line 83.
- The 20 test lines total 3,254,460.00, not the scan's grand total 21,400,951.80 (the 60 middle lines are not in the sample).
- Open: how centavos print other than 0 and 80 (the helper prints `05/100`).

## 6. Server needs

- PHP extensions `dom`, `mbstring`, `gd`, `zlib`; `intl` not needed. `memory_limit` 128M recommended (64M worked for the spike). A writable folder for the dompdf font cache. The six Liberation `.ttf` files (2.4 MB) ship with the application. The PDF merge needs qpdf as already noted in `docs/PDF-MERGE.md` section 8.
- Time: about 1 to 2 s per PO PDF. Do not generate on every page view; generate on a "Print" click (or cache the file).

## 7. Verdict

(Updated 2026-11-18, after run 3.)

Form 13 and form 04A: **dompdf is workable** (installs, fast enough, small memory, page counts match our plan in 24 of 24 cases). The first run's visual problems were bugs in my code, not limits of dompdf, and are fixed; Liberation is embedded and the absolute layout renders without overlaps (run 3). **One open point: the 04A extra-page spill** (section 1); the next run must show planned pages = PDF pages for every `f04a_*` case. Form 13 is confirmed. If run 2 still shows ignored fonts or positions, the fallback is headless Chrome (Node and Chrome on the server, only if IT agrees); the same HTML pages would be reused.

## 8. Files and approach to reuse in 4.6 (form 13) and 4.7 (form 04A)

Move to `app/Support/Print/` (namespace `App\Support\Print`) and reuse as they are: `AmountInWords.php`, `Paginator.php`, `Ttf.php`, `Fonts.php` (font directory from config), `Ab.php` (absolute-position helpers), `Money.php` (merge into the project's `App\Support\Money`), `Paper.php`, `Html.php`.
Starting point, to be fed by a view model from `PurchaseOrder` instead of the dummy arrays: `Form13.php`, `Form04A.php` (the height constants at the top of each class are the only numbers to tune), `Config.php` (signatories, header text, certification text move to `config/mms.php`), `Renderer.php` (dompdf options: `chroot`, `fontDir`, `fontCache`, no remote).
Fonts to `resources/fonts/`, logos to `resources/images/` (not `public/`). Rules learned: font URLs in `@font-face` must be plain paths like `C:/print-spike/fonts/x.ttf` (not `file:///C:/...`, which dompdf rejects on Windows); no HTML table widths, no automatic wrapping (every text line is its own no-wrap `div` after our own measuring), one `div.page` per printed page, the last page reserves room for the total rows; place the blocks of a page with explicit top/height (see `pageShell()` in `Form04A.php`) and keep the page a few points shorter than the paper; keep the grid 1.4 pt narrower than the page and indent it 0.7 pt so neither border is clipped.
Still needed before 4.6 and 4.7 (unchanged from PO-TEMPLATE.md section 12): the migrations (TIN, contact number, supply chain role, P.R. No., CAF No., mode of procurement, stock no., brand/manufacturer/country, free text, `po_schedule_items`), dompdf in the project, and the office's answers (which form when, paper, the `7 Days` column, the Terms text).