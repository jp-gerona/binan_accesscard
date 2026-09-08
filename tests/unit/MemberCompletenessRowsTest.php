<?php

namespace Tests\Unit;

use App\Models\Families\MemberModel;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Database\DumpSchema;
use Tests\Support\Database\ReferentialFixture;

/**
 * Coverage for completenessRows(): the raw rows behind the Data Completeness
 * report. Active heads and their members with exactly the columns the report
 * reads; soft-deleted rows excluded on both sides.
 */
final class MemberCompletenessRowsTest extends CIUnitTestCase
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

    public function testReturnsActiveHeadsAndMembersWithTheReportColumns(): void
    {
        $db = db_connect();
        $db->table('barangay')->insert(['barangayID' => 1, 'name' => 'SANTO TOMAS']);
        ReferentialFixture::heads($db, [1, 2]);

        // Head 1 complete; head 2 has no income and no barangay.
        $db->table('member')->update(['salary' => 5000, 'barangayID' => 1], ['memberID' => 1]);
        $db->table('member')->update(['salary' => null, 'barangayID' => null], ['memberID' => 2]);

        // A member under head 1 with no education; a soft-deleted member that
        // must not appear at all.
        $db->table('member')->insert([
            'memberID' => 3, 'headID' => 1, 'firstname' => 'ANA', 'middlename' => '', 'lastname' => 'FIXTURE',
            'relationship' => 'CHILD', 'education' => null, 'barangayID' => 1,
        ]);
        $db->table('member')->insert([
            'memberID' => 4, 'headID' => 1, 'firstname' => 'OLD', 'middlename' => '', 'lastname' => 'GONE',
            'relationship' => 'CHILD', 'dt_deleted' => '2026-01-01 00:00:00',
        ]);

        $rows = (new MemberModel())->completenessRows();

        $this->assertSame([1, 2], array_map('intval', array_column($rows['heads'], 'memberID')));
        $this->assertSame([3], array_map('intval', array_column($rows['members'], 'memberID')));
        $this->assertArrayHasKey('salary', $rows['heads'][0]);
        $this->assertArrayHasKey('relationship', $rows['members'][0]);
        $this->assertArrayNotHasKey('contactnumber', $rows['heads'][0]);
    }
}
