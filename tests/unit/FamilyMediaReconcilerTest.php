<?php

namespace Tests\Unit;

use App\Jobs\JobReporter;
use App\Libraries\FamilyMediaReconciler;
use App\Libraries\FamilyMediaStorage;
use App\Models\Families\FamilyMediaModel;
use App\Models\Jobs\JobQueueModel;
use CodeIgniter\Test\CIUnitTestCase;
use Config\FamilyMediaSettings;
use RuntimeException;
use Tests\Support\Database\DumpSchema;
use Tests\Support\Database\ReferentialFixture;

/** @internal */
final class FamilyMediaReconcilerTest extends CIUnitTestCase
{
    private string $root;
    private FamilyMediaModel $mediaModel;
    private JobQueueModel $queue;

    protected function setUp(): void
    {
        parent::setUp();
        DumpSchema::create(db_connect());
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'family-media-reconcile-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        $this->mediaModel = new FamilyMediaModel();
        $this->queue = new JobQueueModel();
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->root . DIRECTORY_SEPARATOR . '*') as $path) {
            @unlink((string) $path);
        }
        @rmdir($this->root);
        DumpSchema::drop(db_connect());
        parent::tearDown();
    }

    public function testPendingFileLinksAfterTheFamilyAndControlMappingExist(): void
    {
        $this->writeJpeg('019186.photo.jpg', 640, 480);
        $first = $this->reconciler()->run($this->reporter());
        $this->assertSame(1, $first['pending']);

        $headId = $this->seedHeadWithControl(19186);
        $second = $this->reconciler()->run($this->reporter());

        $this->assertSame(1, $second['linked']);
        $this->assertSame($headId, (int) $this->mediaModel->findLinked($headId, 'photo')['headID']);
    }

    public function testInvalidSourceRemainsUnserved(): void
    {
        file_put_contents($this->root . DIRECTORY_SEPARATOR . '019187.photo.jpg', 'not an image');

        $summary = $this->reconciler()->run($this->reporter());

        $this->assertSame(0, $summary['seen']);
        $this->assertNull($this->mediaModel->findBySourceFilename('019187.photo.jpg'));
    }

    public function testFingerprintReplacementUpdatesTheSameLinkedMediaRow(): void
    {
        $headId = $this->seedHeadWithControl(19188);
        $path = $this->writeJpeg('019188.photo.jpg', 100, 100, [255, 0, 0]);
        $this->reconciler()->run($this->reporter());
        $first = $this->mediaModel->findLinked($headId, 'photo');

        $this->writeJpeg('019188.photo.jpg', 100, 100, [0, 0, 255]);
        touch($path, time() + 2);
        $summary = $this->reconciler()->run($this->reporter());
        $second = $this->mediaModel->findLinked($headId, 'photo');

        $this->assertSame(1, $summary['replaced']);
        $this->assertSame((int) $first['mediaID'], (int) $second['mediaID']);
        $this->assertNotSame($first['content_sha256'], $second['content_sha256']);
    }

    public function testDeletionMarksLinkedMediaMissing(): void
    {
        $headId = $this->seedHeadWithControl(19189);
        $path = $this->writeJpeg('019189.photo.jpg', 100, 100);
        $this->reconciler()->run($this->reporter());
        @unlink($path);

        $summary = $this->reconciler()->run($this->reporter());
        $row = $this->mediaModel->findBySourceFilename('019189.photo.jpg');

        $this->assertSame(1, $summary['missing']);
        $this->assertSame(FamilyMediaModel::STATE_MISSING, $row['state']);
        $this->assertSame($headId, (int) $row['headID']);
    }

    public function testANewSourceTakesTheSlotHeldByAMissingRowForTheSameHeadAndKind(): void
    {
        $headId = $this->seedHeadWithControl(19193);
        $path = $this->writeJpeg('019193.photo.jpg', 100, 100);
        $this->reconciler()->run($this->reporter());
        @unlink($path);
        $this->reconciler()->run($this->reporter());

        // The same head now also maps a second control number; the new canonical
        // file resolves to the head whose photo slot is held by the missing row.
        db_connect()->table('qr_control')->insert(['control_no' => 19194, 'headID' => $headId]);
        $this->writeJpeg('019194.photo.jpg', 100, 100);

        $summary = $this->reconciler()->run($this->reporter());
        $linked = $this->mediaModel->findLinked($headId, 'photo');

        $this->assertSame(1, $summary['linked']);
        $this->assertNotNull($linked);
        $this->assertSame('019194.photo.jpg', $linked['source_filename']);

        // The one-current-item-per-(headID, kind) invariant still holds.
        $all = $this->mediaModel->findLinkedForHead($headId);
        $this->assertCount(1, $all);
        $this->assertSame('019194.photo.jpg', $all[0]['source_filename']);
    }

    public function testAListingFailureThrowsAndNeverMarksLinkedRowsMissing(): void
    {
        $headId = $this->seedHeadWithControl(19194);
        $path = $this->writeJpeg('019194.photo.jpg', 100, 100);
        $this->reconciler()->run($this->reporter());
        $linked = $this->mediaModel->findBySourceFilename('019194.photo.jpg');

        $this->assertSame(FamilyMediaModel::STATE_LINKED, $linked['state']);

        chmod($this->root, 0000);

        try {
            if (@scandir($this->root) !== false) {
                $this->markTestSkipped('The current PHP user can read chmod 0000 directories.');
            }

            $failed = false;

            try {
                $this->reconciler()->run($this->reporter());
            } catch (RuntimeException $exception) {
                $failed = true;
            }

            $this->assertTrue($failed, 'A folder whose listing fails must throw, never mark missing.');
        } finally {
            chmod($this->root, 0700);
        }

        $after = $this->mediaModel->findBySourceFilename('019194.photo.jpg');
        $this->assertSame(FamilyMediaModel::STATE_LINKED, $after['state']);
        $this->assertSame($headId, (int) $after['headID']);
    }

    public function testRestoredFileRelinksToTheOriginalHeadAfterItsControlMappingIsRetired(): void
    {
        $headId = $this->seedHeadWithControl(19195);
        $path = $this->writeJpeg('019195.photo.jpg', 100, 100, [255, 0, 0]);
        $this->reconciler()->run($this->reporter());

        @unlink($path);
        $this->reconciler()->run($this->reporter());
        $row = $this->mediaModel->findBySourceFilename('019195.photo.jpg');

        $this->assertSame(FamilyMediaModel::STATE_MISSING, $row['state']);
        $this->assertSame($headId, (int) $row['headID']);

        // Retire the control mapping the same way the existing retired-mapping test does.
        db_connect()->table('qr_control')->where('control_no', 19195)->delete();

        $this->writeJpeg('019195.photo.jpg', 100, 100, [0, 0, 255]);
        touch($path, time() + 2);
        $summary = $this->reconciler()->run($this->reporter());

        $this->assertSame(1, $summary['linked']);
        $this->assertSame(0, $summary['pending']);

        $linked = $this->mediaModel->findLinked($headId, 'photo');
        $this->assertNotNull($linked);
        $this->assertSame('019195.photo.jpg', $linked['source_filename']);
        $this->assertSame($headId, (int) $this->mediaModel->findBySourceFilename('019195.photo.jpg')['headID']);

        // The head still has exactly one linked photo.
        $all = $this->mediaModel->findLinkedForHead($headId);
        $this->assertCount(1, $all);
    }

    public function testUnconfiguredRootThrowsSoTheWorkerRecordsAFailure(): void
    {
        $settings = new FamilyMediaSettings();
        $settings->root = '';
        $reconciler = new FamilyMediaReconciler(new FamilyMediaStorage($settings), $this->mediaModel);

        $this->expectException(RuntimeException::class);
        $reconciler->run($this->reporter());
    }

    public function testNonHeadMappingRemainsPending(): void
    {
        $headId = 119190;
        ReferentialFixture::heads(db_connect(), [$headId]);
        db_connect()->table('member')->insert([
            'memberID' => $headId + 1,
            'headID' => $headId,
            'firstname' => 'MEMBER',
            'middlename' => '',
            'lastname' => 'FIXTURE',
        ]);
        db_connect()->table('qr_control')->insert(['control_no' => 19190, 'headID' => $headId + 1]);
        $this->writeJpeg('019190.photo.jpg', 100, 100);

        $summary = $this->reconciler()->run($this->reporter());
        $row = $this->mediaModel->findBySourceFilename('019190.photo.jpg');

        $this->assertSame(1, $summary['pending']);
        $this->assertSame(FamilyMediaModel::STATE_PENDING, $row['state']);
        $this->assertNull($row['headID']);
    }

    public function testAlreadyLinkedSourceKeepsItsHeadAfterControlMappingDisappears(): void
    {
        $headId = $this->seedHeadWithControl(19191);
        $this->writeJpeg('019191.photo.jpg', 100, 100);
        $this->reconciler()->run($this->reporter());
        db_connect()->table('qr_control')->where('control_no', 19191)->delete();

        $this->reconciler()->run($this->reporter());

        $this->assertSame($headId, (int) $this->mediaModel->findBySourceFilename('019191.photo.jpg')['headID']);
    }

    public function testEveryLinkedMutationHasOneSystemAuditRow(): void
    {
        $headId = $this->seedHeadWithControl(19192);
        $path = $this->writeJpeg('019192.photo.jpg', 100, 100, [255, 0, 0]);
        $this->reconciler()->run($this->reporter());
        $this->writeJpeg('019192.photo.jpg', 100, 100, [0, 0, 255]);
        touch($path, time() + 2);
        $this->reconciler()->run($this->reporter());
        @unlink($path);
        $this->reconciler()->run($this->reporter());

        $rows = db_connect()->table('audit_trails')->orderBy('auditID', 'ASC')->get()->getResultArray();

        $this->assertSame(['MEDIA_ADDED', 'MEDIA_REPLACED', 'MEDIA_REMOVED'], array_column($rows, 'user_action'));
        $this->assertSame([null, null, null], array_column($rows, 'userID'));
        $this->assertSame([$headId, $headId, $headId], array_map('intval', array_column($rows, 'memberID')));
    }

    private function reconciler(): FamilyMediaReconciler
    {
        $settings = new FamilyMediaSettings();
        $settings->root = $this->root;

        return new FamilyMediaReconciler(new FamilyMediaStorage($settings), $this->mediaModel);
    }

    private function reporter(): JobReporter
    {
        $jobId = $this->queue->enqueue('media_reconcile', []);

        return new JobReporter($this->queue, $jobId);
    }

    private function seedHeadWithControl(int $controlNo): int
    {
        $headId = $controlNo + 100000;
        ReferentialFixture::heads(db_connect(), [$headId]);
        db_connect()->table('qr_control')->insert(['control_no' => $controlNo, 'headID' => $headId]);

        return $headId;
    }

    private function writeJpeg(string $filename, int $width, int $height, array $colour = [255, 255, 255]): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $filename;
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$colour));
        imagejpeg($image, $path, 90);
        imagedestroy($image);

        return $path;
    }
}
