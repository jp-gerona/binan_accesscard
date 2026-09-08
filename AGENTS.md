# AGENTS.md

Biñan Access Card: a CodeIgniter 4 application for family and member
access-card records, assistance services, and audit trails, built for the City
of Biñan's CSWD. `CLAUDE.md` is a symlink to this file, so every agent reads the
same instructions.

Full documentation: **`docs/README.md`**. Start with `docs/00-introduction.md` if
you do not know the domain; it carries the glossary the rest assumes.

## Hard rules

Each rule states the consequence of breaking it, and links to the chapter that
explains it properly. These are not preferences.

1. **Never add a migration.** Schema changes are patch files in
   `sql/patches/vNN-*.sql`, folded into a new dump. The dump is the only source
   of truth, and a migration would create a second one that silently disagrees.
   See `docs/02-database.md`.

2. **Column names, enum values, and role names match the current dump
   exactly** (`accesscardV23.sql`). A name invented in code fails at runtime, not
   at lint time, and the SQLite test path may not catch it either. Enum values
   are case-sensitive: `sex` is `MALE`/`FEMALE`. The encoding role is `encoder`
   in the schema, the code, and the interface. See `docs/02-database.md`.

3. **Every family mutation writes an audit row** through
   `Audit/AuditTrailsModel`. A mutation that skips the audit is indistinguishable
   afterwards from data that was never changed, and there is no second source to
   reconstruct it from. Never insert into `audit_trails` directly, and never skip
   it on an "internal" path. See `docs/16-audit-trails.md`.

4. **Controllers decide, libraries build.** Dashboard controllers are one-line
   dispatchers; `Libraries/DashboardPageBuilder.php` assembles view data; models
   own queries. Mixing them produced a controller and a layout per role, which
   drifted, which is why those files were deleted. See `docs/01-architecture.md`.

5. **One page, one URL.** Routes carry no role prefix. A page declares its
   manifest key with `['filter' => 'roleNav:<key>']` and `app/Config/Navigation.php`
   decides which roles reach it. Adding a page is a manifest entry, not an edit
   in three layouts. A role with no entry gets a 404, never a redirect. See
   `docs/10-navigation-and-access.md`.

6. **PHP 8.2 with typed signatures, and no `declare(strict_types=1)`.** The
   absence is deliberate and matches the CI4 appstarter. Adding it to one file in
   passing is inconsistent and can change coercion behaviour. See
   `docs/21-php-style.md`.

7. **Escape every dynamic value in a view.** `esc($v, 'attr')` is the default and
   is required for an unquoted attribute or hand-assembled markup. Inside a
   double-quoted attribute the html context is correct, because attr encoding
   turns an anchor href into `&#x23;section-head`. See `docs/20-frontend.md`.

8. **Never run `composer lint:fix` or `lint:format` across the repository.** The
   repo is deliberately unformatted. A whole-repo reformat produces a diff nobody
   can review and moves executable tokens. See
   `docs/reference/comment-standard.md`.

9. **Leave pre-existing dead code alone.** Remove only orphans your own change
   created. Mention anything else you find and append it to
   `docs/reference/violations.md` instead of deleting it in passing.

10. **Never rank a technical option by how long it takes to build.** Judge on
    correctness, maintainability, runtime cost, and fit with these rules. If
    effort genuinely matters, say so separately, never as the reason for a
    recommendation.

11. **No em dashes** in code comments, documentation, or commit messages. Use a
    comma, a colon, or a full stop. `composer lint:comments` enforces this on
    code. The one exception is the GitHub issue severity format, which is a fixed
    external format.

## Which skill to load, and when

This is the load-bearing table. Pull the skill rather than guessing; each one
carries detail deliberately kept out of this file.

| Situation | Skill |
|---|---|
| About to edit anything under `app/`: controllers, models, views, libraries, routes | `conventions` |
| Starting or serving the app, or it will not boot | `run-app` |
| Changing schema, cutting a dump version, writing a patch file, or hitting a column/enum error | `database-dump` |
| Writing or debugging tests, or a CI job is red | `testing-ci` |
| A branch is ready for review or merge, or you are triaging review findings or opening an issue | `code-review` |
| You changed anything visual and need to verify it | `ui-verification` |
| Writing docs, or writing copy that ships in the interface | `writing-voice` |
| Building a page or component with Bootstrap | `bootstrap`, then `conventions` for this repo's rules on top |

`conventions` is the router into the handbook: it maps a keyword to the chapter
`#rules` anchor that answers it, so you read an anchor rather than a chapter.

## Commands

```bash
php spark routes        # confirm every route resolves to a controller
php spark serve         # dev server; see the run-app skill for the flags that matter
vendor/bin/phpunit      # full test suite
composer test           # alias for phpunit
composer lint           # docblock sniff + comment-style check, required before a PR
composer lint:sniff     # phpcs only (phpcs.xml.dist)
composer lint:comments  # view/CSS headers, banned patterns
bash scripts/check-doc-cites.sh   # every path:line cite in docs/ resolves
```

`composer lint` and `composer test` run on every PR to `main`
(`.github/workflows/ci.yml`), so a red lint blocks the merge, not just the
review.

## Where things are

| What | Where |
|---|---|
| The handbook | `docs/README.md` |
| Domain glossary | `docs/00-introduction.md` |
| Known mess, ticked as it is fixed | `docs/reference/violations.md` |
| Version pins, including the Bootstrap trap | `docs/reference/version-pins.md` |
| Comment and docblock standard | `docs/reference/comment-standard.md` |
| Past design decisions, dated and unmaintained | `docs/superpowers/specs/` |

One thing worth knowing before you debug any styling: dashboard pages load
Bootstrap 5.2.3 compiled into the SB Admin theme, while the JavaScript bundle and
the login page are 5.3.3. A 5.3-only class on a dashboard page silently does
nothing. `docs/reference/version-pins.md` has the detail.
