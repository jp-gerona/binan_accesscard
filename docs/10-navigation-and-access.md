# Navigation and access

One page, one URL. Routes carry no role prefix. A page declares a key, and a
manifest decides which roles can reach that key.

That single sentence is the whole design, and it is worth understanding before
you add a page, because the obvious alternative is the thing this replaced.

## Why it works this way

The system serves five roles, and most pages are shared by several of them. The
straightforward approach is a URL space per role: `admin/records`,
`employee/records`, `viewer/records`. It is easy to build and it fails in a
specific way.

You get a controller per role, because each prefix needs a handler. You get a
layout per role, because each shell renders its own sidebar. And you get a
sidebar defined in three places, which drift, so a link added for Admin quietly
never appears for Encoder. Changing one page means editing three files and
remembering the third.

So the prefixes are gone, along with the `Employee\` and `Viewer\` controllers
and their layouts. There is one route, one controller method, and one shell. The
only thing that varies by role is what the manifest says the role can see.

## The manifest

`app/Config/Navigation.php` holds it. Each listed page is one entry:

```php
[
    'key' => 'records', 'label' => 'Family Records', 'icon' => 'bi-people',
    'route' => 'records', 'heading' => 'Profiling', 'roles' => self::ALL_STAFF,
],
```

Three consumers read it, which is the point: the sidebar view renders from it,
the layout takes each page's title from it, and `RoleNavFilter` decides access
from it. Adding a page is one entry here, not an edit in three layouts.

Order in the file is display order, and `heading` groups consecutive entries into
the sidebar's sections: Core, Profiling, Distribution, Administration.

The Profiling heading, for example, lists `records`, `records-import`, and
`records-completeness` in that order. The last one is the Card Readiness queue
(chapter 11), reachable by Developer, Admin, and Encoder.

Two role sets cover most pages. `ALL_STAFF` is Developer, Admin, Encoder and
Viewer. `MANAGERS` is Developer and Admin. Pages that do not fit either list
their roles directly, such as Access Cards for Developer, Admin and Encoder.

### Pages with no sidebar link

Some pages are real pages that nobody navigates to deliberately: you arrive at
them from a toolbar on the page that owns them. They live in `UNLISTED`, keyed
the same way but without the display fields:

```php
'records-entry'     => ['Developer', 'Admin', 'Encoder'],
'records-import'    => ['Developer', 'Admin', 'Encoder'],
'records-profile'   => self::ALL_STAFF,
'records-edit'      => ['Developer', 'Admin', 'Encoder'],
'records-update'    => ['Developer', 'Admin', 'Encoder'],
'dashboard-reports' => self::ALL_STAFF,
```

Unlisted pages get their titles from `UNLISTED_TITLES` and their breadcrumb
parent from `UNLISTED_PARENTS`, so the family profile page knows it hangs off
Family Records.

`dashboard-reports` is the odd one and is worth reading the comment on in the
source. It is not a page but a pair of read-only endpoints the dashboard's
Distribution pane reads from. It has its own key because that pane renders for
every staff role while the Distribution page does not, and grouping the two left
an Encoder looking at a pane whose data 404'd silently.

## The filter

`app/Filters/RoleNavFilter.php`, aliased `roleNav`, is declared on the route:

```php
$routes->get('records', 'Admin\DashboardController::manageRecords', ['filter' => 'roleNav:records']);
```

It looks up the key, gets the roles allowed to use it, and compares against the
session role. A role with no entry gets a **404, not a redirect**. That is
deliberate: a redirect confirms the page exists and merely refuses you, which
tells an unauthorised caller something. A 404 tells them nothing.

Two consequences follow.

A page method carries no `RoleAccess::requireRole()` call. The filter is the gate,
and adding a second check inside the controller means two places to keep in
agreement.

A typo in a key grants access to nobody. An unknown key resolves to an empty role
list, so the page 404s for everyone including a Developer. If a page you just
added is invisible to you, check the spelling of the key before you check
anything else.

## The other two filters

Every dashboard route carries three filters, not one. `roleNav` is the
interesting one, but the other two shape the session and are worth knowing when
a page behaves strangely. They are declared by URI pattern in
`app/Config/Filters.php`, not on the route.

`idleTimeout` (`app/Filters/IdleTimeoutFilter.php`) logs a session out after a
period of inactivity, configured by `IdleTimeout`. It clears the auth session
keys through `RoleAccess::forgetLoginSession()` without regenerating the session
id, which is the difference between it and a deliberate logout.

`singleSession` (`app/Filters/SingleSessionFilter.php`) enforces one active
session per account. A second login elsewhere lands on the session-conflict page
rather than silently sharing the account. `ActiveSessionRegistry` tracks who is
where.

`batchSchedule` is added only to the scanner and distribution routes, and is
covered in chapter 15.

## The URL space

Flat, one URI per page:

`dashboard`, `records`, `reference-data`, `cards`, `distribution`, `accounts`,
`audit-trails`, plus `records/entry`, `records/import`, `records/completeness`,
`records/completeness/download`, and `records/{id}`.

The scanner kiosk keeps its own space under `scanner/` and its own shell, because
it is not a dashboard page and does not want the sidebar.

There is no `routeBase` string threaded through controllers and views to rebuild
a role-prefixed URL. That existed and was deleted. Build flat URLs directly.

## Adding a page, end to end

Say you are adding a Reports page for Developer and Admin.

**1. Add the manifest entry** in `app/Config/Navigation.php`, positioned where you
want it in the sidebar:

```php
[
    'key' => 'reports', 'label' => 'Reports', 'icon' => 'bi-file-earmark-text',
    'route' => 'reports', 'heading' => 'Administration', 'roles' => self::MANAGERS,
],
```

**2. Add the route**, naming the same key in the filter:

```php
$routes->get('reports', 'Admin\DashboardController::reports', ['filter' => 'roleNav:reports']);
```

**3. Add the dispatcher method**, one line:

```php
public function reports(): string|RedirectResponse
{
    return (new DashboardPageBuilder($this->request))->renderPage('reports');
}
```

**4. Add the page's branch** in `DashboardPageBuilder`, and its view. Chapter 01
covers that boundary.

**5. Verify:**

```bash
php spark routes
vendor/bin/phpunit tests/unit/RouteSpaceTest.php tests/unit/NavigationManifestTest.php
```

Log in as a role that should not see it and confirm you get a 404 rather than a
redirect.

## Rules

Copied from the conventions this codebase is held to. Terse on purpose.

**Scope:** where controllers live, how routes reach them, and who may open a page.

### Rule 1: Controllers group into feature subnamespaces

`app/Controllers/` is organized by feature, not by verb: `Accounts`, `Admin`,
`Auth`, `Cards`, `Families`, `Lookups`, `Scanner`, plus cross-cutting
`BaseController.php`, `HomeRoleAccessTrait.php`, and shared traits in `Concerns/`.

A new controller goes into the feature directory whose data it owns - a
family-record endpoint belongs in `Families\FamilyController`, not a new
top-level controller.

There are no role-named controller directories. `Admin\DashboardController` is the
one dashboard controller for every staff role; the former `Employee\` and `Viewer\`
copies are deleted.

**Why:** routes, models (`docs/02-database.md`), and views mirror the same
feature split, so one feature reads top-to-bottom.

### Rule 2: Routes target subnamespaces relative to `App\Controllers`

Routes name the subnamespaced controller directly - no `namespace` option, no
leading backslash:

```php
$routes->get('/', 'Auth\AuthController::index');
```

**Anti-pattern:** `['namespace' => ...]` route options or fully-qualified
`\App\Controllers\...` strings - the repo never uses them; CI4 prepends the
default namespace to the relative reference.

### Rule 3: One page, one URL - no role prefix

The `admin/`, `employee/`, and `viewer/` URL prefixes are gone. Each page has a
single flat URI and declares the navigation-manifest key that decides which roles
may reach it:

```php
$routes->get('records', 'Admin\DashboardController::manageRecords', ['filter' => 'roleNav:records']);
```

`app/Config/Navigation.php` holds the manifest: one entry per page (key, label,
icon, route, heading, roles), plus an `UNLISTED` map for pages with no sidebar link
(`records-entry`, `records-import`, `records-profile`, `records-edit`,
`records-update`, `dashboard-reports`).
`App\Filters\RoleNavFilter` (alias `roleNav`) reads it and 404s a role with no
entry, so the response does not confirm that a page the caller may not use exists.

Adding a page means adding a manifest entry plus a route with its key. A controller
page method carries no `RoleAccess::requireRole()` call; the filter is the gate. A
typo in the key grants nobody, because an unknown key returns no roles.

The `scanner/` kiosk keeps its own URL space and its own shell.

**Anti-pattern:** a `routeBase` string threaded through controllers, libraries, and
views to rebuild a role-prefixed URL. It is deleted; build flat URLs directly
(`records/{id}`, `records/{id}/archive`).

### Rule 4: Dashboard page routes map 1:1 to dispatcher methods

Each page URL maps to one method on `Admin\DashboardController` (`dashboard`,
`manageRecords`, `referenceData`, `cards`, `accounts`, `auditTrails`), and each
method is a one-line delegate to `DashboardPageBuilder::renderPage('<manifest
key>')` (see `docs/01-architecture.md`).

**Enforcement:**

- `tests/unit/RouteSpaceTest.php` asserts every page resolves at its flat URI, that
  no route carries a role prefix, and that guarded pages declare their manifest key.
- `tests/unit/NavigationManifestTest.php` asserts the manifest's shape, its role
  filtering, and that `RoleNavFilter` denies a role with no entry.
- `tests/unit/DashboardControllerRoutingTest.php` asserts the page-action methods
  exist - moving or renaming one fails loudly. Update it when a page is added.

### Verification

After any route change:

```bash
php spark routes   # every route must resolve to a real controller method
vendor/bin/phpunit tests/unit/RouteSpaceTest.php tests/unit/NavigationManifestTest.php
```
