# Import Strictness and Data Completeness

Design for realigning the family import's validation with what the database
and the office's real data can actually support, and for a Data Completeness
report that turns "imported with blanks" into a chasable work queue.

Branch: `feat/import-strictness-completeness`, cut from `main`.

Reference workbook for every measured claim in this document:
`/Users/JP/Documents/ACCESS CARD/Cluster1_Import_Final_2026.xlsx` (33,223 real
person rows, 8,164 canonical families, 1,775 blank template stubs). Baseline
run of `php tools/validate-import.php` against it, on the current importer:

```
Counts: families=7177 members=19093 blocking=53648 warnings=1503 (data rows=33223)
```

## Why

The importer was built stricter than the database it feeds. The `member`
table and `MemberModel` rules are `permit_empty` on birthday, sex, civil
status, education, job, and salary, but the import template demands all of
them for every person. The real Cluster1 file proves encoders cannot meet
that bar, and the numbers are not edge cases:

| Field blank on real rows | Rows | Share |
|---|---|---|
| MonthlyIncome | 18,577 | 55.9% |
| Job | 12,722 | 38.3% |
| Education | 4,619 | 13.9% |
| CivilStatus | 2,290 | 6.9% |
| Birthday | 263 + 9,477 malformed | ~29% |
| Sex | 203 | 0.6% |

Of 9,125 Head rows: 1,798 have no income, 1,119 no job, 1,159 no address,
391 no civil status, 349 no education, 377 no barangay. The result is 53,648
blocking errors that no amount of spreadsheet fixing can resolve, because the
data was never collected. Strictness on uncollected data does not create the
data; it only defers the import and makes the spreadsheet, not the system,
the holding pen.

Two confirmed false positives compound this:

- **QR-CONTIG misfires on blank rows.** Contiguity is computed from raw row
  numbers, so a blank template row between two rows of one family reads as a
  break. 1,503 warnings fired; a large share is this artifact, the rest is
  the 188 formerly leading-zero-split families and the ~970 duplicate
  enrollments, which are genuinely scattered.
- **The income "mismatch" is not a mismatch.** Bracket labels already match
  case-insensitively; the flood is `INCOME is required` on every blank, plus
  ~790 free-text amounts ("P3000", "10, 000", "n/a") the parser cannot read.

Separately, a future-dated birthday gets a `BDAY-FUTURE` warning and imports
with a NULL birthday, so the import handles it without an external cleanup
pre-step.

## What this is not

- No schema redesign or constraint tightening. "Required for the office's
  workflow" is a policy, not an invariant; policies live in the application
  layer (import warnings, completeness report) where they can evolve without
  re-cutting the dump. The one database change is a data row (the IW sector),
  not a schema change.
- No duplicate-family merge or dedupe feature. The ~970 duplicate enrollments
  are manual spreadsheet work (see "Manual spreadsheet work that remains").
  A merge tool would be its own design round.
- Playwright stays out of `package.json` and CI. It is an agent-driven
  confirmation layer only (see "Testing").
- No change to the stepper flow, the inline per-cell restage machinery, or
  the "fix it in the spreadsheet" doctrine. Warnings still do not block
  Confirm; blocking issues still do.
- `BDAY-RANGE` for dates over 150 years past keeps its current behavior
  (warning, imports as typed).
- A blank Relationship still stores as `MEMBER`; the completeness report
  therefore cannot see it post-import. Documented limitation, accepted.

## The blocking contract

One rule replaces the per-field strictness:

> A row blocks only when the system cannot store it or the family structure
> is ambiguous. Missing or unreadable data never blocks; it warns, imports
> blank, and lists the family on the Data Completeness report.

Identity and structure stay blocking because guessing is dangerous or the
database physically refuses: QR problems (blank, letters, junk, range, error
cells, formulas, merges, taken), `HEAD-NONE`, `HEAD-MULTI`, `FP-ADDR`, blank
first/last name (NOT NULL columns), `LENGTH` (silent truncation), `FILE`,
`EMPTY`. Blank QR stays blocking for the same reason: a person with no QR
cannot be placed in a family.

Everything else demotes to a warning that imports blank. Code-by-code:

| Code | Old behavior | New behavior |
|---|---|---|
| `REQUIRED` | blocking, all required fields | blocking, first/last name only |
| `INCOMPLETE` (new) | - | warning: blank relationship (imports as MEMBER), birthday, sex, civil status, education, job, monthly income, head address, head barangay. Imports blank; family listed on Data Completeness. |
| `INCOME` | blocking | warning: value present but unmapable; imports blank, original text quoted in the message. |
| `BDAY` | blocking | warning: value present but unparseable; imports blank, original text quoted. |
| `BDAY-FUTURE` (future dates) | warning, imports as typed (write-time trap) | warning, imports blank birthday. |
| `SEX` | blocking | warning: value not Male/Female after case folding; imports blank. |
| `SERVICE` | blocking | warning: unknown service token after aliasing; that token is skipped, the rest import. |
| Sector fallback | silent `OTHER` catch-all | warning when an *unrecognized* token is filed under the `OTHER` catch-all, so the fallback is visible. A deliberately typed `OTHER` stays silent. |
| `QR-CONTIG` | warning, raw row-number span | warning, populated-rows logic (below). |

The `INCOMPLETE` message names the field and states the consequence: "imports
with no income recorded; the family is listed on the Data Completeness
report." The review screen's warning tiles and code filter already handle
warning-severity buckets, so no new review plumbing is needed for them.

## Value parsing

### Income

1. Bracket labels, case-insensitive, map to the stored bracket value
   (existing behavior, verified correct against the real file).
2. Currency prefixes stripped case-insensitively (`P`, `PHP`, `₱`, `$`),
   then commas and stray spaces: "P3000" → 3000, "10, 000" → 10000,
   "PHP15,000" → 15000. If the remainder is numeric, it is stored.
3. Placeholders (`n/a`, `N/A`, `none`, `None`, `-`) → blank income with an
   `INCOMPLETE` warning. Unknown is stored as NULL, never as "No regular
   income": that label is a positive claim only a human makes. NULL versus 0
   stays distinguishable in the database.
4. Anything else ("MINIMUM WAGE", "6K", "SSS Pension - 14, 0000") → `INCOME`
   warning, blank, original text quoted.

### Birthday

Normalize before parsing: collapse inner whitespace, double dashes to one
("10-12--2019"), `=` to `-` ("03-02=2020"), `/` to `-` keeping the template's
M-D-Y order ("9/23/1989"). The result must parse as a full, real MM-DD-YYYY
date. Truncated ("03-07"), year-only ("2008"), and 5-digit years do not
parse: `BDAY` warning, blank birthday, original quoted. Future dates: blank
birthday with the `BDAY-FUTURE` warning.

### Services

Per token, in order: (1) if the whole trimmed token, uppercased with inner
spaces removed, is a valid code or a curated alias, use it ("EDA 8" → EDA8);
(2) otherwise split the token on whitespace and try each part ("B2 B3" →
B2, B3; "EDA1 EDA8" → EDA1, EDA8); (3) anything still unmatched is skipped
with a `SERVICE` warning naming the token. Curated aliases start with the
variants measured in the real file: `ED8A` → EDA8, `EDAI` → EDA8,
`SCI` → SC1. "None"/"NONE" means no services, not an error.

### Sectors

The importer's existing `OTHER` catch-all stays, but now warns when it fires.
The dominant unknown code, `IW` (4,863 rows in the real file, the most-used
sector code), stops being unknown: it becomes a real sector (below).

## QR-CONTIG

Contiguity is judged against populated rows only. A family triggers the
warning exclusively when a row belonging to a *different* family sits
strictly between its first and last populated row. Blank rows inside the span
no longer count as a break. The genuinely scattered cases (formerly split
spellings, duplicate enrollments) still warn, which is correct: they are
informational, and the duplicate enrollments are caught as `HEAD-MULTI`
regardless.

## The IW sector (the one database change)

`INSERT` a new row into `sector`: shortcode `IW`, name `Informal Worker`,
description "Informal workers: seasonal, contractual, or self-employed persons without formal employment registration" (wording adjustable with the office at implementation; the category itself is confirmed). Patch file
`sql/patches/v23-add-iw-sector.sql`, folded into a new dump
(`accesscardV23.sql`) following the `database-dump` skill's process, which
also owns the version-reference updates across docs and `AGENTS.md`. The
template's Reference sheet and dropdowns pick the sector up automatically
(they build from the DB lookups), and the importer maps `IW` to it instead of
filing 4,863 people under `OTHER`. No backfill of previously imported rows:
the current database is testing/throwaway data.

## Review table

The presenter already computes exact cell references (`field.cell`); they are
only visible inside the expanded detail. Two presentation changes in
`import-review.js` / `import-review-table.php`:

- A visible **Excel row** column on every person row.
- Each issue in the collapsed Issues cell renders as a cell-ref badge
  ("N42 · Monthly income is blank") so the operator can Ctrl+G in Excel
  without expanding anything. The existing copy-to-clipboard jump behavior
  stays.

The grouped-by-problem view already names sheet rows; it keeps doing so.

## Data Completeness page

A listed sidebar page, because staff set out to visit a work queue, they do
not stumble into it from a toolbar.

- **Manifest:** one `LINKS` entry, key `records-completeness`, label
  "Data Completeness", icon `bi-clipboard-data`, heading `Profiling`, roles
  Developer / Admin / Encoder (Viewer excluded: this is an action queue, not
  a report someone reads for information).
- **Route:** `records/completeness` under the records group, filter
  `roleNav:records-completeness`. Does not collide with `records/(:num)`,
  which matches digits only.
- **Query** (lives in `MemberModel`): families are head rows
  (`memberID = headID`, not soft-deleted), with the family's QR resolved
  through the existing `qr_control` lookup. A family qualifies when any
  member has NULL birthday, sex, civilstatus, education, job, or salary, or
  the head has a blank address or NULL `barangayID`.
- **Page:** summary tiles (families affected, per-field gap counts), then a
  paginated table grouped by family: QR, head, barangay, member count, and
  the gaps, with head-level gaps ("HEAD: no barangay") rendered distinctly
  from member-level ones ("3 members missing income"). Sort: families with
  head-level gaps first, then by member-gap count. Filters: barangay, field.
- **Download:** `records/completeness/download` honoring the active filters,
  builds an `.xlsx` via PhpSpreadsheet (already a dependency): one row per
  member, grouped under its family, one column per tracked field marked when
  missing. It is the field-work checklist the encoder ticks off while
  collecting data.
- **Read-only.** No mutation, therefore no audit rows. Fixes happen through
  the existing edit path, which is audit-trailed.
- **Controller is a dispatcher** (rule 4): a `DataCompletenessController`
  one-liner per action; a library assembles view data; the model owns the
  query.

## Template wording

`FamilyExcelTemplate`'s guidance row and asterisk presentation change to
match the contract: fields marked `*` are needed for a *complete* record;
blanks import but flag the family on the Data Completeness report. The
importer's own required-field checks change per the contract table; the
template change is wording, since the importer reads headers, not asterisks.
Existing downloaded files are unaffected.

## Documentation and tooling

- `docs/12-import.md`: the two-severities section, the blocking and warning
  tables, the demo-file table, and the "review is read-only" framing updated
  for the new contract.
- `docs/10-navigation-and-access.md`: the new page and manifest entry.
- `docs/11-records.md`: the completeness queue as part of the records
  workflow. `docs/13-reference-data.md`: the IW sector.
- `docs/02-database.md` and the dump-version references: per the
  `database-dump` skill when v23 is cut.
- `tools/make-import-demo.php` and `excel/family-import-DEMO-validations.xlsx`
  regenerated: several demo rows change severity.
- `tools/make-test-files.php` seeded-error expectations updated.

## Manual spreadsheet work that remains

Nothing in this design can invent this data; it stays in Excel before
import, surfaced by the review screen itself:

1. ~970 duplicate enrollments (`HEAD-MULTI`): consolidate each family to one
   enrollment, one Head row.
2. 16 headless families (`HEAD-NONE`): mark the head; the review message
   already names the likely one.
3. Junk QRs (`;014520`, `202920290`, `0 13562`, 9-digit values): read the
   real number off the physical card.
4. ~23 rows missing first or last names: fill in or delete the row.
5. Verify the hand edits flagged in the consolidation review (the Bolivar
   deletions, the Olaguer barangay change) against field records.

Truncated birthdays and free-text income move *out* of this list: they import
blank and are chased through the completeness queue instead.

## Testing

- **TDD via PHPUnit** (the CI gate): extend `FamilyExcelImporterTest` with
  the contract table (every demoted case, every kept-blocking case) and the
  parsing fixtures, using the real file's variants ("P3000", "11- 30-2017",
  "9/23/1989", "B2 B3", "ED8A") as test data; extend `ImportReviewPresenterTest`
  for row/cell badge data; new completeness tests for the query (seeded
  fixtures: NULL vs 0 salary, head vs member gaps, soft-deleted exclusion);
  `NavigationManifestTest` for the new entry.
- **Node smoke test** for the review table's new column and badges, following
  the `tests/js/*.smoke.mjs` pattern.
- **End-to-end data check:** `php tools/validate-import.php` against the real
  Cluster1 file, before and after, recorded in the implementation plan.
- **Playwright, agent-driven** (not a project dependency): on a reset, seeded
  database (mysql CLI; the DB is throwaway), log in as Encoder, upload a
  small fixture workbook with seeded gaps, verify the review screen (badges,
  INCOMPLETE warnings, Confirm enabled with only structural issues),
  confirm the import, then open Data Completeness and see exactly the
  imported gaps, and download the checklist. Screenshots reviewed with the
  user before the branch is called done.

## Acceptance

- `tools/validate-import.php` on the Cluster1 file: blocking drops from
  53,648 to approximately 1,050, dominated by the ~970 `HEAD-MULTI`
  duplicate enrollments plus `HEAD-NONE`, junk QRs, and missing names; no
  blocking code outside the identity/structure/length set appears; the
  previously built 7,177 families remain buildable and now clear the
  Confirm gate.
- Blank rows and `'001234`-style QRs behave exactly as today (verified
  already: 33,223 rows read, leading zeros merged).
- Every demoted case except blank relationship (the documented limitation
  above) imports blank, is quoted in its warning, and lands the
  family on the Data Completeness page and its `.xlsx` download.
- Future-dated birthdays import blank instead of rolling back their family
  at write time.
- `IW` resolves to the new sector; unknown sector and service tokens warn
  instead of silently falling back.
- `composer lint` and `composer test` green; demo and test-file generators
  regenerated and passing.
