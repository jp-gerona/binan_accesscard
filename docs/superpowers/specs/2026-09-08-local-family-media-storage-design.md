# Local family media storage design

**Date:** 2026-09-08
**Status:** Approved for planning

## Goal

Store a family head's portrait and signature specimen as local files rather than
MySQL BLOBs. The office must be able to add, replace, or delete these files by
organising a local folder. Media collection is independent of the Excel family
import: a file can arrive before its family record, then link automatically once
the family exists.

Only family heads have media. An access card belongs to the head and may be used
by another household member, but it still identifies the family through its
head.

## Decisions

- Portraits are JPEG files and signatures are transparent PNG files.
- Both media kinds are optional. A missing or invalid file never blocks an Excel
  import or a family save.
- The office manages the local media folder directly. The application does not
  require an upload page for bulk media collection.
- The media folder is scanned automatically at most once per minute. There is no
  page that staff must open to reconcile it and no long-running filesystem
  watcher.
- The current control number is a one-time intake alias, not the permanent
  identity of a media object. Once linked, media belongs to the head's immutable
  `memberID`.
- A retired control number is never assigned to another family. An old card no
  longer scans, but its already-linked media remains linked to its original head.
- Developer, Admin, and Encoder may view media. Viewer and Scanner may not.
- The database stores a private, relative application URL and media metadata, not
  file bytes, an absolute path, or a public web URL.

## Why a folder scan, not a filesystem watcher

A CodeIgniter request process cannot safely stay alive to watch the filesystem.
A separate watcher service would need installation, restart handling, privilege
management, and a recovery scan for events it missed while the laptop was off.
It would still need the same reconciliation logic.

The existing scheduled queue worker already runs once a minute and recovers work
after a restart. The reconciler uses that infrastructure, so a file added while
the machine is off is discovered on the next scheduled run. It lists file
metadata on every scan and opens image content only when a file is new or has
changed, which keeps the regular scan inexpensive even for a large folder.

## Storage layout and file naming

`MEDIA_ROOT` is a required `.env` path to a directory outside `public/`,
`writable/`, and the repository. The worker service account needs read and write
access to it. Apache and direct HTTP requests must not be able to serve it.

The root is flat in the first release. Each filename contains the current intake
control number, the media kind, and its required extension:

```
019186.photo.jpg
019186.signature.png
```

The control portion is the decimal control number padded to **at least six
digits**. Values below one million must therefore have six digits, so
`19186.photo.jpg` is invalid and `019186.photo.jpg` is correct. A future number
with more than six digits is not truncated or rejected merely for its width.
Filenames are lowercase ASCII and must match this pattern:

```
<zero-padded-control>.photo.jpg
<zero-padded-control>.signature.png
```

The leading zeroes are a folder convention only. The reconciler converts the
numeric portion to the integer `qr_control.control_no` value before matching.

The file at `MEDIA_ROOT/019186.photo.jpg` remains the source of truth. The
application does not move it into a `memberID` directory, because that would
make direct folder deletion and replacement unreliable for staff who know the
control number but not `memberID`. Once a file is linked, the registry preserves
its association with the head even if the card is later replaced.

## Data model

The current dump has no image or signature fields. Add a `family_media` table
through a numbered SQL patch, then fold it into the next authoritative dump. Do
not add a CodeIgniter migration.

One row represents one source file and has these fields:

| Field | Purpose |
| --- | --- |
| `mediaID` | Surrogate primary key. |
| `headID` | Nullable until a filename resolves. References the family head once known. |
| `kind` | `photo` or `signature`. |
| `source_control_no` | Parsed control-number alias from the filename. It is not a foreign key because a card can later be retired. |
| `source_filename` | Exact relative filename below `MEDIA_ROOT`; unique. |
| `media_url` | Relative private route, `/records/{headID}/media/{kind}`, set only after resolution. |
| `content_sha256` | Detects a replacement even when timestamp and size coincide. |
| `byte_size`, `modified_at` | Allows cheap unchanged-file detection before content is read. |
| `state` | `pending`, `linked`, `invalid`, or `missing`. |
| `last_seen_at`, `dt_created`, `dt_updated` | Supports deletion detection and operations follow-up. |

A unique key on (`headID`, `kind`) enforces one current portrait and one current
signature per head. A unique key on `source_filename` makes case and duplicate
handling deterministic. `headID` must point only to a row where `headID` equals
`memberID`; the model validates that invariant because MySQL cannot express it
with a normal foreign key.

`media_url` is deliberately relative. It is stable across laptop, LAN, and
production hostnames, while the route continues to enforce authentication. The
filesystem path is never returned to a browser.

## Reconciliation flow

A new `media_reconcile` job type uses the existing `job_queue` and
`php spark queue:work` engine. It is separate from the `family_import` handler,
but shares the same queue locking, restart recovery, worker scripts, and scheduled
drain.

Before each scheduled drain, a small CLI producer enqueues a reconciliation job
only when no `media_reconcile` job is pending or processing. This prevents a
slow scan from stacking minute-by-minute copies of itself. The handler holds its
own named lock as defence against a manual CLI invocation.

For each valid filename in `MEDIA_ROOT`, the handler:

1. Reads metadata and skips unchanged linked files without opening their content.
2. Validates the extension, actual MIME type, readability, size, and image
   dimensions. Portraits accept JPEG only, signatures accept PNG only.
3. Finds an existing `family_media` row by exact source filename first. That
   preserves a resolved association after its control number is retired.
4. If no row exists, resolves `source_control_no` through the current
   `qr_control` mapping and accepts the result only when it is a family head.
5. Creates or updates the media row. A newly resolved file receives its private
   route URL based on the head's `memberID`.
6. Marks previously seen rows missing when their source file no longer exists.
   Missing media is not served.

A file that arrives before its family row has no matching control mapping. The
handler stores it as `pending` and leaves the file untouched. The next scan after
the Excel import resolves it. A malformed, unreadable, unsupported, or
case-conflicting file is stored as `invalid`, left untouched, and never served.
This lets staff correct the folder without the application deleting evidence or
silently changing files.

When a linked file is added, replaced, or removed, the handler writes one family
audit row through `AuditTrailsModel` under the system actor. Pending and invalid
files have no family to audit. The audit detail names the kind and source
filename, never the file contents or signature data.

## Family entry and editing

The Add Family and Edit Family interfaces include optional portrait and signature
file inputs for Encoder, Admin, and Developer. A family record is saved first.
After the head and its current control number are known, the upload is validated,
written atomically into `MEDIA_ROOT` under its canonical filename, and processed
by the same reconciliation path as a file copied directly by the office.

If media validation or filesystem writing fails, the family record remains saved
and the interface gives a specific media error. The worker will not serve a
partial file. Replacing a file is atomic: write a temporary sibling, validate it,
then rename it into place. The next reconciliation job updates its fingerprint
and audit trail.

## Private delivery

A role-guarded route serves each kind of media for a family head. It resolves the
linked `family_media` row, confirms the requested kind, verifies the file remains
inside `MEDIA_ROOT`, and streams it with its declared MIME type. It returns 404
for absent or missing media and 403 for roles without access. It never accepts a
path from the request and it never maps a request directly onto a local filename.

Family profile and access-card rendering use the stored relative URL. A card
layout may leave a media area blank when no portrait or signature is linked. The
scanner kiosk does not receive these URLs.

## Future card replacement

This design intentionally works before the replacement-card architecture. It
uses today's control numbers only to discover a new file. After the first link,
`headID` is authoritative for the media association.

A later card system can retire a control number and assign a new one without
renaming or moving existing media. It must preserve the rule that retired numbers
are never reused. A later storage migration may move files to a
`heads/{memberID}/` layout, update `source_filename`, and retain the same
`media_url` and head association. That migration is not part of this work.

## Error handling and operations

- Missing files are normal. Profiles simply show no item for that kind.
- Pending files are normal before import. They remain in place until a matching
  family exists.
- Invalid files are visible in the job result and worker log with a filename and
  correction reason.
- A missing `MEDIA_ROOT`, unreadable directory, database failure, or lock failure
  fails the job without changing existing linked rows. The next scheduled run
  retries it.
- The scheduler and worker service account must be installed and checked using
  the existing queue-worker operations guide. A stopped worker delays import and
  media reconciliation equally.
- Backups must include both MySQL and `MEDIA_ROOT`. Restoring only one breaks
  associations, so the recovery guide must require a matched database and media
  backup set.

## Testing

Unit and integration coverage must prove:

- Filename parsing, minimum six-digit padding, wider values, wrong kind, wrong
  extension, and case conflicts.
- JPEG and PNG content validation independent of filename extension.
- Pending media resolves after the matching family and control mapping appear.
- A linked file remains linked after its control mapping is retired.
- Replacing, deleting, or corrupting a source file changes availability correctly
  and cannot serve a stale file.
- One head cannot have more than one linked item of the same kind.
- A non-head control mapping never links media.
- Uploaded files and direct folder files reach the same reconciliation code.
- Authorized roles can stream media, while Viewer and Scanner are denied and path
  traversal cannot reach any file.
- Each resolved add, replacement, and removal writes the required audit row.
- Queue locking prevents overlapping reconciliation jobs.

Run the PHPUnit suite, comment and sniff lint, route checks, and a manual
scheduled-worker smoke test against a scratch media root before merge.

## Out of scope

- MySQL BLOB storage.
- Media for non-head members.
- Public or directly addressable filesystem URLs.
- A long-running filesystem watcher.
- Blocking family import or family entry because media is absent.
- Card-control-number replacement, token issuance, card revocation, or historic
  control lookup.
- Automatic image cropping, signature cleanup, or OCR.
