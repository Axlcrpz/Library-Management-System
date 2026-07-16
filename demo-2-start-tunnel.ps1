# demo-2-start-tunnel.ps1
# Opens a TEMPORARY public HTTPS URL to the LMS using a Cloudflare Quick Tunnel.
# No Cloudflare account, login, or domain is required.
#
# KEEP THIS WINDOW OPEN during the whole presentation. The public URL stays alive
# only while this is running. Press Ctrl+C (or run demo-3-stop-tunnel.ps1) to end it.

Set-Location -Path $PSScriptRoot

if (-not (Test-Path ".\cloudflared.exe")) {
    Write-Host "cloudflared.exe not found in this folder." -ForegroundColor Red
    Write-Host "Download it from: https://github.com/cloudflare/cloudflared/releases/latest" -ForegroundColor Yellow
    Write-Host "(get 'cloudflared-windows-amd64.exe', rename to cloudflared.exe, put it here)" -ForegroundColor Yellow
    exit 1
}

Write-Host "Opening a public tunnel to http://localhost:8080 ..." -ForegroundColor Cyan
Write-Host "Watch for a line like:  https://<random-words>.trycloudflare.com" -ForegroundColor Yellow
Write-Host "That is the URL to send to your client. Keep this window open." -ForegroundColor Yellow
Write-Host ""

& ".\cloudflared.exe" tunnel --url http://localhost:8080
