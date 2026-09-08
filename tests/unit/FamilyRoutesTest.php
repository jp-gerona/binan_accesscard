<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class FamilyRoutesTest extends CIUnitTestCase
{
    /** Route → handler pairs that must survive the FamilyController split. */
    public function testFamilyRoutesResolveToSplitControllers(): void
    {
        $routes = Services::routes(true);
        require APPPATH . 'Config/Routes.php';
        $getRoutes  = $routes->getRoutes('GET');
        $postRoutes = $routes->getRoutes('POST');

        $expected = [
            ['records/import', $getRoutes, 'FamilyImportController::importForm'],
            ['records/import', $postRoutes, 'FamilyImportController::import'],
            ['records/template', $getRoutes, 'FamilyImportController::downloadTemplate'],
            ['records/data', $getRoutes, 'FamilyDataTableController::dataTable'],
            ['records/qr-check', $getRoutes, 'FamilyController::qrAvailability'],
            ['records/([0-9]+)', $getRoutes, 'FamilyController::profile'],
            ['records/([0-9]+)/media/([a-zA-Z]+)', $getRoutes, 'FamilyMediaController::show'],
            ['records', $postRoutes, 'FamilyController::store'],
        ];

        foreach ($expected as [$path, $routes, $handler]) {
            $this->assertArrayHasKey($path, $routes, $path);
            $this->assertStringContainsString($handler, (string) $routes[$path], $path);
        }
    }
}
