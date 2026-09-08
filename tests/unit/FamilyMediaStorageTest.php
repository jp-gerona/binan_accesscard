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
        foreach ((array) glob($this->root . DIRECTORY_SEPARATOR . '*') as $file) {
            @chmod((string) $file, 0600);
            @unlink((string) $file);
        }
        @rmdir($this->root);

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
        $outside = tempnam(sys_get_temp_dir(), 'family-media-outside-');
        file_put_contents($outside, 'outside');
        symlink($outside, $this->root . DIRECTORY_SEPARATOR . '019186.photo.jpg');

        $this->assertNull($this->storage->inspect('../019186.photo.jpg'));
        $this->assertNull($this->storage->inspect('019186.photo.jpg'));
        $this->assertNull($this->storage->pathFor('../019186.photo.jpg'));

        @unlink($outside);
    }

    public function testItRejectsEmptyAndProjectStorageRoots(): void
    {
        $this->assertNull($this->storageFor('')->pathFor('019186.photo.jpg'));
        $this->assertNull($this->storageFor(FCPATH)->pathFor('019186.photo.jpg'));
        $this->assertNull($this->storageFor(WRITEPATH)->pathFor('019186.photo.jpg'));
        $this->assertNull($this->storageFor(ROOTPATH)->pathFor('019186.photo.jpg'));
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
        $destination = $ancestor . DIRECTORY_SEPARATOR . $filename;
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

    public function testItScansOnlyAcceptedFiles(): void
    {
        $this->writeJpeg('019186.photo.jpg', 100, 100);
        $this->writeJpeg('19186.photo.jpg', 100, 100);
        $this->writePng('019187.signature.png', 100, 100);

        $files = iterator_to_array($this->storage->scan());

        $this->assertSame(['019186.photo.jpg', '019187.signature.png'], array_column($files, 'source_filename'));
    }

    public function testCandidateFilenamesIgnoresNonCanonicalEntriesTempsDotfilesDirsAndLinks(): void
    {
        $this->writeJpeg('019186.photo.jpg', 100, 100);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . '.DS_Store', 'junk');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . '.019187.photo.jpg.tmp-abc', 'partial');
        $this->writeJpegAt($this->root . DIRECTORY_SEPARATOR . '19186.photo.jpg', 100, 100, [255, 255, 255]);
        mkdir($this->root . DIRECTORY_SEPARATOR . 'subdir', 0700);
        $outside = tempnam(sys_get_temp_dir(), 'family-media-outside-');
        $this->assertNotFalse($outside);
        symlink($outside, $this->root . DIRECTORY_SEPARATOR . '019999.photo.jpg');

        // Only the canonical real file is a candidate; nothing else is judged invalid.
        $this->assertSame(['019186.photo.jpg'], $this->storage->candidateFilenames());

        @unlink($outside);
        @rmdir($this->root . DIRECTORY_SEPARATOR . 'subdir');
    }

    public function testCandidateFilenamesThrowsWhenTheRootCannotBeListed(): void
    {
        chmod($this->root, 0000);

        try {
            if (@scandir($this->root) !== false) {
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
        $this->assertSame([], glob($this->root . DIRECTORY_SEPARATOR . '.*.tmp-*'));
        @unlink($upload);
    }

    public function testStoredUploadIsLeftGroupReadableSoAWorkerInTheSharedGroupCanReadIt(): void
    {
        $upload = tempnam(sys_get_temp_dir(), 'family-media-upload-');
        $this->writeJpegAt($upload, 100, 100, [0, 0, 255]);
        $destination = $this->root . DIRECTORY_SEPARATOR . '019195.photo.jpg';

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

    private function writeJpeg(string $filename, int $width, int $height, array $colour = [255, 255, 255]): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $filename;
        $this->writeJpegAt($path, $width, $height, $colour);

        return $path;
    }

    private function writeJpegAt(string $path, int $width, int $height, array $colour): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$colour));
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }

    private function writePng(string $filename, int $width, int $height): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $filename;
        $image = imagecreatetruecolor($width, $height);
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
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
