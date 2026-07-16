# demo-3-stop-tunnel.ps1
# Ends the Cloudflare tunnel. The public URL dies immediately.
# The LMS keeps running locally (this does NOT stop Docker).

Write-Host "Stopping the Cloudflare tunnel..." -ForegroundColor Cyan
$procs = Get-Process cloudflared -ErrorAction SilentlyContinue
if ($procs) {
    $procs | Stop-Process -Force
    Write-Host "Tunnel stopped. The public URL is now dead." -ForegroundColor Green
} else {
    Write-Host "No tunnel was running." -ForegroundColor Green
}
Write-Host "The LMS is still running locally at http://localhost:8080." -ForegroundColor Green
