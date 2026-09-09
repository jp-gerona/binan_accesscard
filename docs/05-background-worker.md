# The background worker

A family Excel import can carry thousands of rows. That work does not fit inside
a web request: it would hit the request timeout, chew through the memory limit,
and hold a PHP worker hostage while every other user waits.

So it does not run there. A request only writes a row into `job_queue` and
returns immediately with "queued, waiting for worker". A separate process drains
the queue.

This is why an import can appear to do nothing. If no worker is running, the job
sits in the queue and the import screen waits forever, with no error, because
nothing has gone wrong yet.

## How it fits together

**The queue** is the `job_queue` table, described in chapter 02. Each row has a
`type`, a JSON `payload`, a status (`pending`, `processing`, `done`, `partial`,
`failed`), progress counters, a checkpoint so a long job can resume rather than
restart, a result blob, and locking columns so two workers cannot claim the same
job.

**The engine** is `php spark queue:work`, implemented in
`app/Commands/QueueWork.php`. It claims pending jobs and dispatches each one by
`type` to its handler, registered in `app/Config/Queue.php`. Today there is one
handler: `family_import`, handled by `app/Jobs/FamilyImportJob.php`. Adding a job
type means writing a handler that implements `JobHandlerInterface` and adding one
line to the config. The retained `media_reconcile` handler drains any legacy rows
created before direct reconciliation was introduced. It scans the configured
private family-media folder and updates the media registry; it does not serve a
page and it does not watch the filesystem.

**The wrappers** are the scripts you actually run. `scripts/queue-worker.sh` and
`scripts/queue-worker.ps1` drain the queue once and exit.
`scripts/install-cron-worker.sh` and `scripts/install-cron-worker.ps1` register
that drain on a schedule.

You rarely call `spark queue:work` directly. You either drain once by hand while
developing, or install the schedule and forget about it.

## Draining once, by hand

Good enough for local development. Run it after kicking off an import.

```bash
# macOS and Linux
./scripts/queue-worker.sh                 # drain now, 250ms throttle
THROTTLE=500 ./scripts/queue-worker.sh    # gentler on the database

# or call the engine directly, on any platform
php spark queue:work --throttle=250
```

```powershell
# Windows
.\scripts\queue-worker.ps1
```

## Running it on a schedule

Installs a cron job on macOS and Linux, or a Scheduled Task on Windows, that
runs every five minutes by default. Imports then finish without anyone watching
them.

```bash
# macOS and Linux
./scripts/install-cron-worker.sh
EVERY_MINUTES=5 ./scripts/install-cron-worker.sh   # every five minutes instead
AT=01:30 ./scripts/install-cron-worker.sh          # nightly at 01:30
./scripts/install-cron-worker.sh --uninstall

# The installer uses the first `php` on PATH. Override it for the intl build:
PHP_BIN=/opt/local/bin/php ./scripts/install-cron-worker.sh
```

```powershell
# Windows, in an elevated PowerShell
cd C:\xampp\htdocs\binan_accesscard
Set-ExecutionPolicy -Scope Process Bypass -Force
.\scripts\install-cron-worker.ps1 -EveryMinutes 5
.\scripts\install-cron-worker.ps1 -Uninstall
```

The Windows installer registers a task called **BinanQueueWorker** and runs it
as `SYSTEM` so it can operate unattended. In a managed deployment, replace that
account with a dedicated service account that has read and write access to
`writable/`, `writable/uploads/`, the configured media root, and MySQL, and
nothing else. The worker parses untrusted uploaded files, so keep that account
as limited as the deployment permits.

## Family-media reconciliation

Every scheduled fire first runs `php spark media:reconcile`, then drains the
shared queue. Reconciliation is direct maintenance work, not a queued job: an
idle scan creates no `job_queue` row, writes no audit row, and stays out of the
worker log. The command scans `familymediasettings.root` once and updates the
registry only when a file needs attention. This is automatic reconciliation, not
a reconciliation page and not a filesystem watcher.

The folder must be configured before the scheduled worker is installed. The
worker account needs read and write access to it in addition to its existing
`writable/` access. Chapter 06 gives the deployment command and ownership rule;
chapter 11 gives the filename and correction workflow.

Logs land in `writable/logs/queue-worker.log`:

```bash
tail -f writable/logs/queue-worker.log
```

## When an import is stuck on "queued, waiting for worker"

Nothing is draining the queue. Check in this order.

1. **Is MySQL up?** The worker aborts if it cannot connect, and says so in the
   log.
2. **Is the worker running at all?** Drain once by hand with
   `./scripts/queue-worker.sh`. If that clears the backlog, your schedule is not
   firing and the problem is the schedule, not the job.
3. **Windows laptop on battery?** Scheduled Tasks skip on battery power unless
   configured otherwise. The installer handles this, but a task created any other
   way will not. `scripts/README.md` has the fix.
4. **Was the machine asleep or off?** Scheduled ticks do not fire then. Drain
   the backlog by hand once it is awake.

The full worker reference, including the tuning flags, how to add a job type, and
deeper Windows troubleshooting, is in `scripts/README.md`.

Chapter 12 covers the import itself, and chapter 06 covers running the worker as
a service in a real deployment.
