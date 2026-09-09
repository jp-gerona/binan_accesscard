#!/usr/bin/env bash
#
# Registers queue-worker.sh as a cron job that drains the queue on a schedule
# (macOS/Linux). Mirror of install-cron-worker.ps1.
#
#   ./scripts/install-cron-worker.sh                 # every 5 minutes (default)
#   EVERY_MINUTES=5 ./scripts/install-cron-worker.sh # every 5 minutes
#   AT=01:30 ./scripts/install-cron-worker.sh         # nightly at 01:30 local
#   ./scripts/install-cron-worker.sh --uninstall      # remove the cron entry
#
# The entry is tagged with a marker comment so re-running replaces (not duplicates)
# it, and --uninstall removes exactly it. Uses your intl-enabled php via PHP_BIN
# (default: first `php` on PATH).
set -euo pipefail

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WORKER="$PROJECT_DIR/scripts/queue-worker.sh"
MARKER="# binan_accesscard queue worker"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"
EVERY_MINUTES="${EVERY_MINUTES:-5}"
AT="${AT:-}"

# Strip any existing entry for this project (idempotent install / uninstall).
current="$(crontab -l 2>/dev/null || true)"
cleaned="$(printf '%s\n' "$current" | grep -vF "$MARKER" || true)"

if [ "${1:-}" = "--uninstall" ]; then
    printf '%s\n' "$cleaned" | crontab -
    echo "Removed queue worker cron entry."
    exit 0
fi

if [ -z "$PHP_BIN" ]; then
    echo "PHP not found on PATH. Set PHP_BIN to your intl-enabled php." >&2
    exit 1
fi

chmod +x "$WORKER"

# Schedule field: nightly (AT) overrides recurring EVERY_MINUTES.
if [ -n "$AT" ]; then
    hour="${AT%%:*}"; min="${AT##*:}"
    schedule="$((10#$min)) $((10#$hour)) * * *"
else
    schedule="*/$EVERY_MINUTES * * * *"
fi

entry="$schedule PHP_BIN=$PHP_BIN $WORKER >/dev/null 2>&1 $MARKER"

# Reinstall: cleaned crontab + our single entry.
{ [ -n "${cleaned// }" ] && printf '%s\n' "$cleaned"; printf '%s\n' "$entry"; } | crontab -

echo "Installed queue worker cron entry:"
echo "  $entry"
echo "Logs: $PROJECT_DIR/writable/logs/queue-worker.log"
