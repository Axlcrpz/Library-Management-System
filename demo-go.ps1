# demo-go.ps1  —  ONE command to get your public URL.
# It makes sure the LMS is running, then opens the Cloudflare Quick Tunnel and
# prints your public https://<words>.trycloudflare.com URL.
#
# RUN IT LIKE THIS (from C:\xampp\htdocs\library_sys):
#     powershell -ExecutionPolicy Bypass -File .\demo-go.ps1
#
# KEEP THIS WINDOW OPEN during the whole presentation. Press Ctrl+C to end the tunnel.
# No Cloudflare account or login is needed.

$ErrorActionPreference = "Stop"
Set-Location -Path $PSScriptRoot

function Test-Lms {
    try {
        return ((Invoke-WebRequest "http://localhost:8080/health.php" -UseBasicParsing -TimeoutSec 3).Content -match '"status":"ok"')
    } catch { return $false }
}

# 1) Ensure the LMS is up on http://localhost:8080
Write-Host "Checking the LMS on http://localhost:8080 ..." -ForegroundColor Cyan
if (-not (Test-Lms)) {
    Write-Host "Not up yet - starting the Docker stack..." -ForegroundColor Yellow
    docker compose up -d
    $up = $false
    for ($i = 0; $i -lt 45; $i++) { if (Test-Lms) { $up = $true; break }; Start-Sleep -Seconds 2 }
    if (-not $up) {
        Write-Host "LMS did not become healthy. Check:  docker compose logs web" -ForegroundColor Red
        exit 1
    }
}
Write-Host "LMS is UP." -ForegroundColor Green

# 2) Ensure cloudflared is present
if (-not (Test-Path ".\cloudflared.exe")) {
    Write-Host "cloudflared.exe is missing from this folder." -ForegroundColor Red
    Write-Host "Download 'cloudflared-windows-amd64.exe' from:" -ForegroundColor Yellow
    Write-Host "  https://github.com/cloudflare/cloudflared/releases/latest" -ForegroundColor Yellow
    Write-Host "Rename it to cloudflared.exe and place it here, then re-run." -ForegroundColor Yellow
    exit 1
}

# 3) Open the public tunnel (stays running in THIS window; your URL prints below)
Write-Host ""
Write-Host "=====================================================================" -ForegroundColor Cyan
Write-Host " Opening your public URL. Look for a line like:" -ForegroundColor Yellow
Write-Host "     https://<random-words>.trycloudflare.com" -ForegroundColor Green
Write-Host " Copy that and send it to your client." -ForegroundColor Yellow
Write-Host " KEEP THIS WINDOW OPEN. Press Ctrl+C when the demo is over." -ForegroundColor Yellow
Write-Host "=====================================================================" -ForegroundColor Cyan
Write-Host ""

& ".\cloudflared.exe" tunnel --url http://localhost:8080
