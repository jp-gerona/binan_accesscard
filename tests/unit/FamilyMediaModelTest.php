<?php

namespace Tests\Unit;

use Config\FamilyMediaSettings;
use App\Models\Families\FamilyMediaModel;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Database\DumpSchema;
use Tests\Support\Database\ReferentialFixture;

/** Covers the V24 local-family-media registry contract against the authoritative dump. */
final class FamilyMediaModelTest extends CIUnitTestCase
{
    private FamilyMediaModel $model;

    protected function setUp(): void
    {
        parent::setUp();
        DumpSchema::create(db_connect());
        $this->model = new FamilyMediaModel();
    }

    protected function tearDown(): void
    {
        DumpSchema::drop(db_connect());
        parent::tearDown();
    }

    private function seedHeadWithControl(int $controlNo): int
    {
        $headId = $controlNo + 100000;
        $db = db_connect();

        ReferentialFixture::heads($db, [$headId]);
        $db->table('qr_control')->insert([
            'control_no' => $controlNo,
            'headID'     => $headId,
        ]);

        return $headId;
    }

    public function testDumpDeclaresTheRegistryUniquenessAndNullablePendingHead(): void
    {
        $dump = (string) file_get_contents((string) DumpSchema::dumpPath());
        $matched = preg_match('/CREATE TABLE `family_media` \\((.*?)\\n\\) ENGINE/s', $dump, $table);

        $this->assertSame(1, $matched);
        $this->assertStringContainsString('`headID` int(11) DEFAULT NULL', $table[1]);
        $this->assertStringContainsString('UNIQUE KEY `uq_family_media_source` (`source_filename`)', $table[1]);
        $this->assertStringContainsString('UNIQUE KEY `uq_family_media_head_kind` (`headID`,`kind`)', $table[1]);
    }

    public function testItLinksExactlyOnePhotoToAHead(): void
    {
        $headId = $this->seedHeadWithControl(19186);
        $mediaId = $this->model->upsertPending([
            'source_control_no' => 19186,
            'source_filename'   => '019186.photo.jpg',
            'kind'              => FamilyMediaModel::KIND_PHOTO,
            'state'             => FamilyMediaModel::STATE_PENDING,
        ]);

        $this->assertTrue($this->model->link(
            $mediaId,
            $headId,
            'records/' . $headId . '/media/photo',
            ['content_sha256' => str_repeat('a', 64), 'byte_size' => 123, 'source_modified_at' => '2026-09-08 10:00:00']
        ));
        $this->assertSame($headId, (int) $this->model->findLinked($headId, 'photo')['headID']);
    }

    public function testReplaceKeepsOneCurrentItemPerHeadAndKind(): void
    {
        $headId = $this->seedHeadWithControl(19187);
        $firstId = $this->model->upsertPending([
            'source_control_no' => 19187,
            'source_filename'   => '019187.old.jpg',
            'kind'              => FamilyMediaModel::KIND_PHOTO,
        ]);
        $this->assertTrue($this->model->link($firstId, $headId, 'records/' . $headId . '/media/photo', []));

        $secondId = $this->model->replaceForHead($headId, FamilyMediaModel::KIND_PHOTO, [
            'source_control_no' => 19187,
            'source_filename'   => '019187.new.jpg',
            'media_url'         => 'records/' . $headId . '/media/photo',
        ]);

        $this->assertGreaterThan(0, $secondId);
        $linked = $this->model->findLinkedForHead($headId);
        $this->assertCount(1, $linked);
        $this->assertSame($secondId, (int) $linked[0]['mediaID']);
        $this->assertSame('019187.new.jpg', $linked[0]['source_filename']);
    }

    public function testPendingRowsMayHaveNoHead(): void
    {
        $mediaId = $this->model->upsertPending([
            'source_control_no' => 19188,
            'source_filename'   => '019188.signature.png',
            'kind'              => FamilyMediaModel::KIND_SIGNATURE,
        ]);

        $row = $this->model->find($mediaId);
        $this->assertNotFalse($row);
        $this->assertNull($row['headID']);
        $this->assertSame(FamilyMediaModel::STATE_PENDING, $row['state']);
    }

    public function testSourceFilenameCollisionReturnsTheExistingRegistryRow(): void
    {
        $firstId = $this->model->upsertPending([
            'source_control_no' => 19189,
            'source_filename'   => '019189.photo.jpg',
            'kind'              => FamilyMediaModel::KIND_PHOTO,
        ]);
        $secondId = $this->model->upsertPending([
            'source_control_no' => 99999,
            'source_filename'   => '019189.photo.jpg',
            'kind'              => FamilyMediaModel::KIND_PHOTO,
        ]);

        $this->assertSame($firstId, $secondId);
        $this->assertSame(99999, (int) $this->model->findBySourceFilename('019189.photo.jpg')['source_control_no']);
    }

    public function testLinkRejectsAMemberWhoIsNotAHead(): void
    {
        $headId = $this->seedHeadWithControl(19190);
        $db = db_connect();
        $memberId = $headId + 1;
        $db->table('member')->insert([
            'memberID'   => $memberId,
            'headID'     => $headId,
            'firstname'  => 'MEMBER',
            'middlename' => '',
            'lastname'   => 'FIXTURE',
        ]);
        $mediaId = $this->model->upsertPending([
            'source_control_no' => 19190,
            'source_filename'   => '019190.photo.jpg',
            'kind'              => FamilyMediaModel::KIND_PHOTO,
        ]);

        $this->assertFalse($this->model->link($mediaId, $memberId, 'records/' . $memberId . '/media/photo', []));
        $this->assertNull($this->model->findLinked($memberId, FamilyMediaModel::KIND_PHOTO));
    }

    public function testSettingsDefaultToAnUnsetRootAndDocumentedLimits(): void
    {
        // Reflection reads the class's shipped defaults without the constructor's
        // environment injection, so a deployment that sets a real root in .env
        // cannot change what this test proves about the code.
        $defaults = (new \ReflectionClass(FamilyMediaSettings::class))->getDefaultProperties();

        $this->assertSame('', $defaults['root'] ?? null);
        $this->assertSame(5242880, $defaults['photoMaxBytes'] ?? null);
        $this->assertSame(1048576, $defaults['signatureMaxBytes'] ?? null);
        $this->assertSame(4096, $defaults['maxDimension'] ?? null);
    }
}
