# Records

A record is a family: a head, the members under them, their address and barangay,
and the sectors and services each member belongs to. Chapter 02 covers how that
is stored; this chapter covers how it gets in and how it is worked with.

Records enter the system three ways: the entry form, the Excel import (chapter
12), and edits to a family that already exists. All three converge on the same
write path.

## The records list

`records` is the page most staff spend their time on. One row per family, not per
person: the head's name, barangay, member count, and the row actions.

The list is a server-side DataTable. The browser asks for a page of rows and
`app/Controllers/Families/FamilyDataTableController.php` answers, delegating the
shaping to `app/Libraries/FamilyDataTablePresenter.php`. That split is the
architecture rule from chapter 01 in miniature: the controller resolves the
session role and hands it over, and the presenter never reads the request or the
session itself.

Server-side paging is not optional here. The member table runs to tens of
thousands of rows in a real deployment, and there is a seeder that will make you
50,000 of them to prove it.

Two things about the presenter are contracts rather than implementation details,
so changing them breaks the page: the HTML it emits, and the shape of its
`payload()` envelope. `public/assets/js/dashboard/family-datatable.js` is written
against both. If you are adding a column, you are editing both sides.

Role affects the output. A Viewer's rows carry no edit actions. That is a
conditional inside the presenter, not a second presenter.

## The entry form

`records/entry` is the manual path, and it is not one long form. The page is a
vertical spine: three numbered steps down the left, each expanding into its own
section.

**Step 1, Control Number.** The family's control number comes first, and it gates
the rest of the page. The field checks availability against `records/qr-check`
as you type, so a number already issued to another family is caught before
anything else is filled in.

**Step 2, Head of Family.** The head's details.

**Step 3, Members.** Everyone else in the family.

Steps 2 and 3 start locked and unlock once the control number is settled. The
ordering is deliberate: the control number is the family's identity for the rest
of its life, and discovering a collision after typing twelve members is a bad
afternoon.

The stepper markup on this page is written out inline rather than through
`app/Views/components/stepper.php`, because the entry spine holds live form
content inside each step while the shared component is presentational only. The
component is used by the import pages, where the steps are separate pages.

`app/Views/Family/_fields.php` holds the field set shared between the entry form
and the profile editor, so a field added for one appears in the other.

## Writing a family

Every family write goes through `app/Libraries/FamilyRecordWriter.php`. Both the
entry form and the background import job call it, which is the point: one write
path means one set of rules about ordering, validation, and audit.

It persists the head, the members, the sector assignments, the service
assignments, and the `FAMILY_CREATED` audit row.

One detail matters if you ever call it: **the writer does not own the
transaction, the caller does.** Both callers wrap one family per transaction. The
import worker does this deliberately, so a large import never holds a single
enormous transaction and one bad family is isolated from the rest. On failure the
writer throws `FamilyRecordWriteException` and the caller rolls back and reports.

## The family profile

`records/{id}` is the family's page, and it is read-only: label and value pairs,
no form and nothing to submit, so a reader cannot edit by accident whatever their
role. Editing is its own page, `records/{id}/edit`, rendering the same
`Family/_fields` partial the entry form uses. The profile links to it only for
the roles the manifest lets through, and the manifest keeps read-only roles off
the edit route entirely. Archiving and restoring are row actions on the records
list (`app/Views/Family/row-actions.php`), posting to `records/{id}/archive` and
`records/{id}/restore` under the `records-update` manifest key.

Archiving sets `dt_deleted`. Nothing is deleted outright, so an archived family
can be restored with its history intact, and its audit trail keeps resolving.

`app/Controllers/Families/FamilyController.php` owns all of it: `createFamily`
and `store` for creation, `profile` and `edit` for viewing and editing, `update`,
`archive`, and `restore`.

## Family portraits and signatures

The private folder configured as `familymediasettings.root`, called `MEDIA_ROOT`
in the commands in this handbook, has two zones:

```text
MEDIA_ROOT/
  inbox/                      <- the office drop zone
    019186.photo.jpg
    019186.signature.png
  store/48/4821/              <- the system-owned archive, one folder per family
    photo.jpg
    signature.png
```

The office works only with the inbox. The direct-folder convention is exact:

```text
019186.photo.jpg
019186.signature.png
```

The six digits are the zero-padded control number. A portrait is a JPEG and a
signature is a PNG. Copy files into the inbox with their final names, for
example:

```bash
cp /secure-intake/019186.photo.jpg "$MEDIA_ROOT/inbox/019186.photo.jpg"
cp /secure-intake/019186.signature.png "$MEDIA_ROOT/inbox/019186.signature.png"
```

Files may arrive before or after the Excel import creates the family and its
control number. If `019186.photo.jpg` arrives first, the next shared-worker scan
records it as pending. It remains pending and is not deleted while no imported
head resolves control number `19186`. Once that head exists, the next scan links
it, moves it out of the inbox into the family's permanent store folder, and an
Encoder, Admin, or Developer can see it on the family profile. The move is a
rename, not a copy: exactly one copy of the file exists at any moment, and the
inbox stays small.

The store is organized by the family's permanent member ID, which is the number
in the family profile's URL (`records/4821` is the folder `store/48/4821/`),
sharded by its first digits so no directory ever holds a six-figure entry count.
A card replacement never moves it: the QR number is read only at intake, and a
retired number never resolves again, so a folder belongs to one family for the
life of the record.

The five-minute scheduled worker performs this reconciliation directly,
without adding a queue row. There is no page to press and no filesystem watcher to install.
The worker scans the inbox, records only valid canonical image files, and leaves
invalid drops in the inbox for staff to correct. A file whose name or format is
wrong simply stays where it was dropped, nothing appears on any profile, and the
worker log reports the invalid-file count. Correct the filename, format,
dimensions, or size in place and let the next scan inspect it again.

Replace a portrait by dropping the same QR-named file into the inbox again; the
next scan overwrites the store copy, updates the registry, and writes a media
replacement row on the audit page. Edits made directly inside a family's store
folder are picked up the same way: the next scan re-validates the changed bytes
before serving them, and bytes that no longer pass validation stop being served
until corrected. Delete a store file directly; the next scan marks it missing,
removes its availability from the profile, and writes a media removal row. Do
not expect the profile to retain a deleted source file.

Media is delivered only through the protected family route, not as a public file
URL. Viewer accounts receive a 404 for a direct media URL and their profile shows
no media panel. Chapter 05 explains the worker schedule, and chapter 06 covers
folder permissions and matched backups.

## The Data Completeness queue

Records are not always complete on the first pass. An Excel import (chapter 12)
saves rows even when optional-but-important fields are blank: the family lands on
the Data Completeness queue instead of being rejected. The queue lives at
`records/completeness` and lists every family with at least one blank field the
city still needs to collect.

The queue is part of the records workflow, not a separate module. Staff open a
family from the queue, fill the missing fields through the same family form used
for edits, and the family drops off the queue once the last blank is gone. The
download link at `records/completeness/download` produces an `.xlsx` checklist
for barangay follow-up. Chapter 12 covers which import warnings send a family
here and how the importer reports them.

## Every mutation writes an audit row

Creating, updating, archiving, and restoring a family each write to
`audit_trails`. This is not something to remember to do; it is built into the
write path so that forgetting is not an option available to you.

Chapter 16 covers what gets recorded and why it matters more here than the phrase
"audit trail" usually implies.
