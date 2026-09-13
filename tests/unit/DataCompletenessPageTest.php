<?php

namespace Tests\Unit;

use App\Libraries\DashboardPageBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Database\DumpSchema;
use Tests\Support\Database\ReferentialFixture;

/**
 * The Card Readiness page shapes only heads with card-required field gaps.
 */
final class DataCompletenessPageTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DumpSchema::create(db_connect());
    }

    protected function tearDown(): void
    {
        DumpSchema::drop(db_connect());
        parent::tearDown();
    }

    public function testCompleteOptionalProfileFieldsDoNotAffectReadiness(): void
    {
        $db = db_connect();
        $db->table('barangay')->insert(['barangayID' => 1, 'name' => 'SANTO TOMAS']);
        ReferentialFixture::heads($db, [1]);
        ReferentialFixture::cards($db, [1], 6000);
        $db->table('member')->update([
            'sex' => 'MALE', 'birthday' => '1990-01-01', 'address' => 'SAMPLE STREET',
            'contactnumber' => '09171234567', 'barangayID' => 1,
            'civilstatus' => null, 'education' => 'NOT PROVIDED', 'job' => 'NOT PROVIDED', 'salary' => null,
        ], ['memberID' => 1]);

        $data = $this->builder()->buildCompletenessViewData();

        $this->assertCount(0, $data['families']);
        $this->assertArrayNotHasKey('tiles', $data);
    }

    public function testBuilderProvidesHeadRowsFiltersAndPagination(): void
    {
        $db = db_connect();
        $db->table('barangay')->insert(['barangayID' => 1, 'name' => 'SANTO TOMAS']);
        ReferentialFixture::heads($db, [1, 2]);
        ReferentialFixture::cards($db, [1, 2], 6000);
        $complete = [
            'sex' => 'MALE', 'birthday' => '1990-01-01', 'address' => 'SAMPLE STREET',
            'contactnumber' => '09171234567', 'barangayID' => 1,
        ];
        $db->table('member')->update(array_merge($complete, ['contactnumber' => null]), ['memberID' => 1]);
        $db->table('member')->update(array_merge($complete, ['address' => null]), ['memberID' => 2]);

        $data = $this->builder()->buildCompletenessViewData();

        $this->assertSame([1, 2], array_map('intval', array_column($data['families'], 'memberID')));
        $this->assertSame(['Contact Number'], $data['families'][0]['missing']);
        $this->assertSame('SANTO TOMAS', $data['families'][0]['barangay']);
        $this->assertSame(25, $data['perPage']);
        $this->assertSame(1, $data['pageCount']);
    }

    public function testCardReadinessViewRendersRecordsTableFiltersAndDownload(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/Family/completeness.php');
        $table = (string) file_get_contents(APPPATH . 'Views/Family/completeness-table.php');

        $this->assertStringContainsString('Card Readiness', $view);
        $this->assertStringContainsString('records/completeness/download', $view);
        $this->assertStringContainsString("'bodyView' => 'Family/completeness-table'", $view);
        $this->assertStringContainsString('id="completenessTable"', $table);
        $this->assertStringContainsString('>CONTROL NUMBER</th>', $table);
        $this->assertStringContainsString('>EDIT FAMILY</th>', $table);
        $this->assertStringNotContainsString('kpi-row', $view);
        $this->assertStringNotContainsString('tileCards', $view);
        $this->assertStringNotContainsString('<?= $', $table);
    }

    public function testCardReadinessDownloadUsesTheCardReadinessFilename(): void
    {
        $response = $this->builder()->completenessDownloadResponse();

        $this->assertSame(
            'attachment; filename="card-readiness-' . date('Y-m-d') . '.xlsx"',
            $response->getHeaderLine('Content-Disposition'),
        );
    }

    private function builder(): DashboardPageBuilder
    {
        return new DashboardPageBuilder(service('request'));
    }
}
