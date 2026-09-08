<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Navigation;
use Config\Services;

/**
 * Guards the single URL space. Replaces AdminReorgRoutesTest, which asserted the
 * role-prefixed layout. One page, one URI: the role is enforced by the roleNav
 * filter, not by the prefix.
 */
final class RouteSpaceTest extends CIUnitTestCase
{
    private array $getRoutes;
    private array $postRoutes;

    protected function setUp(): void
    {
        parent::setUp();
        $routes = Services::routes(true);
        require APPPATH . 'Config/Routes.php';
        $this->getRoutes  = $routes->getRoutes('GET');
        $this->postRoutes = $routes->getRoutes('POST');
    }

    public function testEveryPageResolvesAtItsFlatUri(): void
    {
        $expected = [
            'dashboard'          => 'DashboardController::dashboard',
            'records'            => 'DashboardController::manageRecords',
            'records/entry'      => 'FamilyController::createFamily',
            'records/import'     => 'FamilyImportController::importForm',
            'records/completeness' => 'DashboardController::dataCompleteness',
            'reference-data'     => 'DashboardController::referenceData',
            'cards'              => 'DashboardController::cards',
            'distribution'       => 'DistributionController::distribution',
            'accounts'           => 'DashboardController::accounts',
            'audit-trails'       => 'DashboardController::auditTrails',
        ];

        foreach ($expected as $uri => $handler) {
            $this->assertArrayHasKey($uri, $this->getRoutes, $uri);
            $this->assertStringContainsString($handler, (string) $this->getRoutes[$uri], $uri);
        }
    }

    public function testRolePrefixesAreGone(): void
    {
        foreach (array_keys($this->getRoutes + $this->postRoutes) as $uri) {
            $this->assertDoesNotMatchRegularExpression(
                '#^(admin|employee|viewer)/#',
                (string) $uri,
                $uri . ' still carries a role prefix'
            );
        }
    }

    public function testGuardedPagesDeclareTheirManifestKey(): void
    {
        $routes = Services::routes(true);
        require APPPATH . 'Config/Routes.php';

        // getRoutesOptions() with no $verb falls back to $this->getHTTPVerb(), which
        // is never 'GET' under PHPUnit (RouteCollection.php:1691) - so the lookup has
        // to be scoped per URI/verb or it reads the wrong bucket. Separately,
        // RouteCollection::create() always normalizes a route's 'filter' option
        // through array_merge() (RouteCollection.php:1461-1463), so the raw options
        // array always holds `['filter' => ['roleNav:records']]`, never a bare
        // string, even for a single, ungrouped filter. getFiltersForRoute() is CI4's
        // own accessor for exactly this ("Returns the filters that should be applied
        // for a single route", RouteCollection.php:1289) and returns list<string>,
        // matching that reality without weakening what's being asserted.
        foreach (['records', 'cards', 'accounts', 'audit-trails', 'distribution'] as $uri) {
            $this->assertSame(
                ['roleNav:' . $uri],
                $routes->getFiltersForRoute($uri, 'GET'),
                $uri . ' must declare its manifest key'
            );
        }

        $this->assertSame(
            ['roleNav:records-completeness'],
            $routes->getFiltersForRoute('records/completeness', 'GET'),
            'records/completeness must declare its manifest key'
        );
    }

    public function testSubsidyTypeActionsLiveUnderReferenceData(): void
    {
        $this->assertArrayHasKey('reference-data/subsidy-types/create', $this->postRoutes);
        $this->assertStringContainsString(
            'SubsidyTypesController::create',
            (string) $this->postRoutes['reference-data/subsidy-types/create']
        );
    }

    public function testScannerKeepsItsOwnSpace(): void
    {
        $this->assertArrayHasKey('scanner/scan', $this->getRoutes);
        $this->assertArrayHasKey('scanner/log', $this->postRoutes);
    }

    /**
     * Every `roleNav:<key>` filter argument in Routes.php must be a key
     * Navigation::pageRoles() recognizes. A typo'd key resolves to an empty
     * roles list, which fails closed to a 404 for everyone - silently, with
     * nothing else catching it - so this walks the route file for every such
     * argument instead of spot-checking a handful of known-good keys.
     */
    public function testEveryRoleNavFilterArgumentIsAKnownManifestKey(): void
    {
        $routesSource = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        preg_match_all('/roleNav:([a-z0-9-]+)/', $routesSource, $matches);
        $keys = array_unique($matches[1]);

        $this->assertNotEmpty($keys, 'expected to find roleNav: filter arguments in Routes.php');

        foreach ($keys as $key) {
            $this->assertNotSame([], Navigation::pageRoles($key), $key . ' is not a known manifest key');
        }
    }
}
