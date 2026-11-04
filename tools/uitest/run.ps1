# Logged-in UI check against sms_db_uitest on a separate PHP server (127.0.0.1:8090).
# XAMPP and sms_db are untouched; DB_NAME is set for the test server process only.
param(
    [ValidateSet('admin', 'staff', 'reviewer', 'viewer', 'legacyadmin', 'all')]
    [string]$Role = 'all',
    [string]$Urls = '/dashboard/dashboard.php',
    [string]$Widths = '1440,390',
    [int]$IdleSeconds = 0,
    [switch]$Stay,
    [int]$ServerPort = 8090,
    [int]$CdpPort = 9340,
    [switch]$NoJs,
    [switch]$Picker,
    [switch]$CtxFlow,
    [switch]$CtxNav,
    [switch]$CtxScenario,
    [string]$Program = "BSCRIM",
    [string]$Prefer = "",
    [string]$Probe = "",
    [int]$Height = 0,
    [switch]$WarnPreview,
    [string]$Out = ''
)
$ErrorActionPreference = 'Stop'
$Database = 'sms_db_uitest'
$Php = 'C:\xampp\php\php.exe'
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$DocRoot = 'C:\xampp\htdocs'
$Port = $ServerPort

& $Php (Join-Path $PSScriptRoot 'guard.php') $Database | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Guard rejected the database name.' }
$EnvFile = Join-Path $Root '.env.uitest'
if (-not (Test-Path $EnvFile)) { throw 'Missing .env.uitest; run tools/uitest/setup-db.ps1 first.' }
$Creds = @{}
foreach ($line in Get-Content $EnvFile) {
    if ($line -match '^(UITEST_[A-Z]+_(USER|PASS))=(.*)$') { $Creds[$Matches[1]] = $Matches[3] }
}
if (-not $Out) { $Out = Join-Path $env:TEMP ("csms-uitest\" + (Get-Date -Format 'yyyyMMdd_HHmmss')) }
New-Item -ItemType Directory -Force $Out | Out-Null

$psi = New-Object System.Diagnostics.ProcessStartInfo
$psi.FileName = $Php
$psi.Arguments = "-S 127.0.0.1:$Port -t `"$DocRoot`""
$psi.UseShellExecute = $false
$psi.RedirectStandardOutput = $true
$psi.RedirectStandardError = $true
$psi.EnvironmentVariables['DB_NAME'] = $Database
$psi.EnvironmentVariables['SMS_SESSION_COOKIE_SECURE'] = '0'
$psi.EnvironmentVariables['CIMF_AI_ENABLED'] = 'false'
$server = [System.Diagnostics.Process]::Start($psi)
# Drain the server's request log so its pipes never fill and block it.
$server.BeginOutputReadLine()
$server.BeginErrorReadLine()
try {
    $ready = $false
    for ($i = 0; $i -lt 40 -and -not $ready; $i++) {
        Start-Sleep -Milliseconds 250
        try { $c = New-Object Net.Sockets.TcpClient('127.0.0.1', $Port); $c.Close(); $ready = $true } catch {}
    }
    if (-not $ready) { throw "Test server did not start on 127.0.0.1:$Port." }
    $roles = if ($Role -eq 'all') { @('admin', 'staff', 'reviewer', 'viewer') } else { @($Role) }
    $failed = 0
    foreach ($r in $roles) {
        $key = $r.ToUpper()
        if (-not $Creds["UITEST_${key}_USER"]) { Write-Warning "No credentials for $r"; $failed++; continue }
        $env:UITEST_USER = $Creds["UITEST_${key}_USER"]
        $env:UITEST_PASS = $Creds["UITEST_${key}_PASS"]
        $nodeArgs = @((Join-Path $PSScriptRoot 'screens.cjs'), "--role=$r", "--out=$Out", "--urls=$Urls", "--widths=$Widths",
            "--base=http://127.0.0.1:$Port/sms-csms")
        $nodeArgs += "--cdp-port=$CdpPort"
        if ($IdleSeconds -gt 0) { $nodeArgs += "--idle=$IdleSeconds" }
        if ($Height -gt 0) { $nodeArgs += "--height=$Height" }
        if ($Probe) { $nodeArgs += "--probe=$Probe" }
        if ($CtxScenario) { $nodeArgs += "--ctxscenario=1" }
        if ($CtxNav) { $nodeArgs += "--ctxnav=1"; $nodeArgs += "--program=$Program"; if ($Prefer) { $nodeArgs += "--prefer=$Prefer" } }
        if ($CtxFlow) { $nodeArgs += "--ctxflow=1"; $nodeArgs += "--program=$Program"; if ($Prefer) { $nodeArgs += "--prefer=$Prefer" } }
        if ($Picker) { $nodeArgs += '--picker=1' }
        if ($WarnPreview) { $nodeArgs += "--warnpreview=1" }
        if ($NoJs) { $nodeArgs += '--nojs=1' }
        if ($Stay) { $nodeArgs += "--stay=1" }
        node @nodeArgs
        if ($LASTEXITCODE -ne 0) { $failed++ }
    }
    Write-Host "Screenshots and reports: $Out"
    if ($failed) { exit 1 }
} finally {
    Remove-Item Env:UITEST_USER, Env:UITEST_PASS -ErrorAction SilentlyContinue
    if (-not $server.HasExited) { $server.Kill() }
}
