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
        $this->removeTree($this->root);
        DumpSchema::drop(db_connect());
        parent::tearDown();
    }

    public function testPendingFileLinksAfterTheFamilyAndControlMappingExist(): void
    {
        $this->writeJpeg('019186.photo.jpg', 640, 480);
        $first = $this->reconciler()->run($this->reporter());
        $this->assertSame(1, $first['pending']);
        // A pending file has no family yet, so it waits in the inbox untouched.
        $this->assertFileExists($this->inboxPath('019186.photo.jpg'));

        $headId = $this->seedHeadWithControl(19186);
        $second = $this->reconciler()->run($this->reporter());

        $this->assertSame(1, $second['linked']);
        $this->assertSame($headId, (int) $this->mediaModel->findLinked($headId, 'photo')['headID']);
        // Linking moved the file out of the inbox into the family's store folder.
        $this->assertFileExists($this->storePath($headId, 'photo'));
        $this->assertFileDoesNotExist($this->inboxPath('019186.photo.jpg'));
    }

    public function testInvalidSourceRemainsUnserved(): void
    {
        @mkdir(dirname($this->inboxPath('019187.photo.jpg')), 0770, true);
        file_put_contents($this->inboxPath('019187.photo.jpg'), 'not an image');

        $summary = $this->reconciler()->run($this->reporter());

        $this->assertSame(1, $summary['seen']);
        $this->assertSame(1, $summary['invalid']);
        $this->assertNull($this->mediaModel->findBySourceFilename('019187.photo.jpg'));
        // The office corrects an invalid drop by replacing it; the file is left in place.
        $this->assertFileExists($this->inboxPath('019187.photo.jpg'));
    }

    public function testFingerprintReplacementUpdatesTheSameLinkedMediaRow(): void
    {
        $headId = $this->seedHeadWithControl(19188);
        $this->writeJpeg('019188.photo.jpg', 100, 100, [255, 0, 0]);
        $this->reconciler()->run($this->reporter());
        $first = $this->mediaModel->findLinked($headId, 'photo');

        // The office replaces the photo by dropping the same QR-named file again.
        $inbox = $this->writeJpeg('019188.photo.jpg', 100, 100, [0, 0, 255]);
        touch($inbox, time() + 2);
        $summary = $this->reconciler()->run($this->reporter());
        $second = $this->mediaModel->findLinked($headId, 'photo');

        $this->assertSame(1, $summary['replaced']);
        $this->assertSame((int) $first['mediaID'], (int) $second['mediaID']);
        $this->assertNotSame($first['content_sha256'], $second['content_sha256']);
        // The replacement moved into the store; the inbox is empty again.
        $this->assertFileDoesNotExist($this->inboxPath('019188.photo.jpg'));
        $this->assertSame($second['content_sha256'], hash_file('sha256', $this->storePath($headId, 'photo')));
    }

    public function testDeletionMarksLinkedMediaMissing(): void
    {
        $headId = $this->seedHeadWithControl(19189);
        $this->writeJpeg('019189.photo.jpg', 100, 100);
        $this->reconciler()->run($this->reporter());
        @unlink($this->storePath($headId, 'photo'));

        $summary = $this->reconciler()->run($this->reporter());
        $row = $this->mediaModel->findBySourceFilename('019189.photo.jpg');

        $this->assertSame(1, $summary['missing']);
        $this->assertSame(FamilyMediaModel::STATE_MISSING, $row['state']);
        $this->assertSame($headId, (int) $row['headID']);
    }

    public function testANewSourceTakesTheSlotHeldByAMissingRowForTheSameHeadAndKind(): void
    {
        $headId = $this->seedHeadWithControl(19193);
        $this->writeJpeg('019193.photo.jpg', 100, 100);
        $this->reconciler()->run($this->reporter());
        @unlink($this->storePath($headId, 'photo'));
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
        // The new source filed into the same family's store folder.
        $this->assertFileExists($this->storePath($headId, 'photo'));
    }

    public function testAListingFailureThrowsAndNeverMarksLinkedRowsMissing(): void
    {
        $headId = $this->seedHeadWithControl(19194);
        $this->writeJpeg('019194.photo.jpg', 100, 100);
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
        $this->writeJpeg('019195.photo.jpg', 100, 100, [255, 0, 0]);
        $this->reconciler()->run($this->reporter());

        @unlink($this->storePath($headId, 'photo'));
        $this->reconciler()->run($this->reporter());
        $row = $this->mediaModel->findBySourceFilename('019195.photo.jpg');

        $this->assertSame(FamilyMediaModel::STATE_MISSING, $row['state']);
        $this->assertSame($headId, (int) $row['headID']);

        // Retire the control mapping the same way the existing retired-mapping test does.
        db_connect()->table('qr_control')->where('control_no', 19195)->delete();

        // The office re-drops the file; the number is retired, so only the row's
        // retained head can resolve it.
        $inbox = $this->writeJpeg('019195.photo.jpg', 100, 100, [0, 0, 255]);
        touch($inbox, time() + 2);
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
        $this->writeJpeg('019192.photo.jpg', 100, 100, [255, 0, 0]);
        $this->reconciler()->run($this->reporter());
        $inbox = $this->writeJpeg('019192.photo.jpg', 100, 100, [0, 0, 255]);
        touch($inbox, time() + 2);
        $this->reconciler()->run($this->reporter());
        @unlink($this->storePath($headId, 'photo'));
        $this->reconciler()->run($this->reporter());

        $rows = db_connect()->table('audit_trails')->orderBy('auditID', 'ASC')->get()->getResultArray();

        $this->assertSame(['MEDIA_ADDED', 'MEDIA_REPLACED', 'MEDIA_REMOVED'], array_column($rows, 'user_action'));
        $this->assertSame([null, null, null], array_column($rows, 'userID'));
        $this->assertSame([$headId, $headId, $headId], array_map('intval', array_column($rows, 'memberID')));
    }

    public function testAnOfficeEditDirectlyInTheStoreIsRevalidatedAndFingerprinted(): void
    {
        $headId = $this->seedHeadWithControl(19196);
        $this->writeJpeg('019196.photo.jpg', 100, 100, [255, 0, 0]);
        $this->reconciler()->run($this->reporter());
        $first = $this->mediaModel->findLinked($headId, 'photo');

        // The office swaps the file inside the family's store folder directly.
        $store = $this->storePath($headId, 'photo');
        $this->writeJpegAt($store, 100, 100, [0, 0, 255]);
        touch($store, time() + 2);

        $summary = $this->reconciler()->run($this->reporter());
        $second = $this->mediaModel->findLinked($headId, 'photo');

        $this->assertSame(1, $summary['replaced']);
        $this->assertSame((int) $first['mediaID'], (int) $second['mediaID']);
        $this->assertNotSame($first['content_sha256'], $second['content_sha256']);
        $this->assertSame($second['content_sha256'], hash_file('sha256', $store));
    }

    public function testAStoreEditWithInvalidBytesStopsServingUntilCorrected(): void
    {
        $headId = $this->seedHeadWithControl(19197);
        $this->writeJpeg('019197.photo.jpg', 100, 100);
        $this->reconciler()->run($this->reporter());
        $this->assertNotNull($this->mediaModel->findLinked($headId, 'photo'));

        $store = $this->storePath($headId, 'photo');
        file_put_contents($store, 'corrupted');
        touch($store, time() + 2);

        $summary = $this->reconciler()->run($this->reporter());

        $this->assertSame(1, $summary['invalid']);
        $this->assertNull($this->mediaModel->findLinked($headId, 'photo'), 'Corrupt store bytes must not be served.');
        $row = $this->mediaModel->findBySourceFilename('019197.photo.jpg');
        $this->assertSame(FamilyMediaModel::STATE_INVALID, $row['state']);
        $this->assertSame($headId, (int) $row['headID'], 'The attachment survives so correcting the file re-links.');

        // Correcting the file in place brings the media back.
        $this->writeJpegAt($store, 100, 100, [0, 255, 0]);
        touch($store, time() + 4);
        $restored = $this->reconciler()->run($this->reporter());

        $this->assertSame(1, $restored['linked']);
        $this->assertNotNull($this->mediaModel->findLinked($headId, 'photo'));
    }

    public function testRemovingAPendingDropDeletesItsRowWithoutAnAudit(): void
    {
        $this->writeJpeg('019198.photo.jpg', 100, 100);
        $this->reconciler()->run($this->reporter());
        $this->assertNotNull($this->mediaModel->findBySourceFilename('019198.photo.jpg'));

        // The office removes an unaccepted drop before its family exists.
        @unlink($this->inboxPath('019198.photo.jpg'));
        $this->reconciler()->run($this->reporter());

        $this->assertNull($this->mediaModel->findBySourceFilename('019198.photo.jpg'));
        $this->assertSame([], db_connect()->table('audit_trails')->get()->getResultArray());
    }

    public function testLegacyFlatRootFilesAreAdoptedThroughTheInbox(): void
    {
        $headId = $this->seedHeadWithControl(19199);
        // A flat-layout deployment left accepted files directly in the root.
        $this->writeJpegAt($this->root . DIRECTORY_SEPARATOR . '019199.photo.jpg', 100, 100, [255, 0, 0]);

        $summary = $this->reconciler()->run($this->reporter());

        $this->assertSame(1, $summary['linked']);
        $this->assertFileExists($this->storePath($headId, 'photo'));
        $this->assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . '019199.photo.jpg');
        $this->assertNotNull($this->mediaModel->findLinked($headId, 'photo'));
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

    private function inboxPath(string $filename): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'inbox' . DIRECTORY_SEPARATOR . $filename;
    }

    private function storePath(int $headId, string $kind): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'store' . DIRECTORY_SEPARATOR . intdiv($headId, 100)
            . DIRECTORY_SEPARATOR . $headId . DIRECTORY_SEPARATOR . $kind . '.' . ($kind === 'photo' ? 'jpg' : 'png');
    }

    private function writeJpeg(string $filename, int $width, int $height, array $colour = [255, 255, 255]): string
    {
        $path = $this->inboxPath($filename);
        $this->writeJpegAt($path, $width, $height, $colour);

        return $path;
    }

    private function writeJpegAt(string $path, int $width, int $height, array $colour): void
    {
        @mkdir(dirname($path), 0770, true);
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$colour));
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            @chmod($dir, 0700);
            $entries = @scandir($dir) ?: [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeTree($path);
            } else {
                @chmod($path, 0600);
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
