# Operations and handover

Written for whoever ends up responsible for keeping this running: an IT
department taking the system over, or the next developer receiving it in a
turnover. Chapter 03 gets a development checkout going; this chapter is about the
copy that people depend on.

## What you are taking on

Three moving parts.

A **CodeIgniter 4 application**, served by a web server pointed at its `public/`
directory. Stateless apart from sessions; you can redeploy it by replacing the
files.

A **MySQL database** whose schema is defined by a SQL dump in the repository
rather than by migrations. This is the part that matters. The application is
replaceable from git; the database is not replaceable from anywhere.

A **background worker process** that drains a job queue. If it is not running,
large imports never finish, and they fail silently rather than loudly.

A **private family-media root** outside this checkout. It holds the portrait
and signature files: an `inbox/` drop zone the office copies into and a
`store/` archive the application files accepted media into, one folder per
family. The database records their registry and association, but not their
image bytes.

## Deploying

Point the web server at `public/`, never at the repository root. CodeIgniter's
entry point lives there deliberately: the rest of the application, the framework,
`.env`, and the SQL dump all sit above it. A server rooted at the repository
serves all of that to anyone who asks for it by path.

Then set up `.env`. It is not in git, so a fresh deployment has to have one
written. The fields that differ from a development setup:

| Field | Development | Production |
|---|---|---|
| `CI_ENVIRONMENT` | `development` | `production` |
| `app.baseURL` | `http://localhost:8090/` | the real URL people type |
| `database.default.username` | `root` | a dedicated account |
| `database.default.password` | empty | set |

`CI_ENVIRONMENT = production` is not cosmetic. In `development`, CodeIgniter shows
full stack traces on error, including file paths and query fragments. That is
exactly what you want while building and exactly what you do not want on a
machine other people reach.

### Configure private family media

Create a folder that both application accounts can reach. The web account that
runs PHP writes uploads synchronously when a family is saved, and the scheduled
worker account scans the same folder, so a deployment that splits the two across
separate accounts needs a shared group with group-write on the directory and
stored files made group-readable. Running both processes under one account is
simpler and also works.

For a Linux deployment whose worker account is `binan-worker` and whose PHP
account is `www-data`, put both accounts in a shared `binan-media` group and make
the directory group-owned and group-writable with the setgid bit, so files the
web account creates inherit the shared group:

```bash
sudo groupadd --system binan-media
sudo usermod -a -G binan-media binan-worker
sudo usermod -a -G binan-media www-data
sudo install -d -o binan-worker -g binan-media -m 2770 /var/lib/binan-accesscard-media
```

Set the corresponding `.env` value, using an absolute local path:

```ini
familymediasettings.root = '/var/lib/binan-accesscard-media'
```

On macOS the folder must sit outside the Desktop, Documents, and Downloads
folders. Those are privacy-protected: the web server or the logged-in terminal
can read them, but the scheduled worker cannot, so every reconcile job fails
with a listing error while the folder looks perfectly fine to whoever is logged
in. A folder directly under the home directory or under `/Library` works.

This handbook calls that configured folder `MEDIA_ROOT` in shell commands; it is
not a second application setting. The application creates the two zones under
it on first use, `MEDIA_ROOT/inbox/` for the office's drops and
`MEDIA_ROOT/store/{shard}/{headID}/` for accepted media, and both inherit the
group set by the setgid directory above. The storage boundary writes uploaded
and accepted files at group-readable `0640`, so the worker account can read
what the web account saved. Keep the folder outside anything the web server
serves directly. Restart the PHP service after changing group membership, then
install the worker at its default one-minute schedule as described in chapter
05.

The database account needs `SELECT`, `INSERT`, `UPDATE`, and `DELETE` on the
`accesscard` database. It does not need `DROP`, and it does not need access to
any other database.

**Change the shipped account passwords before the system is reachable by anyone
else.** The dump ships working staff accounts so a fresh import can log in, and
the development password is published in this handbook and in the README. On a
production deployment, change or disable every account that came with the dump,
then confirm none of them still works. Disable rather than delete, for the audit
reasons below.

Chapter 04 covers exposing the app beyond the local network, and the security
note there applies with more force to a production box.

## Backup and restore

**The database is the state.** The repository is replaceable; the records are
not. Back it up on a schedule you would be comfortable explaining after a disk
failure.

```bash
mysqldump -u<user> -p accesscard > accesscard-$(date +%F).sql
```

Restoring is importing that file:

```bash
mysql -u<user> -p accesscard < accesscard-2026-08-14.sql
```

Family media is a second part of the same record set. Back up the configured
`MEDIA_ROOT` at the same time as the MySQL dump and label both files with the
same backup date or snapshot identifier:

```bash
MEDIA_ROOT=/var/lib/binan-accesscard-media
BACKUP_DATE=$(date +%F)
mysqldump -u<user> -p accesscard > "accesscard-${BACKUP_DATE}.sql"
tar -C "$(dirname "$MEDIA_ROOT")" -czf "media-root-${BACKUP_DATE}.tar.gz" "$(basename "$MEDIA_ROOT")"
```

Restore the MySQL dump and its matching media archive together. For example,
restore `accesscard-2026-08-14.sql` only with
`media-root-2026-08-14.tar.gz`, after stopping the worker:

```bash
mysql -u<user> -p accesscard < accesscard-2026-08-14.sql
tar -C /var/lib -xzf media-root-2026-08-14.tar.gz
```

Do not restore MySQL and `MEDIA_ROOT` from different backup points. The
`family_media` registry in MySQL names files in that root, so a mismatched restore
can point records at absent or wrong media.

**A dump is a file containing the personal details of every family the office
serves**, including minors, and it carries no protection of its own. Treat it
accordingly: write it somewhere only the backup operator can read, encrypt it at
rest and in transit rather than copying it to a shared drive or emailing it, keep
at least one copy off the machine that made it, set a retention limit and
actually delete what ages out, and write down who is allowed to restore one.

A dump left in a developer's home directory or a project folder is the most
likely way this system leaks.

`writable/` holds logs, sessions, the debug bar's output, and caches, none of
which need backing up. Two subdirectories are exceptions worth knowing about:
`writable/uploads/` and `writable/import-staging/` hold uploaded spreadsheets and
in-progress import staging data. Losing those loses any import that has been
uploaded but not yet confirmed. `writable/backups/` is not an automatic backup of
anything; do not mistake it for one. It holds single-table `mysqldump` files
written by the one-time backfill commands before they change data
(`app/Commands/Concerns/DumpsTableBackup.php`), so it carries the same personal
data as a full dump and deserves the same handling.

Test a restore before you need one. A backup nobody has restored is a hypothesis.

## The worker as a service

Chapter 05 has the scripts and the installation commands. The operational points
for a deployment:

- The worker must be running, on a schedule, for imports to complete. Every
  minute is the default and is fine.
- Run it as a dedicated least-privilege account, not as SYSTEM or root. It needs
  read and write on `writable/`, `writable/uploads/`, and the configured private
  `MEDIA_ROOT`, plus network access to MySQL. Nothing else. It parses untrusted
  uploaded files, which is the whole reason for the constraint.
- Its log is `writable/logs/queue-worker.log`. If imports stop completing, read it
  first.
- On a Windows laptop, Scheduled Tasks skip while on battery unless configured
  otherwise. The provided installer handles this; a task created by hand will
  not.

## Administering accounts

Account management lives under `accounts` and is reachable by Developer and Admin
roles. It covers creating a staff account, editing it, resetting a password, and
enabling or disabling it.

The five roles and what they reach are covered in chapters 00 and 10. The
operational rule is this one:

**Disable accounts, do not delete them.** `users.isactive` toggles between
`Enable` and `Disabled`, and the interface offers exactly that. Audit rows and
scan records reference `userID`. Deleting the user breaks the link, and an audit
trail that cannot say who made a change is not an audit trail. A disabled account
cannot log in and stays resolvable forever.

When someone leaves, disable their account the same day. When someone changes
role, edit the existing account rather than creating a second one.

## When something breaks

Start with `writable/logs/`. CodeIgniter writes a dated log file per day, and the
worker writes its own.

| Symptom | First thing to check | Chapter |
|---|---|---|
| Page loads but CSS and JavaScript 404, form posts bounce, QR codes point at the wrong host | `app.baseURL` does not match the URL being used | 04 |
| An import sits on "queued, waiting for worker" and never finishes | The worker is not running, or MySQL was down when it tried | 05 |
| A page 404s for one role but works for another | The navigation manifest has no entry for that role and page key. This is deliberate behaviour, not a bug | 10 |
| "Unknown column" or an enum rejection after a code change | Code is referencing a column or value that is not in the dump | 02 |
| A batch is not visible at the venue | A batch is open only when `closed_at` is NULL and `started_at` is not NULL | 15 |
| Login works, then every page bounces back to login | Session storage under `writable/session` is not writable by the web server user | 03 |
| Everything is slow, especially first page load | On the dev server, the single-worker default. In production, check the indexes | 23 |

If a change to the code is the suspect, chapter 22 covers running the test suite,
and `composer lint` catches documentation and comment problems before they reach
a review.

## Handing it on

Whoever takes this next needs five things: the repository, the current `.env`
values (not the file, the values), a recent matched MySQL and `MEDIA_ROOT`
backup pair, the media-root path and worker account, and the credentials to the
machine it runs on. Everything else in this handbook is reconstructable from the
code.

The secrets among those five must travel as secrets. Use a password
manager or secret store that both parties already have, or an encrypted archive
whose passphrase travels by a different channel. Not email, not chat, not a
shared folder, not a document in the repository.

Then rotate them. Database password, application account passwords, and any
machine credentials should change once ownership does, because the point of a
handover is that the previous holder no longer has access. Rotating afterwards is
also the only way the audit trail keeps meaning anything: an account whose
password two people know cannot answer the question "who did this".

Point them at chapter 00 first. The domain is the part that takes longest to
learn, and it is the part the code does not explain.
