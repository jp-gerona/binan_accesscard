# Biñan Access Card documentation

Everything about how this system is built, how to run it, and how to work in it.
Written for developers: someone joining the project, someone receiving it in a
turnover, or an IT team taking it over.

Chapters are numbered in the order they make sense to read, not in the order the
code was written. You are not expected to read all of them.

**Reading order, if you are new:** [00](00-introduction.md),
[01](01-architecture.md), [03](03-setup.md). Stop there and go look at the code;
come back for a module chapter when you need it.

**If you are taking over operations:** [00](00-introduction.md),
[03](03-setup.md), [06](06-operations-and-handover.md).

## Understanding the system

| Chapter | What is in it |
|---|---|
| [00 Introduction](00-introduction.md) | What the system does, who uses it, and the glossary the rest of the handbook assumes |
| [01 Architecture](01-architecture.md) | The CodeIgniter layout, feature subnamespaces, the request lifecycle, and the boundary between controllers and libraries |
| [02 Database](02-database.md) | The entity relationship diagram, a tour of all 18 tables including the V24 media registry, and why there are no migrations |
| [03 Setup](03-setup.md) | Getting a checkout running: prerequisites, the dump, `.env`, and the three ways to serve the app |
| [04 Networking](04-networking.md) | Reaching the app from somewhere other than localhost, and the `baseURL` rule that bites everyone once |
| [05 Background worker](05-background-worker.md) | The job queue, the one-minute worker, and automatic private-media reconciliation |
| [06 Operations and handover](06-operations-and-handover.md) | Deploying, matched database and media-root backups, running the worker as a service, administering accounts, and where to look when something breaks |

## The modules

| Chapter | What is in it |
|---|---|
| [10 Navigation and access](10-navigation-and-access.md) | Roles, the navigation manifest, and the rule that every page has exactly one URL |
| [11 Records](11-records.md) | Families and members, the entry form, the records list, editing, and private portrait and signature workflow |
| [12 Import](12-import.md) | The Excel import, every validation rule, the review screen, and the diagnostic tools |
| [13 Reference data](13-reference-data.md) | Barangays, categories, sectors, services, and subsidy types |
| [14 Access cards](14-access-cards.md) | Control numbers, QR generation, and card printing |
| [15 Distribution](15-distribution.md) | Batches, schedule windows, eligibility, the scanner kiosk, and the scan flow |
| [16 Audit trails](16-audit-trails.md) | What gets logged, and the rule that nothing bypasses it |
| [17 Dashboard and reports](17-dashboard-and-reports.md) | How dashboard pages are assembled, where the numbers come from, and the exports |

## Working in the codebase

| Chapter | What is in it |
|---|---|
| [20 Frontend](20-frontend.md) | Layouts, components, the SB Admin theme, and the design system rules |
| [21 PHP style](21-php-style.md) | PHP 8.2 idioms and the conventions this repo actually follows |
| [22 Testing](22-testing.md) | Running the suite, what it guards, and the two-backend CI |
| [23 Performance](23-performance.md) | Indexes, the stats cache, and how to check a query before you optimise it |

## Reference

Not chapters. These are lists you look things up in.

| File | What it is |
|---|---|
| [Version pins](reference/version-pins.md) | The versions this repo is actually built against, and the canonical documentation URLs |
| [Comment standard](reference/comment-standard.md) | The docblock and comment rules `composer lint` enforces |
| [Violations](reference/violations.md) | The live punch-list of known mess, ticked as it gets fixed |

## Also here

`superpowers/` holds the dated design documents behind past features. They record
what was decided and why at the time, and they are not maintained afterwards. If
a spec and a chapter disagree, the chapter is right.
