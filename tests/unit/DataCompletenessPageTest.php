<?php

namespace Tests\Unit;

use App\Libraries\DashboardPageBuilder;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Database\DumpSchema;
use Tests\Support\Database\ReferentialFixture;

/**
 * The Data Completeness page: DashboardPageBuilder::buildCompletenessViewData()
 * turns MemberModel::completenessRows() into the chase list the view renders,
 * and Family/completeness.php renders the table, filters and download link.
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

    /**
     * Three heads: one complete, one missing only salary, one whose member is
     * missing only education. The head-gap family must sort first.
     */
    private function seedCompletenessQueue(): void
    {
        $db = db_connect();

        $db->table('barangay')->insert(['barangayID' => 1, 'name' => 'SANTO TOMAS']);
        // A soft-deleted barangay must not label a family: nameMap() filters
        // dt_deleted out, so a head pointing at it gets a blank barangay label.
        $db->table('barangay')->insert([
            'barangayID' => 2,
            'name'       => 'ARCHIVED VILLAGE',
            'dt_deleted' => '2026-01-01 00:00:00',
        ]);
        ReferentialFixture::heads($db, [1, 2, 3]);

        $complete = [
            'address'     => 'SAMPLE STREET',
            'barangayID'  => 1,
            'birthday'    => '1990-01-01',
            'sex'         => 'MALE',
            'civilstatus' => 'MARRIED',
            'education'   => 'COLLEGE',
            'job'         => 'EMPLOYED',
            'salary'      => 5000,
        ];

        $db->table('member')->update($complete, ['memberID' => 1]);
        // Head 2 lives in the archived barangay, so its family must label ''.
        $db->table('member')->update(array_merge($complete, ['salary' => null, 'barangayID' => 2]), ['memberID' => 2]);
        $db->table('member')->update($complete, ['memberID' => 3]);

        // Head 2's only member is complete, so the family's member list stays empty.
        $db->table('member')->insert([
            'memberID'     => 4,
            'headID'       => 2,
            'firstname'    => 'BOY',
            'middlename'   => '',
            'lastname'     => 'FIXTURE',
            'relationship' => 'CHILD',
            'birthday'     => '2000-01-01',
            'sex'          => 'MALE',
            'civilstatus'  => 'SINGLE',
            'education'    => 'ELEMENTARY',
            'job'          => 'STUDENT',
            'salary'       => 0,
        ]);

        // Head 3's member is missing only education.
        $db->table('member')->insert([
            'memberID'     => 5,
            'headID'       => 3,
            'firstname'    => 'ANA',
            'middlename'   => '',
            'lastname'     => 'FIXTURE',
            'relationship' => 'CHILD',
            'birthday'     => '2005-05-05',
            'sex'          => 'FEMALE',
            'civilstatus'  => 'SINGLE',
            'education'    => null,
            'job'          => 'STUDENT',
            'salary'       => 0,
        ]);

        $db->table('qr_control')->insert(['control_no' => 6002, 'headID' => 2]);
        $db->table('qr_control')->insert(['control_no' => 6003, 'headID' => 3]);
    }

    public function testBuilderShapesFamiliesWithHeadGapsFirst(): void
    {
        $this->seedCompletenessQueue();

        $builder = new DashboardPageBuilder(service('request'));
        $data = $builder->buildCompletenessViewData();

        $this->assertSame(2, $data['tiles']['families']);
        $this->assertSame(1, $data['tiles']['headGaps']);
        $this->assertSame(6002, $data['families'][0]['qr']);
        $this->assertSame(['Monthly Income'], $data['families'][0]['headGaps']);
        $this->assertSame([], $data['families'][0]['members']);
        // The archived barangay's name must not leak into the shaped label.
        $this->assertSame('', $data['families'][0]['barangay']);
        $this->assertSame('Education', $data['families'][1]['members'][0]['gaps'][0]);
        // A live barangay still labels its family as before.
        $this->assertSame('SANTO TOMAS', $data['families'][1]['barangay']);
        // Member count includes the head and complete active members omitted from
        // the missing-member detail list.
        $this->assertSame(2, $data['families'][0]['memberCount']);
        $this->assertSame(2, $data['families'][1]['memberCount']);
    }

    /**
     * View structure only; the file is read, not rendered, so the page can be
     * asserted without going through the shell. Mirrors FamilyDataTableTest's
     * file-reading style.
     */
    public function testCompletenessViewRendersTableFiltersAndDownload(): void
    {
        $view = (string) file_get_contents(APPPATH . 'Views/Family/completeness.php');

        $this->assertStringContainsString('id="completenessTable"', $view);
        $this->assertStringContainsString('records/completeness/download', $view);
        $this->assertStringContainsString('name="field"', $view);
        $this->assertStringContainsString('>MEMBERS</th>', $view);
        $this->assertStringContainsString("site_url('records/' . (int) (\$family['headID'] ?? 0))", $view);
        $this->assertStringNotContainsString('<?= $', $view);
    }
}
