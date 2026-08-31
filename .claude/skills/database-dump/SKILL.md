---
name: database-dump
description: Use when changing the database schema, cutting a new SQL dump version, writing a patch file under sql/patches, resetting a database for a demo, or debugging a column-not-found or enum error. This repo has no migrations; the dump is the source of truth, and this skill covers the workflow that replaces migrations.
---

# Dump and schema workflow

**There are no migrations and there never will be.** The database belongs to the
CSWD deployment. It is restored from a dump, patched in place, and dumped again.
Code follows the dump.

The current dump is `accesscardV23.sql` at the repository root.
`docs/02-database.md` describes the schema itself.

## The immediate consequence

A column name or enum value invented in code and not present in the dump fails at
**runtime**, not at lint time. `composer lint` will not catch it, and the SQLite
test path may not either. When you hit "Unknown column" or an enum rejection,
check the dump before you check anything else:

```bash
sed -n "/^CREATE TABLE \`member\`/,/^) ENGINE/p" accesscardV23.sql
```

Enum values are case-sensitive in practice. `sex` is `MALE` and `FEMALE`, not
`Male` and `Female`.

## Changing the schema

1. **Write a patch file** at `sql/patches/vNN-<topic>.sql`, where `NN` is the new
   dump version. Make it idempotent so a re-run is safe: `ADD INDEX IF NOT
   EXISTS`, guarded `ALTER`s. `sql/patches/v17-indexes.sql` is the template, and
   its comments explain what each statement is for.
2. **Apply it** to a database that already has data, which is what the patch is
   for. A fresh setup needs only the dump.
3. **Dump the result** as `accesscardVNN.sql` at the repository root.
4. **Delete superseded dumps** rather than accumulating them. V18 and V19 were
   removed when V20 landed.

### Ordering within a version

A version shipping several patches has an order, and it is not alphabetical.
Backfills run before the constraints that depend on them, and drops run last,
after any command that still reads the old columns.

V22 landed as: `v22-uppercase.sql`, `v22-barangay-fk.sql`, `v22-normalize.sql`,
then `v22-normalize-drop.sql` once the commands reading the old text columns had
run. V20 was the same shape: `v20-barangay-backfill.sql` before
`v20-eligibility.sql`.

State the order in the patch headers. The next person will not infer it.

## Tests resolve the dump by version

`tests/_support/Database/DumpSchema.php` picks the **highest-numbered**
`accesscardV*.sql` at test time. The CI workflow does the same thing:

```bash
mysql -h 127.0.0.1 -P 3306 -uroot -proot < "$(ls accesscardV*.sql | sort -V | tail -1)"
```

Neither hardcodes a filename, so cutting a new version does not require editing
them. What it does require is that the old dump is removed, or that the highest
number really is the one you meant.

Never build schema in a test with `forge()`. A test against a schema that does
not exist in production is worse than no test, because it passes.

## Resetting for a demo

The dump carries schema and reference seed rows only: barangays, sectors,
categories, services, subsidy types, and the staff accounts. No families, no
cards.

```bash
mysql -uroot -e "DROP DATABASE IF EXISTS accesscard; CREATE DATABASE accesscard"
mysql -uroot accesscard < accesscardV23.sql
```

Then import families through the interface with an Excel file, which is also the
honest way to demo the import. `docs/12-import.md` covers the test workbooks and
the generator scripts under `tools/`.

`DummyDataSeeder` is the other option: about 50,000 dummy members in family units,
for load work. It never touches sectors, services, or accounts, and it never
creates a table or a column. Do not run it against real records.

## Version history and why each bump happened

| Version | What changed |
|---|---|
| V17 | performance indexes behind the records list, dashboard counts, and audit list |
| V18 | reference-data cleanup, began uppercase name storage |
| V19 | renamed aid to subsidy everywhere |
| V20 | batch eligibility roster, `member.barangayID` |
| V21 | batch schedule columns, so batches open and close themselves |
| V22 | `member_sectors` junction, services grouped by key, address and barangay split, uppercase storage completed, `card_generated_at` |
| V23 | `IW` (Informal Worker) sector row, the Cluster1 survey's most-used code |

## Seeds

`app/Database/Seeds/` never creates tables or columns. That is the whole rule.
