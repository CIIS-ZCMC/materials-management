<#
  Test-PdfTools.ps1  (step 4.1, run on the Windows XAMPP machine; changes nothing in the project)

  Usage (PowerShell, from any folder):
    powershell -ExecutionPolicy Bypass -File .\docs\pdf-spike\Test-PdfTools.ps1 -Folder "C:\pdf-samples"

  -Folder   a folder with your sample PDFs (the scanner PDFs, the Word-exported PDF, ...).
  -Qpdf     optional full path to qpdf.exe if it is not on PATH.
  -Gs       optional full path to gswin64c.exe if it is not on PATH.

  Writes pdf-spike-results.txt next to the samples. Send me that file.
#>
param(
    [Parameter(Mandatory = $true)][string]$Folder,
    [string]$Qpdf = "qpdf",
    [string]$Gs = "gswin64c"
)

$ErrorActionPreference = "Continue"
$report = New-Object System.Collections.Generic.List[string]
function Say([string]$text) { Write-Host $text; $report.Add($text) }

function Has-Tool([string]$name) { return [bool](Get-Command $name -ErrorAction SilentlyContinue) }

$work = Join-Path $Folder "_spike_out"
New-Item -ItemType Directory -Force -Path $work | Out-Null
$pdfs = Get-ChildItem -Path $Folder -Filter *.pdf -File | Sort-Object Name
Say "== Sample files ($($pdfs.Count))"
foreach ($f in $pdfs) { Say ("  {0}  {1:N0} KB" -f $f.Name, ($f.Length / 1KB)) }
if ($pdfs.Count -eq 0) { Say "No PDF files found in $Folder"; exit 1 }

# ---------------------------------------------------------------- PHP
Say ""
Say "== PHP"
if (Has-Tool "php") {
    Say ("  version: " + (& php -r "echo PHP_VERSION;"))
    Say ("  php.ini: " + (& php -r "echo php_ini_loaded_file();"))
    foreach ($ext in @("dom", "mbstring", "gd", "zlib", "intl", "exif")) {
        $on = & php -r "echo extension_loaded('$ext') ? 'yes' : 'no';"
        Say ("  extension {0,-9} {1}" -f $ext, $on)
    }
    Say ("  memory_limit: " + (& php -r "echo ini_get('memory_limit');"))
    Say ("  proc_open available: " + (& php -r "echo function_exists('proc_open') ? 'yes' : 'no';"))
    Say ("  disable_functions: [" + (& php -r "echo ini_get('disable_functions');") + "]")
}
else { Say "  php is not on PATH (use the XAMPP or C:\php85 path)" }

# ---------------------------------------------------------------- qpdf
$haveQpdf = Has-Tool $Qpdf
Say ""
Say "== qpdf"
if ($haveQpdf) {
    Say ("  " + ((& $Qpdf --version) | Select-Object -First 1))
    $sum = 0
    $readable = @()
    foreach ($f in $pdfs) {
        $n = & $Qpdf --show-npages $f.FullName 2>$null
        $code = $LASTEXITCODE
        if ($code -eq 0 -or $code -eq 3) {
            $sum += [int]$n
            $readable += $f.FullName
            $note = ""
            if ($code -eq 3) { $note = "  (qpdf warnings, exit 3: file is still usable)" }
            Say ("  {0}: {1} pages{2}" -f $f.Name, $n, $note)
        }
        else { Say ("  {0}: qpdf cannot read it (exit {1})" -f $f.Name, $code) }
    }
    $out = Join-Path $work "merged_qpdf.pdf"
    $argv = @("--empty", "--pages") + $readable + @("--", $out)
    $t = Measure-Command { & $Qpdf @argv }
    Say ("  merge of {0} files: exit {1} (0 or 3 = success), {2:N2} s, {3:N0} KB, pages {4} (expected {5})" -f $readable.Count, $LASTEXITCODE, $t.TotalSeconds, ((Get-Item $out).Length / 1KB), (& $Qpdf --show-npages $out), $sum)
    & $Qpdf --check $out *> $null
    Say ("  qpdf --check on the merged file: exit {0} (0 = ok, 3 = warnings, 2 = errors)" -f $LASTEXITCODE)
}
else { Say "  qpdf not found. Download the Windows zip from the qpdf GitHub releases page, unzip it, and add its bin folder to PATH (or pass -Qpdf)." }

# ---------------------------------------------------------------- Ghostscript
$haveGs = Has-Tool $Gs
Say ""
Say "== Ghostscript (only for comparison)"
if ($haveGs) {
    Say ("  version: " + (& $Gs --version))
    $out = Join-Path $work "merged_gs.pdf"
    $files = @(); foreach ($f in $pdfs) { $files += $f.FullName }
    $argv = @("-q", "-dNOPAUSE", "-dBATCH", "-dSAFER", "-sDEVICE=pdfwrite", "-dCompatibilityLevel=1.4", "-sOutputFile=$out") + $files
    $t = Measure-Command { & $Gs @argv }
    Say ("  merge: exit {0}, {1:N2} s, {2:N0} KB" -f $LASTEXITCODE, $t.TotalSeconds, ((Get-Item $out).Length / 1KB))
}
else { Say "  Ghostscript not installed (fine: it is not the recommended method)." }

# ---------------------------------------------------------------- damaged files
Say ""
Say "== Damaged-file behaviour"
$first = $pdfs[0].FullName
$bytes = [IO.File]::ReadAllBytes($first)
$cut = [Math]::Min(300000, [int]($bytes.Length / 2))
$trunc = Join-Path $work "damaged_truncated.pdf"
[IO.File]::WriteAllBytes($trunc, $bytes[0..($cut - 1)])
$text = Join-Path $work "not_a_pdf.pdf"
Set-Content -Path $text -Value ("This is plain text renamed .pdf`r`n" * 20)
foreach ($bad in @($trunc, $text)) {
    $name = Split-Path $bad -Leaf
    if ($haveQpdf) {
        & $Qpdf --check $bad *> $null
        Say ("  qpdf --check {0}: exit {1} (2 = reject the upload)" -f $name, $LASTEXITCODE)
    }
    if ($haveGs) {
        $o = Join-Path $work ("gs_" + $name)
        & $Gs -q -dNOPAUSE -dBATCH -dSAFER -sDEVICE=pdfwrite -sOutputFile="$o" $bad *> $null
        $size = if (Test-Path $o) { (Get-Item $o).Length } else { 0 }
        Say ("  gs {0}: exit {1}, output {2} bytes (a small file with exit 0 = silent failure)" -f $name, $LASTEXITCODE, $size)
    }
}

$resultFile = Join-Path $Folder "pdf-spike-results.txt"
$report | Set-Content -Path $resultFile -Encoding UTF8
Say ""
Say "Saved: $resultFile"