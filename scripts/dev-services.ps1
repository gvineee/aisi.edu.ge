# Starts local dev services that don't run as Windows services.
#
# PostgreSQL 17 is installed as a proper Windows service ("postgresql-x64-17")
# and starts automatically with Windows — nothing to do for it here.
#
# Redis is installed as a portable Scoop package (no admin rights were
# available on this machine to register it as a service), so it has to be
# started manually each time this machine reboots. Run this script once per
# session before `composer run dev`.

$ErrorActionPreference = "Stop"

$redisDataDir = "$env:USERPROFILE\scoop\persist\redis-data"
New-Item -ItemType Directory -Force -Path $redisDataDir | Out-Null

$alreadyRunning = Get-Process -Name "redis-server" -ErrorAction SilentlyContinue
if ($alreadyRunning) {
    Write-Host "redis-server already running (PID $($alreadyRunning.Id))"
} else {
    $env:Path += ";$env:USERPROFILE\scoop\shims"
    Start-Process -FilePath "redis-server.exe" `
        -ArgumentList "--port 6379 --daemonize no --dir `"$redisDataDir`" --save 60 1" `
        -WindowStyle Hidden
    Start-Sleep -Seconds 2
    Write-Host "redis-server started"
}

$env:Path += ";$env:USERPROFILE\scoop\shims"
redis-cli ping

$pg = Get-Service -Name "postgresql-x64-17" -ErrorAction SilentlyContinue
if ($pg -and $pg.Status -ne "Running") {
    Write-Host "Starting postgresql-x64-17 service..."
    Start-Service -Name "postgresql-x64-17"
}
Write-Host "PostgreSQL service status: $((Get-Service -Name 'postgresql-x64-17').Status)"
