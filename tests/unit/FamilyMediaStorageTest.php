<?php

namespace Tests\Unit;

use App\Libraries\FamilyMediaStorage;
use App\Models\Families\FamilyMediaModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\FamilyMediaSettings;
use RuntimeException;

/** @internal */
final class FamilyMediaStorageTest extends CIUnitTestCase
{
    private string $root;
    private FamilyMediaStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'family-media-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        $this->storage = $this->storageFor($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    public function testItAcceptsCanonicalSixDigitPhotoAndInspectsRealJpegBytes(): void
    {
        $this->writeJpeg('019186.photo.jpg', 640, 480);

        $inspection = $this->storage->inspect('019186.photo.jpg');

        $this->assertSame(19186, $inspection['source_control_no']);
        $this->assertSame(FamilyMediaModel::KIND_PHOTO, $inspection['kind']);
        $this->assertSame('image/jpeg', $inspection['mime']);
        $this->assertSame(640, $inspection['width']);
        $this->assertSame(480, $inspection['height']);
    }

    public function testItAcceptsCanonicalSignatureAndSevenDigitControlNumber(): void
    {
        $this->writePng('1234567.signature.png', 200, 100);

        $inspection = $this->storage->inspect('1234567.signature.png');

        $this->assertSame(1234567, $inspection['source_control_no']);
        $this->assertSame(FamilyMediaModel::KIND_SIGNATURE, $inspection['kind']);
        $this->assertSame('image/png', $inspection['mime']);
    }

    public function testItRejectsAnUnpaddedControlFilename(): void
    {
        $this->writeJpeg('19186.photo.jpg', 640, 480);

        $this->assertNull($this->storage->inspect('19186.photo.jpg'));
    }

    public function testItRejectsExtensionSpoofing(): void
    {
        $this->writePng('019186.photo.jpg', 100, 100);
        $this->writeJpeg('019187.signature.png', 100, 100);

        $this->assertNull($this->storage->inspect('019186.photo.jpg'));
        $this->assertNull($this->storage->inspect('019187.signature.png'));
    }

    public function testItRejectsAnUnreadableFile(): void
    {
        $path = $this->writeJpeg('019186.photo.jpg', 100, 100);
        chmod($path, 0000);

        if (is_readable($path)) {
            $this->markTestSkipped('The current PHP user can read chmod 0000 files.');
        }

        $this->assertNull($this->storage->inspect('019186.photo.jpg'));
    }

    public function testItRejectsDimensionsAboveTheConfiguredMaximum(): void
    {
        $this->writeJpeg('019186.photo.jpg', 4097, 1);

        $this->assertNull($this->storage->inspect('019186.photo.jpg'));
    }

    public function testItRejectsFilesAboveTheConfiguredPerKindByteLimit(): void
    {
        $this->writeJpeg('019186.photo.jpg', 100, 100);
        $storage = $this->storageFor($this->root, 1);

        $this->assertNull($storage->inspect('019186.photo.jpg'));
    }

    public function testItRejectsTraversalFilenamesAndLinks(): void
    {
        $this->storage->inboxDir();
        $outside = tempnam(sys_get_temp_dir(), 'family-media-outside-');
        file_put_contents($outside, 'outside');
        symlink($outside, $this->inboxPath('019186.photo.jpg'));

        $this->assertNull($this->storage->inspect('../019186.photo.jpg'));
        $this->assertNull($this->storage->inspect('019186.photo.jpg'));

        @unlink($outside);
    }

    public function testItRejectsEmptyAndProjectStorageRoots(): void
    {
        $this->assertNull($this->storageFor('')->root());
        $this->assertNull($this->storageFor(FCPATH)->root());
        $this->assertNull($this->storageFor(WRITEPATH)->root());
        $this->assertNull($this->storageFor(ROOTPATH)->root());
    }

    public function testItRejectsProjectRootAncestorsAndPreservesExternalTemporaryRoots(): void
    {
        $this->writeJpeg('019186.photo.jpg', 100, 100);
        $this->assertNotNull($this->storage->inspect('019186.photo.jpg'));

        $projectRoot = realpath(ROOTPATH);
        $this->assertNotFalse($projectRoot);
        $ancestor = dirname($projectRoot);
        $controlNo = random_int(1000000000, 9999999999);
        $filename = sprintf('%06d.photo.jpg', $controlNo);
        $destination = $ancestor . DIRECTORY_SEPARATOR . 'inbox' . DIRECTORY_SEPARATOR . $filename;
        $upload = tempnam(sys_get_temp_dir(), 'family-media-upload-');
        $this->assertNotFalse($upload);
        $this->assertFileDoesNotExist($destination);
        $this->writeJpegAt($upload, 100, 100, [255, 255, 255]);

        try {
            $this->assertSame([], $this->storageFor($ancestor)->storeUpload(
                new LocalUploadedFile($upload, 'ignored.jpg'),
                $controlNo,
                FamilyMediaModel::KIND_PHOTO,
            ));
            $this->assertFileDoesNotExist($destination);
        } finally {
            @unlink($upload);
            @unlink($destination);
        }
    }

    public function testCandidateFilenamesListsTheInboxCanonicalFiles(): void
    {
        $this->writeJpeg('019186.photo.jpg', 100, 100);
        $this->writeJpeg('19186.photo.jpg', 100, 100);
        $this->writePng('019187.signature.png', 100, 100);

        $this->assertSame(
            ['019186.photo.jpg', '019187.signature.png'],
            $this->storage->candidateFilenames(),
        );
    }

    public function testCandidateFilenamesIgnoresNonCanonicalEntriesTempsDotfilesDirsAndLinks(): void
    {
        $this->writeJpeg('019186.photo.jpg', 100, 100);
        file_put_contents($this->inboxPath('.DS_Store'), 'junk');
        file_put_contents($this->inboxPath('.019187.photo.jpg.tmp-abc'), 'partial');
        $this->writeJpegAt($this->inboxPath('19186.photo.jpg'), 100, 100, [255, 255, 255]);
        mkdir($this->inboxPath('subdir'), 0700);
        $outside = tempnam(sys_get_temp_dir(), 'family-media-outside-');
        $this->assertNotFalse($outside);
        symlink($outside, $this->inboxPath('019999.photo.jpg'));

        // Only the canonical real file is a candidate; nothing else is judged invalid.
        $this->assertSame(['019186.photo.jpg'], $this->storage->candidateFilenames());

        @unlink($outside);
    }

    public function testCandidateFilenamesThrowsWhenTheInboxCannotBeListed(): void
    {
        $this->storage->inboxDir();
        chmod($this->root, 0000);

        try {
            if (@scandir($this->storage->inboxDir()) !== false) {
                $this->markTestSkipped('The current PHP user can read chmod 0000 directories.');
            }

            $this->assertNotNull($this->storage->root());
            $this->expectException(RuntimeException::class);
            $this->storage->candidateFilenames();
        } finally {
            chmod($this->root, 0700);
        }
    }

    public function testMetadataReportsTheSameCheapStatInspectionUsesAndRejectsGoneOrNonCanonicalFiles(): void
    {
        $path = $this->writeJpeg('019186.photo.jpg', 100, 100);
        $inspection = $this->storage->inspect('019186.photo.jpg');

        $stat = $this->storage->metadata('019186.photo.jpg');

        $this->assertSame((int) $inspection['byte_size'], $stat['byte_size']);
        $this->assertSame($inspection['source_modified_at'], $stat['source_modified_at']);

        $this->assertNull($this->storage->metadata('019187.photo.jpg'));
        $this->assertNull($this->storage->metadata('19186.photo.jpg'));
        $this->assertNull($this->storage->metadata('../019186.photo.jpg'));

        @unlink($path);
        $this->assertNull($this->storage->metadata('019186.photo.jpg'));
    }

    public function testStorePathForDerivesTheShardedStoreLocation(): void
    {
        $expected = realpath($this->root) . DIRECTORY_SEPARATOR . 'store' . DIRECTORY_SEPARATOR . '48'
            . DIRECTORY_SEPARATOR . '4821' . DIRECTORY_SEPARATOR . 'photo.jpg';

        // A stat never creates directories; a write does.
        $this->assertSame($expected, $this->storage->storePathFor(4821, FamilyMediaModel::KIND_PHOTO));
        $this->assertFileDoesNotExist(dirname($expected));

        $this->assertNotNull($this->storage->storePathFor(4821, FamilyMediaModel::KIND_PHOTO, true));
        $this->assertDirectoryExists(dirname($expected));

        $this->assertNull($this->storage->storePathFor(0, FamilyMediaModel::KIND_PHOTO));
        $this->assertNull($this->storage->storePathFor(4821, 'badge'));
        $this->assertNull($this->storageFor('')->storePathFor(4821, FamilyMediaModel::KIND_PHOTO));
    }

    public function testMoveToStoreRelocatesTheInboxFileLeavingExactlyOneCopy(): void
    {
        $this->writeJpeg('019186.photo.jpg', 100, 100);
        $source = $this->inboxPath('019186.photo.jpg');
        $hash = hash_file('sha256', $source);

        $this->assertTrue($this->storage->moveToStore('019186.photo.jpg', 4821, FamilyMediaModel::KIND_PHOTO));

        $destination = $this->storage->storePathFor(4821, FamilyMediaModel::KIND_PHOTO);
        $this->assertFileExists($destination);
        $this->assertFileDoesNotExist($source);
        $this->assertSame($hash, hash_file('sha256', $destination));
        $this->assertSame(0640, fileperms($destination) & 0777);

        // A move whose inbox file is gone (already moved) is a harmless false.
        $this->assertFalse($this->storage->moveToStore('019186.photo.jpg', 4821, FamilyMediaModel::KIND_PHOTO));
    }

    public function testStoredMetadataAndInspectStoredReadTheStoreZoneOnly(): void
    {
        $this->writeJpeg('019186.photo.jpg', 100, 100);
        $this->storage->moveToStore('019186.photo.jpg', 4821, FamilyMediaModel::KIND_PHOTO);

        $stat = $this->storage->storedMetadata(4821, FamilyMediaModel::KIND_PHOTO);
        $this->assertIsArray($stat);
        $this->assertGreaterThan(0, $stat['byte_size']);

        $inspection = $this->storage->inspectStored(4821, FamilyMediaModel::KIND_PHOTO, '019186.photo.jpg');
        $this->assertIsArray($inspection);
        $this->assertSame('019186.photo.jpg', $inspection['source_filename']);
        $this->assertSame(FamilyMediaModel::KIND_PHOTO, $inspection['kind']);
        $this->assertSame('image/jpeg', $inspection['mime']);

        // A head with nothing stored answers null for both.
        $this->assertNull($this->storage->storedMetadata(9999, FamilyMediaModel::KIND_PHOTO));
        $this->assertNull($this->storage->inspectStored(9999, FamilyMediaModel::KIND_PHOTO, 'x.jpg'));
    }

    public function testAdoptLegacyRootFilesMovesFlatLayoutDropsIntoTheInbox(): void
    {
        $this->writeJpegAt($this->root . DIRECTORY_SEPARATOR . '019186.photo.jpg', 100, 100, [255, 0, 0]);
        $this->writePngAt($this->root . DIRECTORY_SEPARATOR . '019187.signature.png', 100, 100);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'junk.txt', 'not canonical');

        $moved = $this->storage->adoptLegacyRootFiles();

        $this->assertSame(2, $moved);
        $this->assertFileExists($this->inboxPath('019186.photo.jpg'));
        $this->assertFileExists($this->inboxPath('019187.signature.png'));
        $this->assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . '019186.photo.jpg');
        // Non-canonical entries stay where they are; adoption ignores them.
        $this->assertFileExists($this->root . DIRECTORY_SEPARATOR . 'junk.txt');
    }

    public function testConfiguredRootIsOutsidePublicAndWritableProjectDirectories(): void
    {
        $configuredRoot = $this->storage->root();

        $this->assertNotNull($configuredRoot);
        $this->assertFalse(str_starts_with($configuredRoot . DIRECTORY_SEPARATOR, realpath(FCPATH) . DIRECTORY_SEPARATOR));
        $this->assertFalse(str_starts_with($configuredRoot . DIRECTORY_SEPARATOR, realpath(WRITEPATH) . DIRECTORY_SEPARATOR));
    }

    public function testRootIsNullOnlyWhenTheConfigurationIsUnusable(): void
    {
        $this->assertNotNull($this->storage->root());
        $this->assertSame(realpath($this->root), $this->storage->root());
        $this->assertNull($this->storageFor('')->root());
        $this->assertNull($this->storageFor(FCPATH . '/definitely-not-here')->root());
        $this->assertNull($this->storageFor(FCPATH)->root());
        $this->assertNull($this->storageFor(WRITEPATH)->root());
        $this->assertNull($this->storageFor(ROOTPATH)->root());
    }

    public function testItAtomicallyReplacesAnUploadedFile(): void
    {
        $destination = $this->writeJpeg('019186.photo.jpg', 100, 100, [255, 0, 0]);
        $oldHash = hash_file('sha256', $destination);
        $upload = tempnam(sys_get_temp_dir(), 'family-media-upload-');
        $this->writeJpegAt($upload, 100, 100, [0, 0, 255]);

        $inspection = $this->storage->storeUpload(
            new LocalUploadedFile($upload, 'ignored.jpg'),
            19186,
            FamilyMediaModel::KIND_PHOTO,
        );

        $this->assertSame('019186.photo.jpg', $inspection['source_filename']);
        $this->assertNotSame($oldHash, $inspection['content_sha256']);
        $this->assertSame($inspection['content_sha256'], hash_file('sha256', $destination));
        $this->assertFileExists($upload);
        $this->assertSame([], glob($this->inboxPath('.*.tmp-*')));
        @unlink($upload);
    }

    public function testStoredUploadIsLeftGroupReadableSoAWorkerInTheSharedGroupCanReadIt(): void
    {
        $upload = tempnam(sys_get_temp_dir(), 'family-media-upload-');
        $this->writeJpegAt($upload, 100, 100, [0, 0, 255]);
        $destination = $this->inboxPath('019195.photo.jpg');

        try {
            $this->storage->storeUpload(
                new LocalUploadedFile($upload, 'ignored.jpg'),
                19195,
                FamilyMediaModel::KIND_PHOTO,
            );

            $this->assertFileExists($destination);
            $this->assertSame(0640, fileperms($destination) & 0777);
        } finally {
            @unlink($upload);
        }
    }

    public function testItRejectsEmptyAndErroredUploads(): void
    {
        $empty = tempnam(sys_get_temp_dir(), 'family-media-upload-');
        $error = tempnam(sys_get_temp_dir(), 'family-media-upload-');
        file_put_contents($empty, '');

        $this->assertSame([], $this->storage->storeUpload(new LocalUploadedFile($empty, 'empty.jpg'), 19186, FamilyMediaModel::KIND_PHOTO));
        $this->assertSame([], $this->storage->storeUpload(new LocalUploadedFile($error, 'error.jpg', null, null, UPLOAD_ERR_PARTIAL), 19186, FamilyMediaModel::KIND_PHOTO));

        @unlink($empty);
        @unlink($error);
    }

    private function storageFor(string $root, ?int $photoMaxBytes = null): FamilyMediaStorage
    {
        $settings = new FamilyMediaSettings();
        $settings->root = $root;
        if ($photoMaxBytes !== null) {
            $settings->photoMaxBytes = $photoMaxBytes;
        }

        return new FamilyMediaStorage($settings);
    }

    private function inboxPath(string $filename): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'inbox' . DIRECTORY_SEPARATOR . $filename;
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

    private function writePng(string $filename, int $width, int $height): string
    {
        return $this->writePngAt($this->inboxPath($filename), $width, $height);
    }

    private function writePngAt(string $path, int $width, int $height): string
    {
        @mkdir(dirname($path), 0770, true);
        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
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

/** Test double that treats a local temporary file as an HTTP upload. */
final class LocalUploadedFile extends UploadedFile
{
    public function __construct(
        string $path,
        string $originalName,
        ?string $mimeType = null,
        ?int $size = null,
        ?int $error = null,
        ?string $clientPath = null,
    ) {
        parent::__construct($path, $originalName, $mimeType, $size, $error, $clientPath);
    }

    public function isValid(): bool
    {
        return $this->getError() === UPLOAD_ERR_OK && is_file($this->getTempName());
    }
}
