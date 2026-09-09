# Background queue worker

Heavy work (a very large family Excel import today; exports/reports later) runs OFF
the web request through a generic job queue, so it never hits the request timeout /
memory limit and does **not** slow down other users. A web request only enqueues a
`job_queue` row; this worker drains it.

- **Worker:** [`queue-worker.ps1`](queue-worker.ps1) — drains the queue once and exits.
- **Installer:** [`install-cron-worker.ps1`](install-cron-worker.ps1) — registers the worker as a Scheduled Task.
- **Engine:** `php spark queue:work` (dispatches each job by `type` to its handler in `App\Config\Queue`).
- **Log:** `writable/logs/queue-worker.log`

## Install the worker as a scheduled task (Admin PowerShell)

```powershell
cd C:\xampp\htdocs\binan_accesscard
Set-ExecutionPolicy -Scope Process Bypass -Force
.\scripts\install-cron-worker.ps1 -EveryMinutes 5
```

This registers **BinanQueueWorker** as a Windows Scheduled Task that fires every
five minutes, reconciles media directly, drains the queue, and exits. The
included installer runs it as `SYSTEM` so it can run unattended. In a managed
deployment, replace that account with a dedicated service account limited to
`writable/`, `writable/uploads/`, the configured media root, and MySQL access.

### Tuning options

| Option          | Default | Meaning                                                                 |
|-----------------|---------|-------------------------------------------------------------------------|
| `-EveryMinutes` | `5`     | Minutes between drains (ignored if `-At` is set).                       |
| `-At`           | —       | Nightly `HH:mm` local time; overrides the recurring schedule.           |
| `-Throttle`     | `250`   | Milliseconds paused between chunks — DB breathing room for other users. |
| `-Drainers`     | `1`     | Parallel drainers per fire. Claims are atomic so >1 is safe; keep at 1. |
| `-MaxSeconds`   | `50`    | Stop claiming new jobs after this many seconds (in-progress job finishes).|

```powershell
# Examples
.\scripts\install-cron-worker.ps1 -At 01:30          # nightly instead of the five-minute default
.\scripts\install-cron-worker.ps1 -Throttle 500      # gentler on the database
.\scripts\install-cron-worker.ps1 -Uninstall         # remove the task
```

## Verify the worker is running

```powershell
Get-ScheduledTask BinanQueueWorker | Select TaskName, State
Get-ScheduledTaskInfo BinanQueueWorker | Select LastRunTime, LastTaskResult, NextRunTime

# Watch the log
Get-Content "C:\xampp\htdocs\binan_accesscard\writable\logs\queue-worker.log" -Tail 30 -Wait
```

## Run a drain by hand (no task)

```powershell
.\scripts\queue-worker.ps1                 # drain now, default 250ms throttle
php spark queue:work --throttle=250         # or call the engine directly
```

## Add a new background job type

1. Write a handler implementing `App\Jobs\JobHandlerInterface`.
2. Register it in `App\Config\Queue::$handlers` under a unique `type`.
3. Enqueue work: `(new \App\Models\Jobs\JobQueueModel())->enqueue('<type>', $payload, ...)`.

The worker dispatches by `type` automatically — no worker changes needed.

## Troubleshooting: import stuck on "queued - waiting for worker"

A `job_queue` row stays `pending` because nothing is draining it. Check, in order:

1. **Is MySQL running?** The worker aborts if it can't connect (see the log). Start it in XAMPP Control Panel.
2. **On a laptop / on battery?** Windows gates scheduled tasks with "don't start on
   battery". `install-cron-worker.ps1` now clears this, but a task created before that
   fix (or re-created another way) will silently skip every fire while unplugged.
   Confirm + fix (elevated):
   ```powershell
   (Get-ScheduledTaskInfo BinanQueueWorker).NumberOfMissedRuns   # >0 = being skipped
   $t = Get-ScheduledTask BinanQueueWorker
   $t.Settings.DisallowStartIfOnBatteries = $false
   $t.Settings.StopIfGoingOnBatteries     = $false
   $t.Settings.StartWhenAvailable         = $true
   Set-ScheduledTask BinanQueueWorker -Settings $t.Settings
   ```
   > Note: a non-elevated `Get-ScheduledTask BinanQueueWorker` returns "not found"
   > even when the task exists — it runs under a service account. Use `schtasks /query /tn
   > BinanQueueWorker`: "Access is denied" means it exists; "cannot find the file"
   > means it doesn't.
3. **Machine was asleep/off?** Scheduled ticks don't run then; they resume on wake
   (with `StartWhenAvailable`). Drain the backlog now with `.\scripts\queue-worker.ps1`.
