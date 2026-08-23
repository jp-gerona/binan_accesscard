<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Navigation;
use Tests\Support\Database\DumpSchema;

/**
 * The manifest is the single source of truth for the sidebar, page titles, and
 * per-page role access. These assertions are the contract the sidebar view, the
 * layout, and RoleNavFilter all read.
 */
final class NavigationManifestTest extends CIUnitTestCase
{
    public function testSidebarIsEightLinksInFourHeadings(): void
    {
        $this->assertCount(8, Navigation::LINKS);

        $headings = array_values(array_unique(array_column(Navigation::LINKS, 'heading')));
        $this->assertSame(['Dashboard', 'Profiling', 'Distribution', 'Administration'], $headings);
    }

    public function testEveryEntryIsFullyFormed(): void
    {
        foreach (Navigation::LINKS as $link) {
            foreach (['key', 'label', 'icon', 'route', 'heading', 'roles'] as $field) {
                $this->assertArrayHasKey($field, $link);
                $this->assertNotEmpty($link[$field], $field . ' on ' . ($link['key'] ?? '?'));
            }
            $this->assertStringStartsWith('bi-', $link['icon']);
        }
    }

    public function testEncoderSeesNeitherAccountsNorDistribution(): void
    {
        $keys = array_column(Navigation::linksFor('Encoder'), 'key');

        $this->assertContains('records', $keys);
        $this->assertContains('cards', $keys);
        $this->assertNotContains('accounts', $keys);
        $this->assertNotContains('distribution', $keys);
    }

    public function testViewerSeesNoAdministration(): void
    {
        $keys = array_column(Navigation::linksFor('Viewer'), 'key');

        $this->assertNotContains('accounts', $keys);
        $this->assertNotContains('audit-trails', $keys);
        $this->assertContains('records', $keys);
    }

    public function testDeveloperAndAdminSeeEverything(): void
    {
        $this->assertCount(count(Navigation::LINKS), Navigation::linksFor('Developer'));
        $this->assertCount(count(Navigation::LINKS), Navigation::linksFor('Admin'));
    }

    public function testUnknownPageGrantsNobody(): void
    {
        $this->assertSame([], Navigation::pageRoles('no-such-page'));
    }

    public function testTitlesComeFromTheManifest(): void
    {
        $this->assertSame('Family Records', Navigation::titleFor('records'));
        $this->assertSame('Account Management', Navigation::titleFor('accounts'));
    }

    public function testEveryUnlistedPageDeclaresAParent(): void
    {
        foreach (array_keys(Navigation::UNLISTED) as $key) {
            $this->assertNotNull(
                Navigation::parentFor($key),
                $key . ' has no breadcrumb parent, so it renders with no way back.'
            );
        }
    }

    public function testEveryUnlistedPageParentIsARealSidebarPage(): void
    {
        foreach (array_keys(Navigation::UNLISTED) as $key) {
            $parent = Navigation::parentFor($key);

            $this->assertNotSame(
                '',
                Navigation::routeFor($parent),
                $key . "'s breadcrumb parent '" . $parent . "' is not a key in Navigation::LINKS, so the breadcrumb link would point at the site root."
            );
        }
    }

    public function testFilterDeniesARoleWithNoEntry(): void
    {
        $filter = new \App\Filters\RoleNavFilter();

        session()->set(['is_logged_in' => true, 'role' => 'encoder', 'user_id' => $this->fixtureUserId()]);
        $denied = $filter->before(service('request'), ['accounts']);
        $this->assertInstanceOf(\CodeIgniter\HTTP\ResponseInterface::class, $denied);
        $this->assertSame(404, $denied->getStatusCode());
    }

    public function testFilterAllowsARoleWithAnEntry(): void
    {
        $filter = new \App\Filters\RoleNavFilter();

        session()->set(['is_logged_in' => true, 'role' => 'encoder', 'user_id' => $this->fixtureUserId()]);
        $this->assertNull($filter->before(service('request'), ['records']));
    }

    public function testFilterSendsAnonymousUsersToLogin(): void
    {
        $filter = new \App\Filters\RoleNavFilter();

        session()->remove(['is_logged_in', 'role']);
        $response = $filter->before(service('request'), ['records']);
        $this->assertStringContainsString('login', $response->getHeaderLine('Location'));
    }

    /**
     * RoleAccess::sessionUserExists() requires the session's user_id to map to a
     * real `users` row, so these two filter tests seed one against the dump's
     * schema. The brief's test bodies did not include this fixture; see
     * task-3-report.md.
     */
    private function fixtureUserId(): int
    {
        DumpSchema::create(db_connect());

        db_connect()->table('users')->insert([
            'username'      => 'nav-fixture',
            'password'      => 'x',
            'account_level' => 'encoder',
        ]);

        return (int) db_connect()->insertID();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        DumpSchema::drop(db_connect());
    }
}
