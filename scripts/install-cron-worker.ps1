<#
.SYNOPSIS
    Registers the binan_accesscard queue worker as a Windows Scheduled Task.

.DESCRIPTION
    Run ONCE in an elevated (Administrator) PowerShell. It registers a Scheduled
    Task that fires queue-worker.ps1 on a schedule (every five minutes by default); each
    fire drains the job_queue and exits. The task runs as SYSTEM with highest
    privileges, so it works even when no one is logged in -- and because it runs in
    session 0, no console window ever appears on your desktop.

    This is the installer only. queue-worker.ps1 is the actual worker.

.PARAMETER Throttle
    Milliseconds paused between chunks -- DB breathing room for other users.

.EXAMPLE
    # Standard: drain every five minutes (run as Administrator)
    cd C:\xampp\htdocs\binan_accesscard
    Set-ExecutionPolicy -Scope Process Bypass -Force
    .\scripts\install-cron-worker.ps1 -EveryMinutes 5

.EXAMPLE
    # Nightly at 01:30 instead of every five minutes
    .\scripts\install-cron-worker.ps1 -At 01:30

.EXAMPLE
    # Remove the task
    .\scripts\install-cron-worker.ps1 -Uninstall
#>
param(
    [int]    $EveryMinutes = 5,
    [string] $At           = '',
    [int]    $Throttle     = 250,
    [int]    $Drainers     = 1,
    [int]    $MaxSeconds   = 50,
    [string] $TaskName     = 'BinanQueueWorker',
    [switch] $Uninstall
)

$ErrorActionPreference = 'Stop'

# Must be elevated to register a SYSTEM task.
$id = [Security.Principal.WindowsIdentity]::GetCurrent()
if (-not ([Security.Principal.WindowsPrincipal]$id).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Error 'Must run as Administrator. Right-click PowerShell -> Run as administrator, then re-run.'
    exit 1
}

$scriptDir  = Split-Path -Parent $MyInvocation.MyCommand.Definition
$projectDir = Split-Path -Parent $scriptDir
$worker     = Join-Path $scriptDir 'queue-worker.ps1'
$logPath    = Join-Path $projectDir 'writable\logs\queue-worker.log'

if (-not (Test-Path $worker)) {
    Write-Error "Worker script not found: $worker"
    exit 1
}

if ($Uninstall) {
    schtasks /delete /tn $TaskName /f
    exit $LASTEXITCODE
}

# What the task runs each fire: the worker, hidden, with the tuning params.
$psArgs = '-NonInteractive -ExecutionPolicy Bypass -WindowStyle Hidden -File "' + $worker + '" ' +
          "-Throttle $Throttle -Drainers $Drainers -MaxSeconds $MaxSeconds"
$run    = 'powershell ' + $psArgs

if ($At -ne '') {
    $schedule = @('/sc', 'daily', '/st', $At)
} else {
    $schedule = @('/sc', 'minute', '/mo', [string][Math]::Max(1, $EveryMinutes))
}

$taskArgs = @('/create', '/tn', $TaskName, '/f', '/ru', 'SYSTEM', '/rl', 'HIGHEST', '/tr', $run) + $schedule
& schtasks $taskArgs

if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}

# schtasks-created tasks inherit Windows' laptop power-gating: "don't start on
# battery" + "stop when unplugged". On a laptop that silently prevents the
# scheduled drain whenever it's on battery (jobs sit `pending` -> the UI hangs
# on "waiting for worker"). Clear both and let missed ticks catch up so the worker
# runs regardless of power state.
try {
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop
    $task.Settings.DisallowStartIfOnBatteries = $false
    $task.Settings.StopIfGoingOnBatteries     = $false
    $task.Settings.StartWhenAvailable         = $true
    Set-ScheduledTask -TaskName $TaskName -Settings $task.Settings | Out-Null
    Write-Host 'Cleared battery power-gating (worker runs on battery too).'
} catch {
    Write-Warning "Task created, but could not clear battery power-gating: $($_.Exception.Message)"
}

if ($At -ne '') { $when = "nightly at $At" } else { $when = "every $EveryMinutes minute(s)" }

Write-Host ("Installed '" + $TaskName + "' - fires " + $when + " (Throttle=" + $Throttle + "ms, Drainers=" + $Drainers + ", MaxSeconds=" + $MaxSeconds + ").")
Write-Host ''
Write-Host 'Verify it is running:'
Write-Host ('  Get-ScheduledTask ' + $TaskName + ' | Select TaskName, State')
Write-Host ('  Get-ScheduledTaskInfo ' + $TaskName + ' | Select LastRunTime, LastTaskResult, NextRunTime')
Write-Host ('  Get-Content "' + $logPath + '" -Tail 30 -Wait')
