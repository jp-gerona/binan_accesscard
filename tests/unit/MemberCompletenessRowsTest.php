<?php

namespace Tests\Unit;

use App\Models\Families\MemberModel;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Database\DumpSchema;
use Tests\Support\Database\ReferentialFixture;

/**
 * Coverage for the head-only Card Readiness query.
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

    public function testCardReadinessReturnsOnlyHeadsMissingCardFields(): void
    {
        $db = db_connect();
        $db->table('barangay')->insert(['barangayID' => 1, 'name' => 'SANTO TOMAS']);
        ReferentialFixture::heads($db, [1, 2]);
        ReferentialFixture::cards($db, [1, 2], 6000);

        $complete = [
            'firstname'     => 'JUAN',
            'lastname'      => 'CRUZ',
            'sex'           => 'MALE',
            'birthday'      => '1990-01-01',
            'address'       => 'SAMPLE STREET',
            'contactnumber' => '09171234567',
            'barangayID'    => 1,
        ];
        $db->table('member')->update($complete, ['memberID' => 1]);
        $db->table('member')->update(array_merge($complete, ['contactnumber' => null]), ['memberID' => 2]);

        $rows = (new MemberModel())->cardReadinessRows();

        $this->assertSame([2], array_map('intval', array_column($rows, 'memberID')));
        $this->assertSame(['Contact Number'], $rows[0]['missing']);
        $this->assertSame(6002, (int) $rows[0]['control_no']);
    }

    public function testEveryRequiredHeadFieldMakesTheHeadNotReady(): void
    {
        $db = db_connect();
        $db->table('barangay')->insert(['barangayID' => 1, 'name' => 'SANTO TOMAS']);
        ReferentialFixture::heads($db, [1, 2, 3, 4, 5]);
        ReferentialFixture::cards($db, [1, 2, 3, 4], 6100);

        $complete = [
            'firstname'     => 'JUAN',
            'lastname'      => 'CRUZ',
            'sex'           => 'MALE',
            'birthday'      => '1990-01-01',
            'address'       => 'SAMPLE STREET',
            'contactnumber' => '09171234567',
            'barangayID'    => 1,
        ];
        foreach ([1, 2, 3, 4, 5] as $id) {
            $db->table('member')->update($complete, ['memberID' => $id]);
        }
        $db->table('member')->update(['sex' => null], ['memberID' => 1]);
        $db->table('member')->update(['birthday' => null], ['memberID' => 2]);
        $db->table('member')->update(['address' => null], ['memberID' => 3]);
        $db->table('member')->update(['contactnumber' => null], ['memberID' => 4]);
        $db->table('member')->update(['barangayID' => null], ['memberID' => 5]);

        $rows = (new MemberModel())->cardReadinessRows();

        $this->assertSame([5, 1, 2, 3, 4], array_map('intval', array_column($rows, 'memberID')));
        $this->assertSame(['Control Number', 'Barangay'], $rows[0]['missing']);
        $this->assertSame(['Sex'], $rows[1]['missing']);
        $this->assertSame(['Birthday'], $rows[2]['missing']);
        $this->assertSame(['Address'], $rows[3]['missing']);
        $this->assertSame(['Contact Number'], $rows[4]['missing']);
    }

    public function testLegacyNameGapsArchivedBarangaysAndInactiveRowsDoNotProduceReadyRows(): void
    {
        $db = db_connect();
        $db->table('barangay')->insert(['barangayID' => 1, 'name' => 'SANTO TOMAS']);
        $db->table('barangay')->insert([
            'barangayID' => 2, 'name' => 'ARCHIVED VILLAGE', 'dt_deleted' => '2026-01-01 00:00:00',
        ]);
        ReferentialFixture::heads($db, [1, 2, 3]);
        ReferentialFixture::cards($db, [1, 2, 3], 6200);

        $complete = [
            'sex'           => 'MALE',
            'birthday'      => '1990-01-01',
            'address'       => 'SAMPLE STREET',
            'contactnumber' => '09171234567',
            'barangayID'    => 1,
        ];
        $db->table('member')->update(array_merge($complete, ['firstname' => '', 'lastname' => '']), ['memberID' => 1]);
        $db->table('member')->update(array_merge($complete, ['barangayID' => 2]), ['memberID' => 2]);
        $db->table('member')->update(array_merge($complete, ['dt_deleted' => '2026-01-01 00:00:00']), ['memberID' => 3]);
        $db->table('member')->insert([
            'memberID' => 4, 'headID' => 2, 'firstname' => '', 'middlename' => '', 'lastname' => '',
            'relationship' => 'CHILD',
        ]);

        $rows = (new MemberModel())->cardReadinessRows();

        $this->assertSame([1, 2], array_map('intval', array_column($rows, 'memberID')));
        $this->assertSame(['First Name', 'Last Name'], $rows[0]['missing']);
        $this->assertSame(['Barangay'], $rows[1]['missing']);
    }

    public function testMalformedLegacyCardValuesRemainInTheReadinessQueue(): void
    {
        $db = db_connect();
        $db->table('barangay')->insert(['barangayID' => 1, 'name' => 'SANTO TOMAS']);
        ReferentialFixture::heads($db, [1, 2, 3, 4, 5]);
        ReferentialFixture::cards($db, [1, 2, 3, 4, 5], 6300);

        $complete = [
            'firstname' => 'JUAN', 'lastname' => 'CRUZ', 'sex' => 'MALE',
            'birthday' => '1990-01-01', 'address' => 'SAMPLE STREET',
            'contactnumber' => '09171234567', 'barangayID' => 1,
        ];
        foreach ([1, 2, 3, 4, 5] as $id) {
            $db->table('member')->update($complete, ['memberID' => $id]);
        }
        $db->table('member')->update(['contactnumber' => 'not-a-number'], ['memberID' => 1]);
        $db->table('member')->update(['sex' => 'UNKNOWN'], ['memberID' => 2]);
        $db->table('member')->update(['birthday' => (new \DateTimeImmutable('tomorrow'))->format('Y-m-d')], ['memberID' => 3]);
        $db->table('member')->update(['firstname' => ''], ['memberID' => 4]);
        $db->table('qr_control')->update(['control_no' => 0], ['control_no' => 6305]);

        $rows = (new MemberModel())->cardReadinessRows();

        $this->assertSame([5, 1, 2, 3, 4], array_map('intval', array_column($rows, 'memberID')));
        $this->assertSame(['Control Number'], $rows[0]['missing']);
        $this->assertSame(['Contact Number'], $rows[1]['missing']);
        $this->assertSame(['Sex'], $rows[2]['missing']);
        $this->assertSame(['Birthday'], $rows[3]['missing']);
        $this->assertSame(['First Name'], $rows[4]['missing']);
    }
}
