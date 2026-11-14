# Finds files whose content is all zero bytes (the right size but blank). Such a file makes PHP, Vite and the tests fail in confusing ways.
# Run from the project root (the folder with artisan or package.json):   powershell -ExecutionPolicy Bypass -File scripts\find-corrupted-files.ps1
# Add -Lint in the backend to also syntax-check every PHP file in app, tests, routes, config and database.
param([string]$Path = ".", [switch]$Lint)

$folders = @("app","tests","routes","config","database","resources","src","docs","scripts") |
    ForEach-Object { Join-Path $Path $_ } | Where-Object { Test-Path $_ }
$patterns = @("*.php","*.js","*.jsx","*.json","*.md","*.css","*.xml","*.ps1","*.txt")
$blank = @()

foreach ($folder in $folders) {
    foreach ($file in (Get-ChildItem -Path $folder -Recurse -File -Include $patterns -ErrorAction SilentlyContinue)) {
        if ($file.Length -eq 0) { continue }
        $stream = [System.IO.File]::OpenRead($file.FullName)
        try {
            $count = [int][Math]::Min(4096, $file.Length)
            $buffer = New-Object byte[] $count
            [void]$stream.Read($buffer, 0, $count)
        } finally { $stream.Close() }
        $hasData = $false
        foreach ($b in $buffer) { if ($b -ne 0) { $hasData = $true; break } }
        if (-not $hasData) { $blank += $file.FullName }
    }
}

if ($blank.Count -eq 0) { Write-Host "No blank (all-zero) files found." -ForegroundColor Green }
else {
    Write-Host ("{0} BLANK file(s) found. Re-extract them from the repair pack:" -f $blank.Count) -ForegroundColor Red
    $blank | ForEach-Object { Write-Host "  $_" }
}

if ($Lint) {
    Write-Host "Syntax-checking PHP files..."
    $failed = 0
    foreach ($folder in @("app","tests","routes","config","database")) {
        $dir = Join-Path $Path $folder
        if (-not (Test-Path $dir)) { continue }
        foreach ($php in (Get-ChildItem -Path $dir -Recurse -Filter *.php -File)) {
            $result = & php -l $php.FullName 2>&1
            if ($LASTEXITCODE -ne 0) { $failed++; Write-Host ("  " + $php.FullName) -ForegroundColor Red; Write-Host ("    " + ($result -join " ")) }
        }
    }
    if ($failed -eq 0) { Write-Host "All PHP files pass php -l." -ForegroundColor Green } else { Write-Host "$failed PHP file(s) failed." -ForegroundColor Red }
}
