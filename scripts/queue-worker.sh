#!/usr/bin/env bash
#
# Drains the binan_accesscard background job queue once, then exits (macOS/Linux).
#
# Mirror of queue-worker.ps1 for non-Windows dev. Runs `php spark queue:work`, which
# processes every queued job_queue job OFF the web request, so heavy work (a very
# large Excel import today, exports/reports later) runs in the background and does
# NOT block or slow interactive users.
#
# This is the WORKER. It is invoked on a schedule by the cron entry that
# install-cron-worker.sh registers (every minute by default). To run it by hand:
#     ./scripts/queue-worker.sh                 # drain now, default 250ms throttle
#     THROTTLE=500 ./scripts/queue-worker.sh    # gentler on the DB
#
# Env overrides:
#   PHP_BIN     php binary to use (default: first `php` on PATH; must have intl).
#   THROTTLE    ms paused between chunks so big jobs don't starve others (default 250).
#   MAX_SECONDS stop claiming new jobs after this many seconds (default 50).
set -euo pipefail

# Project root = parent of this scripts/ folder.
PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="$PROJECT_DIR/writable/logs"
LOG_FILE="$LOG_DIR/queue-worker.log"

PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
THROTTLE="${THROTTLE:-250}"
MAX_SECONDS="${MAX_SECONDS:-50}"

if [ -z "$PHP_BIN" ]; then
    echo "PHP not found on PATH. Set PHP_BIN to your intl-enabled php." >&2
    exit 1
fi

mkdir -p "$LOG_DIR"

stamp() { date '+%Y-%m-%d %H:%M:%S'; }

# Enqueue a single media reconciliation run before draining the shared queue. The
# producer's lock plus JobQueueModel::enqueueIfNoActive prevents overlapping
# scheduler fires from stacking jobs; exit code is always successful here.
reconcile_output="$("$PHP_BIN" "$PROJECT_DIR/spark" media:queue-reconcile 2>&1 || true)"

if [ -n "${reconcile_output// }" ]; then
    printf '[%s] %s\n' "$(stamp)" "$reconcile_output" >> "$LOG_FILE"
fi

# Drain once; capture output, prefix each line with a timestamp into the log.
output="$("$PHP_BIN" "$PROJECT_DIR/spark" queue:work \
    --throttle="$THROTTLE" --max-seconds="$MAX_SECONDS" 2>&1 || true)"

if [ -n "${output// }" ]; then
    printf '[%s] %s\n' "$(stamp)" "$output" >> "$LOG_FILE"
fi

# Keep the log from growing unbounded (trim to last 2000 lines past ~5 MB).
if [ -f "$LOG_FILE" ] && [ "$(wc -c < "$LOG_FILE")" -gt 5242880 ]; then
    tail -n 2000 "$LOG_FILE" > "$LOG_FILE.tmp" && mv "$LOG_FILE.tmp" "$LOG_FILE"
fi
