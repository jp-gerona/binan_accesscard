# The Excel import

Most families do not arrive one at a time through the entry form. They arrive as
a spreadsheet filled in during a barangay survey, hundreds or thousands of rows,
typed by people who are not looking at this application while they type.

That is the whole design problem of this feature. The import is not a data
transfer; it is a proofreading exercise with a data transfer at the end.

## The flow

1. The encoder downloads the template from the records page and fills it in.
2. They upload the filled `.xlsx`.
3. The system reads and checks the file. **Nothing is saved yet.**
4. The review screen lists every problem it found, naming the exact Excel cell.
5. The encoder fixes the **blocking** problems **in the spreadsheet** and uploads again. Warnings are shown but do not need to be fixed; they describe what the import will do.
6. When no blocking issues remain, they press Confirm import.

Step 5 is the part that gets questioned, so it is worth stating the reasoning:
**the review screen is read-only on purpose.** The spreadsheet is the source of
truth. Fixing an error in the browser would leave the mistake in the file, and
the next person to open that file would import it all over again. Sending the
encoder back to the spreadsheet fixes the error at its origin.

### Where the work happens

Reading and validating a large workbook does not fit in a web request. The upload
enqueues a `family_import` job and the background worker does the parsing, which
is why the upload page polls for status rather than blocking. Chapter 05 covers
the worker, and an import that never leaves "queued" is a worker problem, not an
import problem.

Validated results go into a staging store (`app/Libraries/ImportStagingStore.php`,
backed by `writable/import-staging/`), which is what the review screen reads.
Nothing touches the `member` table until Confirm.

On confirm, each family is written through `FamilyRecordWriter`, the same path the
manual entry form uses. One family per transaction, deliberately: a large import
never holds one enormous transaction, and a single bad family is isolated rather
than taking the whole file down with it.

## The review screen

Six tiles across the top:

| Tile | What it counts |
|---|---|
| Family groups | how many families the file contains |
| Total members | every person in the file |
| Ready to import | families that are correct and will be saved |
| Already in system | families that already exist and will be skipped |
| Issues to fix | blocking problems; the import will not run |
| Warnings | things to read, which do not stop the import |

Below them the problems, grouped by type, with a toggle to group by row instead.
Each problem names its exact Excel cell. Clicking a cell reference copies it, so
the encoder can press Ctrl+G in Excel and paste to jump straight there. That
detail is not obvious from the code and it is most of what makes the screen
usable on a thousand-row file.

Last comes the **Ready to import** list: every family that is correct, with its
head, member count, and address. The rest of the screen is nothing but problems,
so this is the half that lets someone confirm the good data really is good.

A family appears in that list only if the import would genuinely **create** it.
Families that are blocked, already on file, or being added to an existing family
are deliberately left out, because listing them would be a promise the import does
not keep. A family with warnings only does appear, marked "imports as typed", so
nothing is glossed over.

## The two severities

**Issue to fix (blocking).** Stops the import. Confirm stays disabled until it is
gone. Either the data is wrong, or the database would physically refuse it.

**Warning (informational).** Does not stop the import. It says what the import is
about to do, or points at something that looks like a typo. Some warnings mean
rows will be **skipped and not saved**, which is why they are worth reading
rather than clicking past. A warning that says a field **imports blank** means
the record is saved with that field empty and the family appears on the Data
Completeness report until the data is collected.

## Blocking issues

### Whole-file failures

| Code | Meaning |
|---|---|
| `FILE` | The file could not be read at all. Catches: not an `.xlsx`, wrong sheet, missing column headers, corrupt file. |
| `EMPTY` | The file was read but has no family rows under the header. |

Neither can coexist with any other error, because there is nothing left to
review.

### QR number problems

The QR number is the family's identity, so most of the blocking rules are here.

| Code | Meaning |
|---|---|
| `QR-11` | Merged QR cells in the data area. Catches a worker merging one QR across a family's rows instead of repeating it, which hides the QR from every row but the first. The template's decorative banner is ignored; only merges reaching data rows are flagged. |
| `QR-01` | The QR cell is blank. Every person needs their family's QR on their own row. |
| `QR-FORMAT` | Not a plain whole number. Catches letters, decimals (`5880.0`), commas (`6,001`), negatives, scientific notation, stray symbols. |
| `QR-05` | The QR is zero, which is not a valid card number. |
| `QR-07` | Above 2,147,483,647, the database column's ceiling. Catches a slipped keyboard producing a 12-digit number. |
| `QR-08` | The cell holds an Excel error value such as `#REF!` or `#N/A`. Catches a broken formula or a bad paste. |
| `QR-12` | The cell holds a formula (`=A4`) instead of a typed number, so the value can change out from under you. |
| `QR-TAKEN` | That QR is already used by a **different** family in the system. |

`QR-TAKEN` is the one that earns its severity. A single mistyped digit, 6001 for
6091, files a brand-new family onto a stranger's card number. It fires in two
forms: the QR is on file under a completely different person, or the same name
but the birthday does not match what is stored.

It blocks rather than warns because it genuinely cannot be saved. A QR belongs to
exactly one family, since it is the primary key, so the import can neither skip it
(this head is new) nor insert it (the number is taken). Left as a warning it would
fail halfway through the import instead of being caught up front. The fix is to
correct the QR, or give the new family its own unused one.

### Family structure

| Code | Meaning |
|---|---|
| `HEAD-NONE` | A QR group with no row marked Relationship = Head. The message names who is most likely the head, using the address: only the head fills in Address and Barangay, so the person with an address almost always is one. |
| `HEAD-MULTI` | Two or more Head rows under one QR. Catches a copied block, or two households sharing a QR by mistake. |
| `FP-ADDR` | Rows sharing one QR carry different addresses or barangays. One QR is one household. Catches a copy-pasted block or a row shift that dropped a stranger's household onto this QR. |

### Field-level

| Code | Meaning |
|---|---|
| `REQUIRED` | First name or last name is blank. These are the only two fields that block; every other blank imports as NULL with a warning instead. |
| `LENGTH` | The value would not fit its database column and would be cut off silently. Limits: first and last name 100, middle name 50, civil status 100, contact number 20, religion 100. |

## Warnings

### Already-known families

| Code | Meaning |
|---|---|
| `DUP-EXISTS` | Same QR and same head (name and birthday) as a family already on file. A genuine re-upload: the family is skipped, not imported twice. This checks the person, not just the QR, so it only says "already in the system" when the head really is the same human being. |
| `DUP-DIFF` | Same family as above, but the file disagrees with what is stored: a new phone number, a corrected middle name. |
| `DUP-DB` | This person is already on file under a **different** QR. |
| `ADD-MEMBER` | A group with no head whose QR already belongs to a family, meaning members are being added to that family. |

Three of those deserve their reasoning spelled out, because each exists to catch a
failure that used to be invisible.

`DUP-DIFF` catches someone editing the spreadsheet expecting the import to update
the record. It does not. Families already on file are skipped, so those edits
would be silently thrown away. The message lists exactly which fields differ and
both values. The fix is to edit the record in the application instead.

`DUP-DB` catches the silent skip. A head re-entered under a brand-new QR used to
look perfectly clean in the review, and then the import quietly dropped the whole
family, head and members and everything, because the head already existed, and
nobody was told. If the person is a head, the whole group will not be saved, so
check the QR. If the person is a member, they will be added as a second record;
delete the row if it really is the same person.

`ADD-MEMBER` covers the forgotten-member case: a worker finishes a family, then
remembers a member the next day and adds the row in a later batch. Those people
are added automatically. To skip one, delete their row from the file. If the
person is already in that family they are not added twice; you get `DUP-DB`
instead.

### Data that looks wrong but imports anyway

| Code | Meaning |
|---|---|
| `DUP-PERSON` | Two rows in your file look like the same person: same first, middle, last, suffix and birthday, and the same household address. Two real people can share a name, but never a name, a birthday, and an address. Imports anyway, on purpose. |
| `INCOMPLETE` | A completeness field is blank. The row is saved with that field empty and the family appears on the Data Completeness report until the data is collected. Covers birthday, sex, civil status, education, job, monthly income, address, barangay, and relationship. |
| `BDAY` | The birthday is not a real date. The original text is quoted and the field imports blank; the family appears on the Data Completeness report until a valid date is collected. Format is MM-DD-YYYY. |
| `BDAY-FUTURE` | The birthday is a real date but in the future. The original text is quoted and the field imports blank; the family appears on the Data Completeness report. |
| `BDAY-RANGE` | A valid date, but over 150 years ago. 150 is well past the oldest human on record, around 122, so it cannot flag a real person. Imports as typed. |
| `SEX` | Not Male or Female. The original text is quoted and the field imports blank; the family appears on the Data Completeness report. |
| `INCOME` | Not a bracket label from the dropdown, and not a number. The original text is quoted and the field imports blank; the family appears on the Data Completeness report. |
| `BRGY` | Not one of the official Biñan barangays. The dropdown has no "Other" option, so anything off-list is suspect. Matching is tolerant of `Sto.` for `Santo`, `n` for `ñ`, and a parenthesised alias. Imports as typed; a blank barangay is reported as `INCOMPLETE` instead. |
| `CONTACT` | Does not start with 09, or is not 11 digits. Imports as typed. |
| `SUFFIX` | The suffix was mapped to the matching dropdown value: "Junior" to `Jr`, "the 3rd" to `III`. If it matches nothing it is left blank. The database accepts only `Jr`, `Sr`, `I`, `II`, `III`, `IV`, `V`. |
| `SECTOR` | A sector code is not on the Reference sheet. The token is filed under Other Sectors instead of blocking. |
| `SERVICE` | A service code is not on the Reference sheet. The token is skipped; the row's other services still import. |
| `QR-CONTIG` | One family's rows are not next to each other because another family's rows sit between them. Catches a sort or paste that scattered a family. It still imports correctly; this only asks you to check the grouping. |

## Data Completeness

Blank completeness fields do not block an import, but they do leave work behind.
The **Data Completeness** page, at `records/completeness`, lists every family
that still has a blank in any completeness field after the import runs. Staff
with the `records-completeness` key - Developer, Admin, and Encoder - can filter
the list by barangay or missing field, open the family form to fill the gap, and
download the current queue as an `.xlsx` checklist from `records/completeness/download`.

## When is a repeated QR a problem?

This question comes up constantly, so here it is directly.

**Not a problem, this is the design.** A whole family shares one QR. The head and
every member carry the same number on their row. That is how the importer groups
them into a family.

```
QR     Relationship   Name
6001   Head           Juan Cruz
6001   Spouse         Maria Cruz
6001   Child          Jose Cruz          <- correct
```

**A problem when the QR collides with the database:**

| Your file's QR is already in the system as | Result |
|---|---|
| the same head, same details | `DUP-EXISTS` warning. Re-upload, skipped. |
| the same head, but your details differ | `DUP-EXISTS` and `DUP-DIFF` warnings. Skipped, and your edits are not saved. |
| a different person | `QR-TAKEN`, blocking. A mistyped QR. |
| the same name, different birthday | `QR-TAKEN`, blocking. Would fail on import. |

Only the cases that genuinely cannot be written are blocked. Re-uploads stay
warnings and import fine.

## The demo file

`excel/family-import-DEMO-validations.xlsx` trips every validation the importer
has: 30 rows, 13 family groups, producing 19 issues and 12 warnings. Nothing is
written unless you confirm, and you cannot confirm while issues remain, so it is
safe to upload against a real database.

It is keyed to families that must exist in the database, QR 1 to 5 in the current
dump, which is what makes the already-in-the-system checks fire. If those families
are ever removed, regenerate it with `php tools/make-import-demo.php`.

| Sheet row | Code | What it demonstrates |
|---|---|---|
| 3 | `DUP-EXISTS` | QR 1 re-uploaded exactly as stored. Skipped, and deliberately raises no `DUP-DIFF`, proving a clean re-upload does not cry wolf. |
| 4 | `DUP-EXISTS`, `DUP-DIFF` | QR 4, same head, new phone number. Skipped, so the edit is not saved. |
| 5 | `QR-TAKEN` | QR 2 belongs to Ronald Andrada; this row is Carmela Reyes. |
| 6 | `QR-TAKEN` | QR 3, right name, wrong birthday. |
| 7 | `ADD-MEMBER` | No head, and QR 5 already exists: a forgotten member joining family 5. |
| 8 | `DUP-DB` | Ronald Andrada re-entered under a brand-new QR. The QR looks fine, but the whole family would be silently skipped. |
| 10-11 | `HEAD-NONE` | Nobody marked Head; the message names who probably is. |
| 12-13 | `HEAD-MULTI` | Two Head rows under one QR. |
| 14-15 | `FP-ADDR` | One QR, two different households. |
| 16 | `REQUIRED`, `BDAY`, `SEX`, `INCOME`, `SERVICE` | One row with five separate field errors. |
| 17 | `LENGTH` | A first name past the 100-character limit. |
| 18 | `QR-01` | Blank QR. |
| 19 | `QR-FORMAT` | `ABC123`. |
| 20 | `QR-05` | Zero. |
| 21 | `QR-07` | `9999999999`, above the ceiling. |
| 22 | `QR-08` | `#REF!`, an Excel error cell. |
| 23 | `QR-12` | `=A4`, a formula instead of a number. |
| 24 | `BRGY`, `CONTACT`, `SUFFIX`, `BDAY-RANGE` | One row with four warnings. |
| 26-27 | `DUP-PERSON` | The same child typed twice in one family. |
| 28, 30 | `QR-CONTIG` | Family 9000009's rows split apart by another family's row. |
| 31-32 | `QR-11`, `QR-01` | Two rows with merged QR cells, so row 32 has no QR of its own. |

`FILE` and `EMPTY` are not demonstrated: both are whole-file failures and cannot
coexist with other errors. To see `FILE`, rename a `.txt` to `.xlsx` and upload
it.

## The code

| Class | Responsibility |
|---|---|
| `app/Controllers/Families/FamilyImportController.php` | template download, upload, status polling, review page, review rows, confirm, cancel, per-cell restage |
| `app/Libraries/FamilyExcelTemplate.php` | builds the template workbook, its dropdowns, and its Reference sheet |
| `app/Libraries/FamilyExcelImporter.php` | reads and validates the workbook; this is where the codes above are raised |
| `app/Libraries/ImportStagingStore.php` | holds validated results between upload and confirm |
| `app/Libraries/ImportReviewQuery.php` | queries the staged data for the review screen |
| `app/Libraries/ImportReviewPresenter.php` | shapes it; its `people` list drives the review table |
| `app/Libraries/ImportReviewChangeLog.php` | tracks per-cell restaging |
| `app/Libraries/ImportLookupCache.php` | caches barangay, sector, and service lookups so validation does not re-query per row |
| `app/Jobs/FamilyImportJob.php` | the queued job that runs the importer |

## Diagnostic tools

Six scripts under `tools/`, all runnable from the repository root. None are part
of the application; they exist for the times an import misbehaves and you want an
answer faster than the browser can give you one.

**`php tools/validate-import.php <file.xlsx>`** runs a workbook through the real
importer and validator and prints the outcome: row, family and member counts,
blocking and warning totals, and the first few of each. The quickest way to
confirm a generated test file parses clean, or trips exactly the errors you
seeded, without going through the upload and the worker. Needs the database up,
because the importer checks existing heads, sectors, services, and barangays.

**`php tools/diag-import-fail.php <file.xlsx> [qr...]`** answers "why did that
family fail?". The import job reports a member insert failure as the generic "One
family member could not be saved."; this reproduces the family and prints the real
database rejection. Everything runs inside a rolled-back transaction, so nothing
is persisted. Extra arguments are the QR numbers to probe.

**`php tools/crosscheck-import.php <fileA.xlsx> <fileB.xlsx>`** predicts, without
writing anything, whether importing two workbooks in sequence would trip the
person-level duplicate codes. Reports QR overlap, which would cause `QR-TAKEN` or
`DUP-DB`, and person-identity collisions on last name, first name and birthday,
matching `FamilyExcelImporter::identityKey`.

**`php tools/make-import-demo.php`** regenerates
`family-import-DEMO-validations.xlsx`, the file described above. Built on the real
`FamilyExcelTemplate`, so the headers, dropdowns and Reference sheet are genuine.
Its database-aware codes are keyed to QR 1 to 5 in the dump; if those families
change, update the script's rows and re-run.

**`php tools/make-test-files.php`** generates the test workbooks: two clean
100-person files that do not overlap, one file exercising every blocking and
warning code with complete head data, a 10,000-person clean file for bulk load
testing, and a 10,000-person file with about 1,000 seeded field errors. Every
dropdown column uses the sheet's actual option strings, so a tester can open any
cell and find the value already matching its dropdown.

**`php tools/fix-future-birthdays.php <in.xlsx> <out.xlsx>`** rewrites
future-dated birthdays so a file imports cleanly, leaving everything else
byte-for-byte identical. It exists because `MemberModel`'s `not_future_date` rule
rejects such a birthday on write, and one bad member rolls back its whole family,
so a file full of them silently loses hundreds of families. It is fast because it
does not load the workbook: an `.xlsx` is a ZIP of XML, birthdays are the only
entries in `xl/sharedStrings.xml` formatted MM-DD-YYYY, so it patches that one XML
member in place and leaves the rest of the archive untouched.
