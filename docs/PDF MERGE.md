# PDF merge spike (step 4.1)

Written 2026-11-16. Spike only: no feature, no project file changed, nothing added to the project.
Depends on `docs/PO-TEMPLATE.md` (step 4.0). The ONE install list for IT is in section 8; it replaces section 10.2 of `PO-TEMPLATE.md`.

## 1. Decision (recommended, needs your approval)

| Question | Answer |
|---|---|
| Merge method | **qpdf** (command-line tool), called from Laravel with the built-in `Process` facade. |
| Image (JPG/PNG) to page | **FPDF** (one small PHP file, no dependencies) plus about 40 lines of our own code. JPEG bytes are embedded unchanged; phone rotation is applied with the PDF page's `/Rotate`, not by re-encoding. |
| Fallback if IT refuses to run programs | FPDI free + FPDF (pure PHP). It cannot read newer PDFs (section 4), so Word-exported PDFs would be rejected at upload. |
| Ghostscript | Not recommended (section 3). |
| JPG/PNG uploads | Technically fine. Recommended: allow `pdf`, `jpg`, `jpeg`, `png` (decided and built in step 4.2). |
| Server requirement | qpdf installed; PHP `proc_open` allowed; PHP extensions `dom`, `mbstring`, `gd`, `zlib`. Full list in section 8. |
| Changes the 4.0 requirement | Yes. 4.0 said "no `exec()`, no program on the server". With qpdf that is no longer true: one small program (qpdf) must be installed. |

## 2. What was tested

Environment: Linux sandbox (Ubuntu 24.04), PHP 8.3.6, qpdf 11.9.0, Ghostscript 10.02.1, FPDI 2.6.10 with FPDF. **Windows XAMPP was not tested** (section 9). Timings are from this sandbox and only good for comparing tools with each other.

### 2.1 Your files (real)
Eight scanner PDFs from `PO_docx.zip` (files named `IAR 26-2183` to `IAR 26-2192`, with gaps in the numbers).
- All are PDF 1.4 from the office scanner. Every page is one image at 200 dpi, either JPEG (color) or CCITT G4 (1-bit black and white), mixed inside the same file. No text layer, no annotations, no outline.
- Page counts: 4, 4, 7, 7, 5, 14, 6 and 4 pages. The 14-page file is `26-2190` (Savelink). The 4-page files are `26-2183`, `26-2184` and `26-2192`.
- Page sizes vary a lot, even inside one file (the 14-page file has 8 different sizes), from 314 x 452 pt (small slips scanned at real size) to 611 x 943 pt (long bond). They are whole bundles (IAR, delivery receipts, PO pages) scanned in one go; the system will receive them as separate uploads.

### 2.2 Stand-ins I made (synthetic, not the real thing)
You had no phone photo or Word PDF to give, so I made these. They test the behavior, not your actual files:
- `phone_photo.jpg`: 4032 x 3024 JPEG with EXIF orientation 6 (what a phone writes when held upright), 1 MB.
- `screenshot_alpha.png`: 1200 x 1700 PNG with transparency; `big_screenshot.png`: 3000 x 4000 PNG.
- `word_like_1.7.pdf`: a text PDF rewritten as PDF 1.7 with cross-reference and object streams, which is how modern Word exports are built. **It is not a real Word file.**
- An annotated PDF (a link), a password-protected PDF, a truncated PDF, a PDF with a destroyed trailer, a text file renamed `.pdf`, a PDF with two pages marked `/Rotate 90`, and a 9.6 MB, 6-page PDF.

When you have a real phone photo and a real Word PDF, run section 9 again with them. A real Word PDF is the most important missing test (section 4).

## 3. Results

| Test | qpdf 11.9.0 | Ghostscript 10.02.1 | FPDI 2.6.10 + FPDF |
|---|---|---|---|
| Merge 3 real scans (4 + 4 + 14 = 22 pages): all pages, right order | 22/22 | 22/22 | 22/22 |
| Image streams byte-identical to the input | **22/22** | 0/22 (see below) | **22/22** |
| Page sizes kept (mixed sizes) | Yes | Yes | Yes |
| Size of the three inputs 2,369 KB becomes | 2,363 KB | 2,343 KB | 2,370 KB |
| Pages with `/Rotate 90` | Kept as is, identical render | Render differs (mean difference 3.5 and 14.9 of 255) | Rotation baked into the page, identical render |
| 14-page scan alone | 0.01 s | 0.19 s | 0.02 s |
| 9.6 MB, 6 pages | 0.02 s, 13 MB RAM | 0.30 s, 25 MB | 0.04 s, 36 MB |
| **Word-style PDF 1.7** | Works | Works | **Fails** |
| Annotation (link) | Kept | Kept | **Dropped** |
| Mixed bundle: scan + phone photo page + Word-style PDF + 14-page scan + PNG page (23 pages) | 23/23 pages, order and image bytes identical | 23 pages | **Fails** on the Word-style PDF; without it 20/20 |

- **qpdf** copies the page objects and image streams unchanged. Pixel difference against the input: 0.
- **FPDI** also keeps the image streams unchanged (renders differ by at most 0.007% of pixels, which is sub-pixel placement).
- **Ghostscript** rewrites every image stream. On the 14 pages I checked, the decoded pixels were identical, but the page transform is rewritten and renders differ slightly (mean difference up to 6 of 255 on 1-bit pages). It looks lossless in these samples, but "unchanged" cannot be proven with a byte check, and the result depends on its settings.
- FPDI's error on the Word-style PDF, exactly: `This PDF document probably uses a compression technique which is not supported`. That matches what you read: the free parser only reads the older PDF format. This was reproduced with a stand-in, so confirm with a real Word PDF.

### 3.1 How a bad file fails

| File | qpdf | Ghostscript | FPDI |
|---|---|---|---|
| Password-protected | Exit 2, `invalid password`, no output file | **Exit 0**, 2 KB empty output | Exception: encrypted |
| Truncated | Exit 2, `file is damaged`, no output file | **Exit 0**, 2 KB output, "No pages will be processed" | Exception: no xref pointer |
| Trailer destroyed | Exit 2, no output file | Exit 0, repaired silently (4 of 4 pages came back) | Exception |
| Text file named `.pdf` | Exit 2, `can't find PDF header` | Exit 1, 2 KB junk file left behind | Exception: no PDF header |

Ghostscript reports success (exit 0) while writing an empty or junk file. That is the dangerous case for a document system. qpdf stops with exit 2 and writes nothing. `qpdf --check file.pdf` returned 0 for the good files and 2 for all four bad ones, so it works as the upload validator. **Exit 3 means "succeeded with warnings"** and the file is usable: see section 3.3, where two of the office's own PO files gave it.

### 3.2 Image to page (JPG and PNG) without a heavy library
- **FPDF** (single file, MIT-style licence, no dependencies) plus our own function. Measured:
  - 12 MP phone JPEG: 0.02 to 0.06 s, 27 MB RAM. The JPEG inside the PDF is **byte-identical** to the uploaded file, so no quality loss and no decoding.
  - Phone rotation: the EXIF orientation is read with a tiny parser (no `exif` extension needed) and applied as the page's `/Rotate` (90, 180 or 270). The test photo came out upright.
  - PNG with transparency, 1200 x 1700: 0.23 s, 42 MB RAM. PNG without transparency, 3000 x 4000: 0.02 s (data is passed through).
  - Per FPDF's source (not tested): interlaced PNG is refused. iPhone HEIC is not supported by anything here.
- dompdf (already proposed in 4.0 for the PO print) could place an `<img>` on a page, but it does not read EXIF rotation and decodes PNG through GD (more memory). Not measured; not needed if FPDF is used.
- Re-encoding with GD would work but loses quality (JPEG again) and uses much more memory. Not recommended.

Reference code (spike version, from the tested script; the real version is written in the build step):

```php
function exifOrientation(string $file): int
{
    $d = file_get_contents($file, false, null, 0, 65536);
    if ($d === false || substr($d, 0, 2) !== "\xFF\xD8") return 1;
    $pos = 2;
    while ($pos + 4 < strlen($d)) {
        if ($d[$pos] !== "\xFF") return 1;
        $marker = ord($d[$pos + 1]);
        $len = unpack('n', substr($d, $pos + 2, 2))[1];
        if ($marker === 0xE1 && substr($d, $pos + 4, 6) === "Exif\0\0") {
            $t = $pos + 10;
            $le = substr($d, $t, 2) === 'II';
            $u16 = fn ($o) => unpack($le ? 'v' : 'n', substr($d, $o, 2))[1];
            $u32 = fn ($o) => unpack($le ? 'V' : 'N', substr($d, $o, 4))[1];
            $ifd = $t + $u32($t + 4);
            $n = $u16($ifd);
            for ($i = 0; $i < $n; $i++) {
                $e = $ifd + 2 + 12 * $i;
                if ($u16($e) === 0x0112) return $u16($e + 8);
            }
            return 1;
        }
        if ($marker === 0xDA) return 1;
        $pos += 2 + $len;
    }
    return 1;
}

// One image -> one PDF page. $pdf is an FPDF object.
[$w, $h] = getimagesize($file);
$o = $ext === 'png' ? 1 : exifOrientation($file);
$rot = [3 => 180, 6 => 90, 8 => 270][$o] ?? 0;
$dw = in_array($rot, [90, 270]) ? $h : $w;      // displayed size in pixels
$dh = in_array($rot, [90, 270]) ? $w : $h;
$scale = min((210 - 10) / $dw, (297 - 10) / $dh);  // fit A4 with a 5 mm margin
$iw = $w * $scale;
$ih = $h * $scale;
$pdf->AddPage($rot && $iw > $ih ? 'L' : 'P', $rot ? [$iw, $ih] : [210, 297], $rot);
$rot ? $pdf->Image($file, 0, 0, $iw, $ih)
     : $pdf->Image($file, (210 - $iw) / 2, (297 - $ih) / 2, $iw, $ih);
```

Note: FPDF stores a page size as portrait and swaps it for `'L'`; passing the size the wrong way round gave a sideways page in my first test.

### 3.3 Windows run (qpdf 12.4.2, PHP 8.5.12) and the exit 3 case

Run by you on 2026-11-17 with the four PO scans (01 to 04) and four more office files (05 to 08):
- qpdf 12.4.2 installed with the Windows installer to `C:\tools\qpdf`; it runs from the full path, no PATH entry needed.
- PHP 8.5.12 (`C:\php85\php.ini`): `dom`, `mbstring`, `gd`, `zlib` and `intl` on, `exif` off (not needed), `proc_open` available, `disable_functions` empty, `memory_limit` 128M (CLI; PO print will need more, see 4.0).
- Merge of 5 files: exit 0, 0.07 s, pages as expected; `qpdf --check` of the result: exit 0. A truncated file and a text file named `.pdf` both gave `--check` exit 2 (rejected), as on Linux.
- **Three files (02, 04 and 07) gave qpdf exit 3** and my first script version wrongly skipped them. I reproduced it on 02 and 04 here: the warning is `reported number of objects (26) is not one plus the highest object number (179)`, a harmless bookkeeping mismatch (typical of a PDF cut out of a bigger one). qpdf still reads them ("operation succeeded with warnings", 4 and 2 pages). Merging 01 + 02 + 03 + 04 gave 8 pages (1 + 4 + 1 + 2), merge exit 3, output size 744 KB, and `qpdf --check` of the merged file exit **0** (the warning is cleaned by the merge). With `--warning-exit-0` the merge exits 0.
- Rule for the build: exit 0 and 3 are success; exit 2 is a rejected file.
- **Re-run with the fixed script (all 8 office files, 13 pages):** merge exit 3, 0.07 s, 1,448 KB, 13 pages (expected 13), `qpdf --check` of the merged file exit 0. Files 02, 04 and 07 gave the same harmless warning; qpdf's own message says the result "may have some problems", but the check of the merged file was clean. Ghostscript was not installed on this machine (not needed).

## 4. Why qpdf

| | qpdf | Ghostscript | FPDI free + FPDF |
|---|---|---|---|
| Keeps scans unchanged | Yes, byte for byte | Pixels yes, streams rewritten | Yes, byte for byte |
| Reads any PDF version (Word, newer scanners) | **Yes** | Yes | **No** (older PDFs only) |
| Keeps annotations | Yes | Yes | No |
| Failure behavior | **Clear exit code, no file** | Exit 0 with an empty or junk file | Clear exception |
| Speed | Fastest | About 10 to 20 times slower | Fast |
| Needs a program on the server | Yes (small) | Yes (large installer) | No |
| Licence | Apache 2.0 (check `qpdf --copyright`) | AGPL (or a paid licence) | MIT, FPDF permissive |
| Fallback value | | | Pure PHP, works where programs are not allowed |

Risk to know about: the main cost of qpdf is operational (IT installs one program and allows PHP to start it). The office's own files are all PDF 1.4 and would pass FPDI, but a Word-exported PDF, a PDF printed from a newer program or a newer scanner would not.

## 5. Proposed upload rules for step 4.2 (not built)

- Allowed: `pdf`, `jpg`, `jpeg`, `png`. Keep 10 MB per file (`mms.uploads.max_size_kb`). Today `mms.php` allows only `pdf`; the change is in 4.2.
- Validate a PDF at upload with `qpdf --check`: exit 0 accepts; exit 2 rejects with a plain message ("The file is damaged or password-protected. Rescan it or remove the password."); exit 3 (succeeded with warnings) is **accepted**, because real office files give it (section 3.3). Reject only exit 2.
- Check a JPEG/PNG by its real content type, not only the extension. Reject interlaced PNG and HEIC with a plain message.
- Merge order is exactly the order of the arguments to qpdf: first upload = first pages. Each image becomes one page converted at upload or at merge time (decided in 4.2/4.3).
- Image page size: A4 fitted in the spike. Whether to use A4 or long bond is part of the paper-size question in `PO-TEMPLATE.md` (2.1).

## 6. Command that will be used (reference)

```powershell
qpdf --empty --pages first.pdf second.pdf third.pdf -- merged.pdf
qpdf --check upload.pdf
qpdf --show-npages upload.pdf
```

From Laravel: `Illuminate\Support\Facades\Process::run([$qpdf, '--empty', '--pages', ...$files, '--', $out])` with the path from `.env`. This uses `proc_open`, which Laravel already depends on; no new library.

## 7. What I could not verify
- The Apache/PHP service account's rights on the qpdf folder (the Windows run below used your own account).
- A real phone photo and a real Word PDF. The stand-ins behave as described, but a real Word PDF is the key test for the FPDI fallback.
- PHP 8.5 with FPDF. FPDI 2.6.10 declares PHP `>=7.2 <8.7`; FPDF's own PHP range was not checked. Confirm when it is installed.

## 8. ONE install list for IT (replaces section 10.2 of `PO-TEMPLATE.md`)

| Item | Requirement |
|---|---|
| PHP | The version the app runs on (your dev machine has PHP 8.5 in `C:\php85`) |
| PHP extensions | `dom`, `mbstring`, `gd` (PNG with transparency, PO print), `zlib`, plus the ones Laravel already needs. **Not needed:** `intl`, `exif`, `imagick`, `zip` |
| PHP settings | `memory_limit` about 256M (to confirm in the PO print spike); `proc_open` must **not** be in `disable_functions` |
| Program | **qpdf**, version 11.x or newer (11.9.0 was tested). Windows: unzip the official Windows build (for example to `C:\tools\qpdf`); the program is `bin\qpdf.exe`. Linux: install the distribution package `qpdf` |
| How the app finds it | An absolute path in `.env` (planned key `MMS_QPDF_PATH`), so the web server's PATH does not matter. The account that runs Apache or PHP needs read and execute rights on the qpdf folder |
| Composer packages (installed in the project, approval needed) | dompdf (from 4.0) and `setasign/fpdf` (this step) |
| Fonts for the PO print | TTF files in the repository (4.0, section 10) |
| Writable folders | `storage/` (font cache, temporary merge files) |
| **Not needed** | LibreOffice, Word, Node, Ghostscript, Imagick |

Smoke test for IT after installing (PowerShell):

```powershell
& "C:\tools\qpdf\bin\qpdf.exe" --version
php -r "echo function_exists('proc_open') ? 'proc_open: yes' : 'proc_open: no';"
php -m | findstr /i "dom mbstring gd zlib"
```

## 9. Windows check (you run it)

1. Put your sample PDFs (the scanner PDFs, a real Word PDF, anything else) in one folder, for example `C:\pdf-samples`.
2. Download the Windows build of qpdf from the qpdf releases page on GitHub, unzip it to `C:\tools\qpdf`.
3. Run (PowerShell):

```powershell
powershell -ExecutionPolicy Bypass -File .\docs\pdf-spike\Test-PdfTools.ps1 -Folder "C:\pdf-samples" -Qpdf "C:\tools\qpdf\bin\qpdf.exe"
```

4. It lists PHP extensions and `disable_functions`, merges every PDF in the folder with qpdf (and with Ghostscript if installed), checks the page count, times it, and tries a truncated file and a text file renamed `.pdf`. It writes `pdf-spike-results.txt` in your sample folder. Send me that file.

The script changes nothing in the project.

## 10. What needs your approval before step 4.2

- **Programs:** qpdf on the server (new requirement).
- **Library:** `setasign/fpdf` (small, no dependencies). If you prefer no new library, the alternative is about 80 lines of our own PDF writer for JPEG only, with PNG converted through GD (lossy) or refused.
- **Migration:** none. **Permission:** none.
- **Decision for 4.2:** allow `jpg`/`jpeg`/`png` uploads (recommended) or keep PDF only.