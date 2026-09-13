# Import Validation and Card Readiness

## Goal

Make family Excel imports permissive about non-card profiling data but strict
about identity, household ownership, and supplied contact information. Import
review must give every input a deterministic result: it either must be fixed,
imports with a warning because the Head is not ready for an access card, or
imports with an approved default.

The existing Data Completeness feature becomes **Card Readiness**. It is a
Head-only worklist for households that have imported successfully but cannot
yet have an access card printed. An access card belongs to the household and
is transferable among its members, so the Head is the only person whose card
data decides readiness.

## Scope

- Reclassify import validation into Must fix and Warning outcomes.
- Apply fixed defaults to absent optional profiling fields.
- Treat the Head as the sole source of household address and barangay data.
- Make QR ownership and supplied contact numbers strict review concerns.
- Replace Data Completeness with a records-style Card Readiness page.
- Define one reusable Head readiness predicate for the future card-printing
  module.
- Remove obsolete fuzzy service-correction residue.

## Non-goals

- Do not create a schema version or persist a `card_ready` column. Readiness
  is derived from current Head data, so a saved flag would become stale after a
  later family edit.
- Do not build or change the card-printing module in this work. It will use
  the readiness predicate when it is built.
- Do not use fuzzy matching or automatic typo correction for identity,
  sectors, services, or jobs.
- Do not create fake `NO SECTOR` or `NO SERVICE` reference rows. An empty
  assignment set already means no sector or service is recorded.
- Do not change the imported workbook merely to make it pass validation.

## Import contract

### Three deterministic outcomes

| Outcome | Meaning | Confirm import |
| --- | --- | --- |
| Must fix | The data is invalid, unsafe, or ambiguous. | Disabled until resolved. |
| Warning | The record can be stored, but the Head lacks required access-card data. | Allowed. The household appears in Card Readiness. |
| Defaulted | An absent optional profiling value has a safe predefined value. | Allowed. No review issue is added. |

The review interface keeps the existing labels **Must fix** and **Warning**.
It does not add informational rows merely because a default was applied.

### Must fix

The following conditions must never reach persistence unresolved:

- A QR that is blank, malformed, zero, outside the allowed range, a formula,
  an Excel error, or already assigned to another stored household.
- A family that has no Head, more than one Head in the same QR block, or a QR
  shared by different households.
- A QR reused in separated populated blocks unless the Head identity can be
  proven equal. The comparison uses normalized first name, middle name, last
  name, suffix, and parsed birthday. A missing identity component means the
  importer cannot prove the QR is safe and must block it.
- Any supplied malformed contact number. A reviewer corrects it or explicitly
  clears it. Clearing stores no contact number, never the malformed text.
- An invalid supplied sex, birthday, barangay, sector, or service value. The
  reviewer corrects it or clears it where clearing is meaningful.
- A sector or service list containing an unrecognized code. The reviewer
  chooses listed values, assigns the existing `OTHER` sector where appropriate,
  or removes the assignment. Services have no generic Other record.
- A value that exceeds its database limit.

`FamilyExcelImporter` already owns QR validation, QR block classification,
and staging canonicalization. The revised rules remain there, rather than
moving import decisions into the controller. See
`app/Libraries/FamilyExcelImporter.php:252`.

### Warnings and Card Readiness

The Head's card fields are:

- QR or control number
- First name
- Last name
- Suffix, when applicable
- Sex
- Birthday
- Address
- Contact number
- Barangay

QR, first name, and last name are import identity requirements, so they are
Must fix when absent. Suffix is optional. A missing Head sex, birthday,
address, contact number, or barangay imports as missing and produces a
Warning. It makes the household not ready for card printing.

A reviewer may clear an invalid Head sex, birthday, barangay, or contact value
when a correct value is unavailable. The row then changes from Must fix to
Warning. This records the absence honestly and lets a historical workbook be
imported without storing malformed data.

Missing or cleared non-Head card-like values do not affect Card Readiness.
However, a non-Head value that is supplied but malformed is still Must fix, so
the database never stores an invalid contact number, date, enum value, or
reference key.

### Optional profile defaults

Absent optional fields are not Card Readiness gaps. They receive the following
stored values for both Heads and members:

| Field | Stored result when blank |
| --- | --- |
| Monthly income | `0.00`, displayed as `NO REGULAR INCOME` |
| Civil status | `NOT PROVIDED` |
| Education | `NOT PROVIDED` |
| Job | `NOT PROVIDED` |
| Religion | `NOT PROVIDED` |
| Blank non-Head relationship | `MEMBER` |
| Sectors | No `member_sectors` rows |
| Services | No `member_services` rows |

These defaults apply to the importer only. Manual family entry stays outside
this change and retains its current choices and validation.

Sex cannot receive a textual default because the dump limits it to `MALE` or
`FEMALE`. It remains missing when not known, which keeps a Head out of card
printing and never prints an invented value. The current enum is defined in
`accesscardV23.sql:214`.

## Household address rule

One QR represents one household unit, whether it contains one person or many.
The Head row is the only address and barangay source:

- The importer reads Address and Barangay from the Head row.
- Address and Barangay values entered in any member row are ignored.
- Every persisted member inherits the Head household address and barangay.
- Member address or barangay text never creates a multiple-address violation.

This removes a false conflict from survey workbooks where member rows repeat,
omit, or vary address details. The imported family still has exactly one
household address.

## Canonicalization and reference values

Canonicalization is mechanical, never a guess:

- Uppercase textual data and collapse redundant whitespace.
- Normalize valid contact punctuation to the format stored by the application.
  The accepted mobile and Biñan landline forms must be shared by manual entry
  and import validation, rather than maintained as separate client and server
  rules.
- Strip spaces within each comma-delimited sector or service code.
  `SC1, SC2` and `SC1,SC2` both become `SC1,SC2`.
- Whitespace is not a separator. `SC1 SC2` becomes `SC1SC2`, one invalid code
  that needs an explicit review decision.
- Continue valid suffix normalization to the exact database enum values.
- Continue official barangay spelling normalization for lookup only.

No code may select a closest service or sector. The former service-alias
mechanism is already intentionally empty in
`app/Libraries/FamilyExcelImporter.php:66`; remove that empty constant,
its lookup branch, and stale test-generator wording that claims typo aliases
are accepted. Keep deterministic spacing normalization and the existing
database-backed reference lookups.

## Review workflow

The existing staged review remains the only repair surface before Confirm.
Each active row can be edited, then revalidated by the server.

- Must fix rows state the field and the action required. Confirm stays disabled
  while any active Must fix remains.
- Warning rows state that the household imports but is not ready for an access
  card when the missing field belongs to the Head.
- Blank optional profile values do not create review text.
- Sector and service controls present current reference choices, not a fuzzy
  correction suggestion. Sector choices include the real `OTHER` sector;
  service choices have no fabricated Other option.
- The review uses the same select-plus-custom-text behavior as manual entry for
  free-text fields with an Other choice, especially Job.
- A malformed contact is editable. Clearing it intentionally is a distinct
  action from accepting the malformed string.

## Card Readiness page

Keep the existing `records/completeness` URL so existing links remain valid,
but rename the navigation item and page title to **Card Readiness**.

The page is a records worklist, not a dashboard. It uses the visual conventions
of Family Records and Reference Data:

- A page heading and compact filter toolbar.
- A dense, paginated table with Control Number, Head, Address, Birthday,
  Contact Number, Barangay, missing card fields, and Edit Family.
- Filters appropriate to follow-up work, such as barangay and missing field.
- No statistic cards, dashboard tiles, or decorative summary widgets.
- Only active Heads that fail the shared readiness predicate appear.

Edit Family links to the existing family record workflow. The readiness page
does not introduce a second editor or write path.

## Shared readiness predicate

Introduce one focused domain service or model-owned query predicate that tests
an active Head for card readiness. It returns ready only when the Head has a
valid control number plus first name, last name, sex, birthday, address,
contact number, and barangay. Suffix is deliberately optional.

The predicate backs three consumers:

1. Import review determines whether a Warning means the household will enter
   Card Readiness.
2. Card Readiness finds active Heads that are not ready.
3. A future card-printing module finds active Heads that are ready.

The predicate must not be duplicated in a controller, a view, or the future
printing module. A current Head edit changes readiness immediately because it
is calculated from stored data.

## Architecture

| Component | Responsibility |
| --- | --- |
| `FamilyExcelImporter` | Canonicalize rows, apply defaults, ignore member household cells, validate the import contract, and build safe family payloads. |
| Import review controller and staging store | Authorize edits, save staged changes, revalidate, and prevent Confirm while Must fix rows exist. |
| Import review presenter and JavaScript | Show Must fix and Warning states, field-appropriate controls, and current reference choices. |
| Import contact validator | Normalize and validate the exact contact forms accepted by the importer. |
| Readiness predicate or query | Decide whether an active Head is printable and select incomplete Heads. |
| Dashboard page builder and Card Readiness view | Build and render the Head-only records worklist without dashboard cards. |
| Future printing module | Consume ready Heads through the shared predicate. |

Family persistence continues through `FamilyRecordWriter`, so every imported
family mutation retains its audit trail. The review stage remains non-persistent
and therefore does not write audit rows.

## Tests and verification

Write tests before changing production behavior. Cover at least:

- Valid, absent, malformed, duplicated, and database-owned QR values.
- Multiple Heads, separate QR blocks, matching and incomplete Head
  fingerprints, and a one-person household.
- Valid mobile and Biñan landline contacts, malformed contact correction,
  clearing, and the distinct Head versus non-Head readiness outcomes.
- Head-only address and barangay import, including conflicting member cells
  that are ignored.
- Missing and invalid Head sex, birthday, address, contact, and barangay.
- Optional defaults for both Heads and members, with no corresponding warning.
- Empty sector and service assignments, comma-only code parsing, explicit
  `OTHER` sector resolution, invalid code repair, and no fuzzy aliases.
- Card Readiness query results for one missing Head field at a time and a fully
  ready Head.
- Review Confirm gating, route authorization, and records-style page output
  without statistic-card markup.

Before handoff, run focused importer and readiness tests, the full PHP suite,
the JavaScript review smoke test, lint, route checks, the documentation cite
check, and a visual check of the changed review and Card Readiness pages.
