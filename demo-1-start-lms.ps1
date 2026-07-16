# demo-1-start-lms.ps1
# Starts the LMS development stack. The app will be at http://localhost:8080.
# (Run this FIRST, before the tunnel.)

$ErrorActionPreference = "Stop"
Set-Location -Path $PSScriptRoot

Write-Host "Starting the LMS (Docker dev stack)..." -ForegroundColor Cyan
docker compose up -d

Write-Host "Waiting for the app + database to be ready (can take ~30s on first run)..." -ForegroundColor Cyan
$ready = $false
for ($i = 0; $i -lt 45; $i++) {
    try {
        $r = Invoke-WebRequest -Uri "http://localhost:8080/health.php" -UseBasicParsing -TimeoutSec 3
        if ($r.Content -match '"status":"ok"') { $ready = $true; break }
    } catch { }
    Start-Sleep -Seconds 2
}

if ($ready) {
    Write-Host ""
    Write-Host "LMS is UP  ->  http://localhost:8080" -ForegroundColor Green
    Write-Host "Log in with your existing development admin account." -ForegroundColor Green
    Start-Process "http://localhost:8080"
} else {
    Write-Host ""
    Write-Host "The app did not pass its health check yet." -ForegroundColor Yellow
    Write-Host "Check the logs with:  docker compose logs web" -ForegroundColor Yellow
}
