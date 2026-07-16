# demo-4-stop-lms.ps1
# OPTIONAL — only run this AFTER the presentation if you want to shut the LMS down.
# Your data is preserved (kept in the db_data volume); nothing is deleted.

$ErrorActionPreference = "Stop"
Set-Location -Path $PSScriptRoot

Write-Host "Stopping the LMS Docker stack..." -ForegroundColor Cyan
docker compose down

Write-Host "LMS stopped. Your database and uploads are preserved." -ForegroundColor Green
Write-Host "To start it again later, run: demo-1-start-lms.ps1" -ForegroundColor Green
