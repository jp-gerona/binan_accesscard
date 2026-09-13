<?php

namespace Config;

/**
 * The sidebar manifest. One entry per page: what it is called, where it lives,
 * which heading groups it, and which roles may reach it. The sidebar view, the
 * layout's page title, and RoleNavFilter all read this, so a new page is one
 * entry here rather than an edit in three layouts.
 *
 * Order is display order. Headings group consecutive entries.
 */
class Navigation
{
    private const ALL_STAFF = ['Developer', 'Admin', 'Encoder', 'Viewer'];
    private const MANAGERS  = ['Developer', 'Admin'];

    /**
     * @var list<array{key: string, label: string, icon: string, route: string, heading: string, roles: list<string>}>
     */
    public const LINKS = [
        [
            'key' => 'dashboard', 'label' => 'Program Overview', 'icon' => 'bi-grid',
            'route' => 'dashboard', 'heading' => 'Dashboard', 'roles' => self::ALL_STAFF,
        ],
        [
            'key' => 'dashboard-distribution', 'label' => 'Batch Progress', 'icon' => 'bi-bar-chart',
            'route' => 'dashboard?view=distribution', 'heading' => 'Dashboard', 'roles' => self::ALL_STAFF,
        ],
        [
            'key' => 'records', 'label' => 'Family Records', 'icon' => 'bi-people',
            'route' => 'records', 'heading' => 'Profiling', 'roles' => self::ALL_STAFF,
        ],
        [
            'key' => 'reference-data', 'label' => 'Reference Data', 'icon' => 'bi-database',
            'route' => 'reference-data', 'heading' => 'Profiling', 'roles' => self::ALL_STAFF,
        ],
        [
            'key' => 'records-completeness', 'label' => 'Card Readiness', 'icon' => 'bi-clipboard-data',
            'route' => 'records/completeness', 'heading' => 'Profiling',
            'roles' => ['Developer', 'Admin', 'Encoder'],
        ],
        [
            'key' => 'cards', 'label' => 'Access Cards', 'icon' => 'bi-person-vcard',
            'route' => 'cards', 'heading' => 'Distribution',
            'roles' => ['Developer', 'Admin', 'Encoder'],
        ],
        [
            'key' => 'distribution', 'label' => 'Distribution', 'icon' => 'bi-clipboard-check',
            'route' => 'distribution', 'heading' => 'Distribution',
            'roles' => ['Developer', 'Admin', 'Viewer'],
        ],
        [
            'key' => 'accounts', 'label' => 'Account Management', 'icon' => 'bi-person-gear',
            'route' => 'accounts', 'heading' => 'Administration', 'roles' => self::MANAGERS,
        ],
        [
            'key' => 'audit-trails', 'label' => 'Audit Trails', 'icon' => 'bi-clock-history',
            'route' => 'audit-trails', 'heading' => 'Administration', 'roles' => self::MANAGERS,
        ],
    ];

    /**
     * Pages that are reachable but carry no sidebar link, because nobody sets out
     * to visit them: they are reached from a toolbar on the page that owns them.
     * Same shape as LINKS minus the display fields.
     *
     * 'dashboard-reports' is not a page at all but the pair of read-only
     * endpoints the dashboard's Distribution pane reads from (its live poll and
     * its Download Report link). It carries its own key because the pane renders
     * for every staff role while the Distribution page it used to be grouped
     * with does not, which left an Encoder on a pane whose data 404'd silently.
     * The batch writes stay on the 'distribution' key.
     *
     * @var array<string, list<string>> page key => allowed roles
     */
    public const UNLISTED = [
        'records-entry'     => ['Developer', 'Admin', 'Encoder'],
        'records-import'    => ['Developer', 'Admin', 'Encoder'],
        'records-profile'   => self::ALL_STAFF,
        'records-edit'      => ['Developer', 'Admin', 'Encoder'],
        'records-update'    => ['Developer', 'Admin', 'Encoder'],
        'records-media'     => ['Developer', 'Admin', 'Encoder'],
        'dashboard-reports' => self::ALL_STAFF,
    ];

    /**
     * Titles for the unlisted pages. Listed pages take their title from their label.
     *
     * @var array<string, string>
     */
    private const UNLISTED_TITLES = [
        'records-entry'     => 'New Family Record',
        'records-import'    => 'Import Family Records',
        'records-profile'   => 'Family Profile',
        'records-edit'      => 'Edit Family Record',
        'records-update'    => 'Edit Family Record',
        'records-media'     => 'Family Media',
        'dashboard-reports' => 'Distribution Report',
    ];

    /**
     * Breadcrumb ancestry: unlisted page key => the listed page key it hangs off.
     * A page with no entry here renders no breadcrumb, which is what every
     * sidebar-listed page wants.
     *
     * @var array<string, string>
     */
    private const UNLISTED_PARENTS = [
        'records-entry'   => 'records',
        'records-import'  => 'records',
        'records-profile' => 'records',
        'records-edit'    => 'records',
        'records-update'  => 'records',
        'records-media'   => 'records',
        // Neither endpoint renders a breadcrumb (one returns JSON, the other
        // PDF bytes), but the manifest's invariant is that every unlisted key
        // names the page it hangs off, and the dashboard is where both are
        // reached from.
        'dashboard-reports' => 'dashboard',
    ];

    /**
     * Roles allowed on a page key. An unknown key grants nobody, so a typo in a
     * route definition fails closed rather than opening a page to everyone.
     *
     * @return list<string>
     */
    public static function pageRoles(string $key): array
    {
        foreach (self::LINKS as $link) {
            if ($link['key'] === $key) {
                return $link['roles'];
            }
        }

        return self::UNLISTED[$key] ?? [];
    }

    /**
     * Sidebar entries visible to a role, in declaration order.
     *
     * @return list<array{key: string, label: string, icon: string, route: string, heading: string, roles: list<string>}>
     */
    public static function linksFor(string $role): array
    {
        return array_values(array_filter(
            self::LINKS,
            static fn (array $link): bool => in_array($role, $link['roles'], true)
        ));
    }

    /** Page heading for a key, for both listed and unlisted pages. */
    public static function titleFor(string $key): string
    {
        foreach (self::LINKS as $link) {
            if ($link['key'] === $key) {
                return $link['label'];
            }
        }

        return self::UNLISTED_TITLES[$key] ?? ucwords(str_replace('-', ' ', $key));
    }

    /** The page key this one hangs off for breadcrumbs, or null when it is top level. */
    public static function parentFor(string $key): ?string
    {
        return self::UNLISTED_PARENTS[$key] ?? null;
    }

    /** Route for a listed page key; unlisted pages are reached from a page that owns them. */
    public static function routeFor(string $key): string
    {
        foreach (self::LINKS as $link) {
            if ($link['key'] === $key) {
                return $link['route'];
            }
        }

        return '';
    }
}
