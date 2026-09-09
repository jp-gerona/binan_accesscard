# Media reconciliation and UI remediation design

**Date:** 2026-09-09
**Status:** Approved for planning

## Goal

Repair the regressions introduced by the recent import-review, family-media, and
import-age commits. Keep user-triggered work in `job_queue`, but remove periodic
media reconciliation from that queue so an idle scheduler does not create a new
terminal database row every minute.

## Decisions

- `.superpowers/` is local tooling state. It remains ignored and its one tracked
  SDD report is removed from Git while remaining on the developer's disk.
- `family_import` remains a queued job because it needs durable progress,
  resumability, and a user-facing status surface.
- Media reconciliation becomes a locked CLI maintenance command. The scheduled
  wrapper invokes it directly, then drains the queue for imports and future
  user-triggered jobs.
- The default schedule changes from one minute to five minutes. A web upload
  still resolves synchronously, so the interval applies only to office-folder
  drops and direct store corrections.
- An unchanged reconciliation is read-only and produces no success log line,
  queue row, audit row, or `last_seen_at` write. `last_seen_at` continues to be
  set when a row is created or changed.
- The polling scan remains preferable to a filesystem watcher. It recovers from
  a stopped machine, works with shared folders, and keeps the same validation
  path for web uploads and office-file changes.

## Queue and reconciliation flow

The current `media:queue-reconcile` producer inserts a `media_reconcile` job on
every scheduler fire after the preceding job reaches `done`. Its active-job check
only avoids overlap. Consequently an idle system accumulates approximately
525,600 terminal queue rows each year. The worker also writes an idle log entry
and updates every unchanged linked `family_media.last_seen_at` value.

Replace that producer with `media:reconcile`. The command owns a non-blocking
lock, constructs `FamilyMediaReconciler`, and runs one scan directly. It returns
an error for a missing root or a failed scan, reports mutations and actionable
invalid-file counts, and stays silent when every file is unchanged. It must not
use `JobQueueModel` or `JobReporter` persistence.

`FamilyMediaReconciler` accepts an optional progress sink. The direct command
uses no sink; the existing queue handler retains `JobReporter` for any already
queued `media_reconcile` work until deployments have drained those rows. The
reconciler still scans the inbox and verifies stored media, but it does not call
`touchSeen()` for an unchanged linked file. Audit entries remain limited to
added, replaced, removed, restored, and invalidated family media.

The shell and PowerShell wrappers invoke the direct command before
`queue:work`. Their installer defaults set `EVERY_MINUTES` to five while retaining
the existing override for deployments that need a different interval. Idle direct
reconciliation produces neither `job_queue` growth nor worker-log noise.

## UI and feature repairs

The import-review problem filter sends `code[]`. Its query object must normalize
both a comma-delimited string and an array of hostile request values into a
trimmed list of non-empty codes. Tests must assert that list contract.

Synchronous web media resolution intentionally moves accepted files from the
inbox to the canonical private store immediately. The media feature tests must
assert the store path and linked registry state, rather than the retired
eventual-worker inbox contract.

The profile media form accepts JPEG aliases and adds preview/clear controls.
Its view tests must assert the expanded accept contract. The preview behavior
moves from inline view JavaScript into the existing dashboard asset and uses
data attributes, so its controls are safe when the partial is rendered more than
once.

The revised import toolbar changes the search controls and multi-select problem
filter. View tests must assert stable element identifiers and decoded accessible
copy rather than incidental substrings. All new inline styles in the import and
media/profile views move to the existing page CSS files, preserving their sizing
and image containment while following the dashboard's Bootstrap 5.2.3 rules.

## Error handling and verification

- The direct command returns failure on an unavailable media root and does not
  alter registry rows in that case.
- A lock contention exits successfully without a duplicate reconciliation run.
- Existing pending or processing queued media jobs remain processable through the
  registered handler during the transition.
- Feature and view tests cover the repaired contracts. Queue tests prove the
  wrapper uses direct reconciliation and an idle run does not persist a queue
  job or update unchanged media metadata.
- Before publication, run PHPUnit without coverage, PHP lint and comment checks,
  route and handler checks, the entry-page Node smoke test, and browser-JavaScript
  ESLint. Verify the changed UI in the running dashboard where the local
environment permits it.

## Out of scope

- Schema changes or migrations.
- A filesystem watcher service.
- Deleting existing terminal queue history.
- Changing the retention policy for user-triggered import jobs.
- Modifying the user's Excel template.
