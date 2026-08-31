# Biñan Access Card

A records and distribution system for the City Social Welfare and Development
office of Biñan.

Staff record a family once, the system issues that family a QR access card, and
when the city runs a relief or subsidy distribution the card is scanned at the
venue instead of a name being looked up on a printed list. Every change to a
family record is written to an audit trail.

Built with CodeIgniter 4 and MySQL. Server-rendered, no build step, run on a
laptop or a small server inside the office and sometimes carried to a
distribution venue.

## What it does

- **Records.** Families and their members, entered through a staged form or
  imported from a filled spreadsheet, with barangay, sector, and service details.
- **Excel import.** Bulk entry with a validation pass that names the exact cell of
  every problem before anything is saved.
- **Access cards.** A control number and QR code per family head, generated in
  bulk by barangay and printed as PDFs.
- **Distribution.** Batches targeted by barangay and sector, with a precomputed
  eligibility roster, a schedule that opens and closes the batch on its own, and a
  one-action scanner kiosk for the venue.
- **Audit trails.** Who changed what, and when.
- **Roles.** Developer, Admin, Encoder, Viewer, and a Scanner account for the
  kiosk, with per-page access decided by a single manifest.

## Requirements

- PHP 8.2 or newer, with the `intl` and `mbstring` extensions
- MySQL or MariaDB
- Composer

## Quick start

```bash
composer install
cp env .env                                    # then set app.baseURL and the DB block
mysql -uroot -e "CREATE DATABASE accesscard"
mysql -uroot accesscard < accesscardV23.sql
PHP_CLI_SERVER_WORKERS=8 php spark serve --port 8090
```

Open `http://localhost:8090` and log in as `developer` / `developer123`.

That account ships in the dump for local development. Change or disable it, and
the other accounts that came with the dump, before the app is reachable by anyone
else. See [docs/06-operations-and-handover.md](docs/06-operations-and-handover.md).

Two things commonly go wrong on a first run. `app.baseURL` in `.env` must match
the URL you actually type, or the page loads while its CSS and JavaScript 404.
And `php spark` needs an intl-enabled PHP, which XAMPP's bundled command-line
binary often is not. Both are covered in
[docs/03-setup.md](docs/03-setup.md).

## There are no migrations

The database schema lives in the SQL dump at the repository root
(`accesscardV23.sql`), and that file is the source of truth. Schema changes are
written as patch files under `sql/patches/` and folded into a new dump.

This surprises people who know CodeIgniter, so it is worth stating up front: a
column invented in code and missing from the dump fails at runtime, not at lint
time. [docs/02-database.md](docs/02-database.md) explains the arrangement and
carries the entity relationship diagram.

## Documentation

**[docs/README.md](docs/README.md)** is the index. It is written for developers:
someone joining, someone receiving a turnover, or an IT team taking the system
over.

- New here: [00 Introduction](docs/00-introduction.md),
  [01 Architecture](docs/01-architecture.md), [03 Setup](docs/03-setup.md), in
  that order.
- Taking over operations: [00 Introduction](docs/00-introduction.md),
  [03 Setup](docs/03-setup.md), and
  [06 Operations and handover](docs/06-operations-and-handover.md), which covers
  deployment, backups, the background worker, account administration, and where
  to look when something breaks.

`AGENTS.md` holds the rules for AI coding agents working in this repository.
`CLAUDE.md` is a symlink to it.

## Checking a checkout is healthy

```bash
php spark routes      # every route resolves to a real controller method
vendor/bin/phpunit    # the test suite
composer lint         # docblock and comment-style gates
```

All three pass on a clean checkout. Some database and session tests skip without
the `sqlite3` extension, which is expected.
[docs/22-testing.md](docs/22-testing.md) covers the suite and the two-backend CI.

## License

MIT. See [LICENSE](LICENSE).
