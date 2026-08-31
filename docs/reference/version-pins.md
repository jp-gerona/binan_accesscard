# Version pins and canonical sources

Freshness anchor for the handbook. On a dependency bump, refresh the affected
chapters and update this file.

## Pins (source of truth in parentheses)

| Dependency | Version | Source of truth |
|---|---|---|
| CodeIgniter 4 | v4.7.4 | `composer.lock` (`codeigniter4/framework`) |
| PHP | 8.2.30 | local runtime; repo floor is PHP 8.2 |
| Bootstrap, dashboard CSS | pre-5.3, compiled into SB Admin | `public/assets/sb-admin/css/styles.css` |
| Bootstrap, login CSS | v5.3.3 | `public/assets/bootstrap/css/bootstrap.min.css` (header) |
| Bootstrap, JavaScript | v5.3.3 | `public/assets/bootstrap/js/bootstrap.bundle.min.js` (header) |
| UI theme | SB Admin 1 (`startbootstrap-sb-admin` v7.0.7) | `public/assets/sb-admin/css/styles.css` (header) |
| FullCalendar | 6.1.15 | `public/assets/fullcalendar/index.global.min.js` |
| Current SQL dump | V23 | `accesscardV23.sql` |

## The Bootstrap version is not one number

This repository carries two different Bootstrap CSS builds, and which one applies
depends on the page. Getting this wrong wastes an afternoon, so it is recorded
here as well as in `docs/20-frontend.md`.

**Dashboard pages** load `assets/sb-admin/css/styles.css`, which has Bootstrap
compiled into it. SB Admin v7.0.7 is built on Bootstrap 5.2.3, and that file
contains no 5.3 features at all: no `--bs-emphasis-color`, no
`--bs-primary-bg-subtle`, no `color-scheme`, no `data-bs-theme`, no
`.nav-underline`.

**The login page** loads the separately vendored
`assets/bootstrap/css/bootstrap.min.css`, which is 5.3.3.

**Every page** loads `assets/bootstrap/js/bootstrap.bundle.min.js`, which is
5.3.3.

So a 5.3-only class used on a dashboard page silently does nothing, and anything
verified on the login page was verified against a different stylesheet. The
JavaScript is 5.3.3 everywhere regardless.

If the dashboard needs 5.3 CSS features, the fix is upgrading the SB Admin build,
not adding a second stylesheet on top of it.

## Canonical documentation URLs

- CodeIgniter 4 user guide: https://codeigniter.com/user_guide/
- Bootstrap 5.3: https://getbootstrap.com/docs/5.3/
- Bootstrap 5.2, which is what dashboard CSS actually is:
  https://getbootstrap.com/docs/5.2/
- SB Admin 1: https://startbootstrap.com/template/sb-admin
- PHP manual: https://www.php.net/manual/en/

## Context7 (live framework docs)

- CodeIgniter 4: `/codeigniter4/userguide` (latest docs, no version-pinned id)
- Bootstrap 5.3: `/websites/getbootstrap_5_3` (version-pinned)

**Two caveats before trusting a Context7 answer.**

The CodeIgniter library serves the latest documentation, not the pinned version
above. That is fine while latest matches the pin, which was true as of
2026-07-06, but cross-check anything version-sensitive against this file. If the
repo ever lags a major, prefer the pinned canonical URL.

The Bootstrap library is pinned to 5.3, which matches the JavaScript and the login
page but **not** the dashboard CSS. For a dashboard styling question, check
whether the class or variable existed in 5.2 before relying on the answer.
