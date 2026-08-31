# Setup

Getting a checkout running. Three ways to serve the app; pick the one that fits
what you are doing. The database setup is the same for all of them and has to
happen first.

## Prerequisites

- **PHP 8.2 or newer**, with the `intl` and `mbstring` extensions. `intl` is not
  optional: several features fail without it, and the failure message is not
  always obvious.
- **MySQL or MariaDB**, reachable on `127.0.0.1:3306`.
- **Composer**, for `composer install`.

On macOS there is a trap worth knowing before you hit it. XAMPP ships its own
PHP, and its command-line build often has no `intl`. That build is fine for
serving through Apache, but `php spark ...` needs an intl-enabled binary. On the
main development machine that is the MacPorts build at `/opt/local/bin/php`.
Check which one you are on:

```bash
php -m | grep intl
```

If that prints nothing, you are on the wrong binary. Either fix your `PATH` or
call the right one explicitly:

```bash
/opt/local/bin/php spark serve --port 8090
```

## The database

The database has to exist before the app will do anything useful. There are no
migrations, so you are importing a dump rather than running a setup command. See
chapter 02 if you want to know why.

```bash
mysql -uroot -e "CREATE DATABASE IF NOT EXISTS accesscard"
mysql -uroot accesscard < accesscardV23.sql
```

The dump carries the schema and the reference seed rows: barangays, sectors,
categories, services, subsidy types. It carries no family data and no cards. A
fresh import gives you a working, empty system.

If you are restoring a database that already has data and only need the newer
schema, use the patch files in `sql/patches/` instead. Chapter 02 lists them in
order.

## Configuration

Copy the sample environment file and edit it:

```bash
cp env .env
```

Two things matter. The database block, which for a standard local setup is
already right:

```ini
database.default.hostname = 127.0.0.1
database.default.database = accesscard
database.default.username = root
database.default.password =
database.default.port = 3306
```

And `app.baseURL`, which must match the URL you actually type into the browser.
CodeIgniter builds every link, asset path, and redirect from it. Get it wrong and
the page loads while the CSS 404s, form posts bounce, and QR codes point at
`localhost` from a phone that has never heard of your laptop. Each run mode below
gives its own value.

## Option 1: XAMPP with Apache

This is how the application is laid out on the main machine, and it is the
closest match to how it gets deployed. The repository lives inside `htdocs`:

- macOS: `/Applications/XAMPP/xamppfiles/htdocs/binan_accesscard`
- Windows: `C:\xampp\htdocs\binan_accesscard`

Start Apache and MySQL from the XAMPP control panel. CodeIgniter's entry point is
the `public/` folder, not the repository root, so the app lives at:

```
http://localhost/binan_accesscard/public/
```

Set `.env` to match:

```ini
app.baseURL = 'http://localhost/binan_accesscard/public/'
```

The `/public/` in the URL is ugly and you can clean it up with a virtual host or
an `.htaccess` rewrite, but nothing requires you to. What you must not do is
point the web server at the repository root to avoid it: that exposes the
application code and the framework to the internet.

## Option 2: the CodeIgniter dev server

The fastest inner loop, and the day-to-day development mode. No Apache, no
`/public/` in the URL, instant restarts.

```bash
PHP_CLI_SERVER_WORKERS=8 php spark serve --port 8090
```

```ini
app.baseURL = 'http://localhost:8090/'
```

The worker count is not decoration. PHP's built-in server runs a single worker by
default, so every CSS file, every image, and every JavaScript bundle is served one
at a time behind the page itself. On a dashboard page that alone adds seconds to
each load, and it looks exactly like a slow application. The environment variable
prefix is Unix shell syntax; on Windows, set it first:

```
set PHP_CLI_SERVER_WORKERS=8
php spark serve --port 8090
```

MySQL still has to be running. Start it through XAMPP or with `mysql.server
start`.

## Option 3: reachable from another machine

Both of the above serve localhost only. To let a phone or another machine reach
the app, see chapter 04. It is a change to `app.baseURL` plus either a Cloudflare
tunnel or a port forward, and the `baseURL` half is the part people forget.

## Logging in

Staff accounts come with the dump, so a fresh import can log in immediately. The
development login is `developer` / `developer123`.

That password is published here, in the README, and in the dump itself, so treat
it as a local-development convenience and nothing more. Before the app is
reachable by anyone other than you, whether that is a production deployment, a
Cloudflare tunnel, or a port forward, change or disable every account that came
with the dump and confirm none of them still works. Chapter 06 covers this as a
deployment step and chapter 04 covers it for temporary sharing.

There is one seeder, `DummyDataSeeder`, and it does something different: it
generates about 50,000 dummy members in family units, for load and performance
work. It never touches sectors, services, or user accounts, and it never creates
a table or a column.

```bash
php spark db:seed DummyDataSeeder
```

Do not run it against a database holding real records unless you want 50,000
fictional families alongside them.

## Checking the checkout is healthy

```bash
php spark routes      # every route resolves to a real controller method
vendor/bin/phpunit    # the test suite
composer lint         # docblock and comment-style gates
```

All three should pass on a clean checkout. Chapter 22 covers what the suite
actually guards, including why some tests skip when the `sqlite3` extension is
missing.

## A note for Windows clones

`CLAUDE.md` in the repository root is a symlink to `AGENTS.md`. Git stores it as a
symlink, and it resolves normally on macOS and Linux. On Windows, a clone made
without `core.symlinks=true` gets a small text file whose entire contents are the
string `AGENTS.md` instead of a working link.

That is harmless to the application, but if you open `CLAUDE.md` and find one line
of text where a document should be, this is why. Enable symlinks and re-clone, or
just read `AGENTS.md` directly.

## Quick reference

| Mode | Command | URL | Needs intl PHP |
|---|---|---|---|
| XAMPP / Apache | start Apache and MySQL in XAMPP | `http://localhost/binan_accesscard/public/` | command line only |
| Dev server | `PHP_CLI_SERVER_WORKERS=8 php spark serve --port 8090` | `http://localhost:8090/` | yes |
| Worker, once | `php spark queue:work` | not applicable | yes |
| Worker, scheduled | `./scripts/install-cron-worker.sh` | not applicable | yes |

Whichever mode you are in, keep `app.baseURL` in sync with the URL you are
actually using. Chapter 04 explains what goes wrong when you do not.
