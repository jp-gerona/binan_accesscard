<#
.SYNOPSIS
    Drains the binan_accesscard background job queue once, then exits.

.DESCRIPTION
    Runs `php spark queue:work`, which processes every queued job_queue job OFF the
    web request, so heavy work (a very large Excel import today, exports/reports
    later) runs in the background and does NOT block or slow interactive users.

    This is the WORKER. It is invoked on a schedule by the Scheduled Task that
    install-cron-worker.ps1 registers (every minute by default). To run it by hand:
        .\queue-worker.ps1                 # drain now, default 250ms throttle
        .\queue-worker.ps1 -Throttle 500   # gentler on the DB

.PARAMETER Throttle
    Milliseconds to pause between chunks. This protects other users: a huge job
    yields the database periodically instead of monopolising it.

.PARAMETER Drainers
    Parallel drainers to spawn this fire. Claims are atomic so >1 is safe, but more
    drainers = more load; keep at 1 unless the backlog truly needs it.

.PARAMETER MaxSeconds
    Stop claiming NEW jobs after this many seconds (an in-progress job still finishes).
#>
param(
    [int] $Throttle   = 250,
    [int] $Drainers   = 1,
    [int] $MaxSeconds = 50
)

$ErrorActionPreference = 'Stop'

# Project root = parent of this scripts\ folder.
$projectDir = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Definition)
$spark      = Join-Path $projectDir 'spark'
$logDir     = Join-Path $projectDir 'writable\logs'
$logFile    = Join-Path $logDir 'queue-worker.log'

# Prefer the XAMPP PHP this project runs under; fall back to PATH.
$phpExe = 'C:\xampp\php\php.exe'
if (-not (Test-Path $phpExe)) {
    $cmd = Get-Command php -ErrorAction SilentlyContinue
    if ($null -eq $cmd) {
        Write-Warning "PHP not found at $phpExe and not on PATH. Edit `$phpExe in this script."
        exit 1
    }
    $phpExe = $cmd.Source
}

if (-not (Test-Path $logDir)) {
    New-Item -ItemType Directory -Force -Path $logDir | Out-Null
}

function Write-Log([string]$message) {
    $stamp = (Get-Date).ToString('yyyy-MM-dd HH:mm:ss')
    Add-Content -Path $logFile -Value "[$stamp] $message" -Encoding utf8
}

# Enqueue a single media reconciliation run before draining the shared queue. The
# producer's lock plus JobQueueModel::enqueueIfNoActive prevents overlapping
# scheduler fires from stacking jobs; exit code is always successful here.
$reconcileOutput = & $phpExe $spark 'media:queue-reconcile' 2>&1
if ($reconcileOutput -and $reconcileOutput.Trim() -ne '') {
    Write-Log $reconcileOutput.Trim()
}

# Run one or more drainers (each with its own lock inside PHP) and wait for them.
$procs = @()
$temps = @()

for ($i = 1; $i -le [Math]::Max(1, $Drainers); $i++) {
    $out = [System.IO.Path]::GetTempFileName()
    $err = [System.IO.Path]::GetTempFileName()
    $temps += @($out, $err)

    $argList = @($spark, 'queue:work', "--throttle=$Throttle", "--drainer-id=$i", "--max-seconds=$MaxSeconds")

    $procs += Start-Process -FilePath $phpExe -ArgumentList $argList `
        -WindowStyle Hidden -PassThru `
        -RedirectStandardOutput $out -RedirectStandardError $err
}

# Allow slack beyond the budget for a long in-progress job to finish.
$procs | Wait-Process -Timeout ([Math]::Max(60, $MaxSeconds + 600)) -ErrorAction SilentlyContinue

foreach ($t in $temps) {
    if (Test-Path $t) {
        $text = (Get-Content $t -Raw)
        if ($text -and $text.Trim() -ne '') {
            Write-Log $text.Trim()
        }
        Remove-Item $t -Force -ErrorAction SilentlyContinue
    }
}

# Keep the log from growing unbounded (trim past 5 MB).
if ((Test-Path $logFile) -and ((Get-Item $logFile).Length -gt 5MB)) {
    $tail = Get-Content $logFile -Tail 2000
    Set-Content -Path $logFile -Value $tail -Encoding utf8
}
