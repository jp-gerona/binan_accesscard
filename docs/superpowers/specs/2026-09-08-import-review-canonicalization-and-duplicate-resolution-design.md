# Import Review Canonicalization and Duplicate Resolution

Branch: `feat/import-strictness-completeness`

## Goal

Make import review show the same canonical uppercase data that the system stores, accept only deterministic sector and service formatting, distinguish duplicate rows from different families sharing a QR, and give the encoder a reversible way to exclude confirmed duplicate rows before import.

The supplied source workbook is `/Users/JP/Documents/ACCESS CARD/Cluster1_Import_Ver3_2026.xlsx`. It is evidence and a compatibility target. It is never edited by this work.

## Why

The importer already uppercases values in its write payload, but staging retains the original workbook text. Import review therefore shows `Maria Santos` and `Purok 1` while the member record stores `MARIA SANTOS` and `PUROK 1`. A correction made in review also retains its original casing until it reaches the payload. The review screen must show the value that will be stored.

The workbook has sector and service codes that are recoverable without guessing, such as `SC, IW` and `EDA 8, EDA9`. The current service parser is too permissive because it treats whitespace as a separator, accepting `EDA8 EDA9` as two services. A missing comma is ambiguous and must be repaired, not guessed.

A repeated QR has two materially different meanings. It may be the same person copied twice, in which case one row can be excluded. Or it may identify two separate families, in which case discarding a family would lose data and the QR must be corrected. The review has to state which case it found.

## Scope

- Canonicalize staged values on upload and on every review-editor Apply.
- Replace generic review labels with field-specific and outcome-specific labels.
- Make invalid sector and service codes blocking.
- Replace in-file duplicate-person warnings with deterministic duplicate-row resolution.
- Detect separate family blocks that share one QR.
- Add discarded-row state and restore actions to import review only.
- Update the generated future template to Ver4 after system behavior is complete.
- Repair the existing MariaDB CI fixture failure.

## Non-goals

- Do not edit or regenerate the supplied Ver3 workbook.
- Do not change the database schema or add a migration.
- Do not fuzzy-match names, addresses, or identities.
- Do not merge values from two duplicate rows. The encoder keeps one complete row.
- Do not use Excel's `CHECK` result as import input or authority.
- Do not turn every completeness field into a blocking requirement.
- Do not alter a different-family QR conflict by discarding a row.

## Canonical staging

`FamilyExcelImporter` gets a shared staging canonicalizer used by both `parseFile()` and the review Apply path before revalidation.

| Input | Staged value | Rule |
|---|---|---|
| `Maria  Santos` | `MARIA SANTOS` | Name-safe character cleanup, whitespace collapse, uppercase. |
| `Purok  1,` | `PUROK 1,` | Address-safe character cleanup, whitespace collapse, uppercase. Punctuation is preserved. |
| `Head` | `HEAD` | Uppercase choice or free-text value. |
| `Jr.` | `JR` | Resolve to the exact `member.suffix` enum spelling. |
| `EDA 8, EDA9` | `EDA8,EDA9` | Comma-delimited codes, each uppercased with internal whitespace removed. |
| `EDA8 EDA9` | `EDA8EDA9` | One malformed token. It is not split on whitespace. |

QR and birthday keep their existing specialized parsers. Blank placeholders continue to become blank values, never invented values.

Address comparison uses the staged uppercase value with whitespace collapsed. It does not remove periods, commas, or any other punctuation. `PUROK 1` and `PUROK 1,` remain different addresses that need a human decision.

The current suffix aliases return `Jr` and `Sr`, but the authoritative dump permits `JR` and `SR`. The canonicalizer must return the dump values.

## Sectors and services

Sector and service cells are comma-delimited lists only. The importer splits on commas, discards empty list positions, canonicalizes each token, and joins the staged display value with commas.

- `SC, IW` becomes `SC,IW`.
- `EDA 8, EDA9` becomes `EDA8,EDA9`.
- `EDA8 EDA9` becomes `EDA8EDA9`, which is an invalid service code.
- `EDA123,EDA8` reports `EDA123` as an invalid service code. `EDA8` remains visible but the row cannot be imported until the cell is corrected.

An invalid sector or service code is a Must fix issue. The importer no longer files an unknown sector under Other Sectors or skips an unknown service. This prevents data loss hidden behind a warning.

## Violation language and severity

The review keeps machine-readable codes for tests and filtering, but the interface shows field-specific labels and the exact effect of each issue. Generic labels such as `Missing required value`, `Missing value`, and `QR belongs to someone else` are removed from the interface.

### Must fix

| Condition | Interface label | Required action |
|---|---|---|
| Empty or malformed workbook | File cannot be imported | Use a valid template workbook. |
| Missing or invalid QR | Missing QR, Invalid QR, QR is zero, QR is too large, QR cell is a formula, QR cell contains an Excel error | Enter the card's actual QR. |
| QR already assigned in the database to another head | QR already assigned to another family | The message names the existing head and incoming head. Correct the incoming QR. |
| Missing first or last name | Missing FirstName, Missing LastName | Fill the named identity field. |
| One QR has no head | No Head in Family | Mark exactly one person as Head. |
| Two heads in one contiguous family block | Multiple Heads (Same Family) | Correct relationship values. |
| Two separate family blocks share a QR | Duplicate QR (Multiple Families) | Correct the QR of the other family. The message names both row ranges and heads. |
| Address or barangay differs under a QR | Multiple Addresses in Family | Resolve the household address or QR. |
| Deterministic duplicated person rows | Duplicate Row | Keep one complete row and discard the copies from this import. |
| Unknown sector or service token | Invalid Sector Code, Invalid Service Code | Correct the comma-delimited code list. |
| Data exceeds a database limit | FirstName too long, LastName too long, and so on | Shorten the named field. |

### Import allowed, follow-up required

A blank field that the schema can accurately store as `NULL` remains importable. It must never receive a made-up default. In particular, blank income is not `0` or `No regular income`, and blank job is not a guessed occupation.

Every such issue names the field and its outcome. Examples:

- `Missing Income - import allowed. Saved without income and added to Data Completeness.`
- `Missing Birthday - import allowed. Saved without birthday and added to Data Completeness.`
- `Invalid Barangay - import allowed. Saved without barangay and added to Data Completeness.`
- `Invalid Birthday - import allowed. Saved without birthday and added to Data Completeness.`

Relationship stays an import-allowed follow-up issue because a blank member relationship stores as `MEMBER`. The message states that result explicitly.

Re-upload, existing-person, append, contact-format, suffix-normalization, and family-contiguity notices keep their current safe behavior, but their labels state the actual result. Examples include `Family already on file, skipped`, `Existing family differs, changes not imported`, and `Person already recorded under QR 6001`.

Asterisks in the generated template mean required for a complete record, not required before any import. The template copy and review wording state that blank starred fields are saved as missing and queued on Data Completeness.

## Duplicate classification

### Duplicate Row

A duplicate row group is deterministic only when every active row has the same QR, relationship, normalized first name, middle name, last name, suffix, parsed birthday, household address, and barangay. The group is a `Duplicate Row` Must fix issue on each row.

A blank identity field prevents duplicate-row classification. The encoder may repair that field through the normal row editor. Revalidation then exposes the duplicate group if it becomes provable.

The focused resolver compares every row in the group. For two rows it is a side-by-side comparison. For three or more it is a selectable comparison grid. The encoder selects one complete row to keep. All other group rows become discarded. The resolver never builds a hybrid record from multiple rows.

### Duplicate QR, Multiple Families

The importer identifies populated contiguous QR blocks, ignoring blank spreadsheet rows. A `Duplicate QR (Multiple Families)` Must fix issue occurs only when the same QR appears in multiple blocks, each block contains a Head, and the Heads are different normalized people.

The review message names the affected row ranges and heads. The operator must correct the second household's QR. This condition does not offer a discard action.

`Multiple Heads (Same Family)` applies to more than one Head inside a single contiguous QR block. A separated continuation with the same family head stays the warning `Family rows not together`.

## Review interaction

The existing accordion editor stays available for all active rows, including rows in a duplicate group. No issue has to be repaired before another. Confirm import stays disabled until every active Must fix issue is resolved.

Discarded rows use the existing review table layout:

- The leading status cell shows a muted discarded icon.
- The Issues cell states the resolution, for example `Discarded as duplicate of row 589`.
- The action cell provides Restore instead of Edit.
- The All filter includes discarded rows in muted form.
- A Discarded filter shows only discarded rows.

Restoring a row makes it active again and reruns all validation. If the duplicate group returns, Confirm remains disabled until the encoder resolves it again. Discarded rows are excluded from family building and persistence, but retained in the staging bundle and change log until Confirm or Cancel.

## Excel template Ver4

The system must work with Ver3 regardless of the template update. After the server behavior and review are complete, `FamilyExcelTemplate` generates a new Ver4 workbook. Its `CHECK` column remains Excel-only preflight.

Ver4 uses the readable preflight labels measured in Ver3:

- OK
- Missing QR
- Missing Relationship
- Missing LastName
- Missing FirstName
- Missing Income
- No Head in Family
- Multiple Heads (Same Family)
- Duplicate QR (Multiple Families)
- Multiple Addresses in Family

Excel applies red formatting to Must fix conditions and yellow formatting to incomplete-data conditions such as Missing Income. The template guidance explains that yellow values import as missing data and enter Data Completeness. The server continues to enforce its own database-backed validation, current reference-data codes, and QR ownership checks.

## Architecture

`FamilyExcelImporter` owns canonicalization, validation, grouping, duplicate classification, and buildable active rows. `ImportReviewResolution` owns the staged discarded-row map, validates a keep-row choice against the importer's duplicate group, filters active rows, and restores a discarded row.

`FamilyImportController` owns request validation, access checks, staging mutations, and revalidation. It gains explicit discard, restore, and duplicate-resolution actions that delegate resolution rules to `ImportReviewResolution`. It does not contain duplicate matching logic.

`ImportReviewPresenter` and `ImportReviewQuery` shape active and discarded staged rows, readable labels, filters, and resolver payloads. The view supplies the stable table structure. `public/assets/js/dashboard/import-review.js` renders rows, editors, the focused resolver, discard state, and Restore using delegated actions.

No family record is written during review, so review-only discard and restore actions do not write audit rows. Confirm continues to persist only active buildable rows through `FamilyRecordWriter`, which writes family audit trails as today.

## Testing and verification

Work proceeds test-first. The tests prove the behavior before implementation changes.

- `FamilyExcelImporterTest`: staged uppercase names and addresses, exact uppercase suffix enums, comma-only codes, invalid service and sector blockers, punctuation-sensitive addresses, duplicate-row groups of two and three, different-family duplicate QR blocks, same-family multiple heads, and separated continuations.
- Import review presenter and controller tests: field-specific labels, discarded row state, All and Discarded filtering, focused resolver payloads, discard, restore, and revalidation.
- JavaScript smoke coverage: rendering the resolver, muted discarded rows, Restore, and filter state.
- Template coverage: Ver4 check labels, red Must fix formatting, yellow incomplete-data formatting, and explicit completeness guidance.
- CI repair: `MemberCompletenessRowsTest` inserts required `middlename` values for both member fixtures so MariaDB matches the dump.

Verification after implementation:

1. Run the focused failing tests before each production change, then rerun them green.
2. Run `php tools/validate-import.php` against Ver3 without editing it and record the resulting counts and representative messages.
3. Run `composer lint`, `vendor/bin/phpunit --no-coverage`, `php spark routes`, `php scripts/check-route-handlers.php`, `node tests/js/import-review.smoke.mjs`, and `npx eslint public/assets/js`.
4. Run the suite against a scratch MariaDB database using the CI connection settings.
5. Verify the review UI against a small fixture and the generated Ver4 template.
6. Push the branch, update PR #71, and wait for `lint-and-test` and `mariadb-tests` to pass.
