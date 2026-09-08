<?php

namespace Tests\Unit;

use App\Libraries\FamilyMediaStorage;
use App\Models\Families\FamilyMediaModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\FamilyMediaSettings;

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

    public function testItScansOnlyAcceptedFiles(): void
    {
        $this->writeJpeg('019186.photo.jpg', 100, 100);
        $this->writeJpeg('19186.photo.jpg', 100, 100);
        $this->writePng('019187.signature.png', 100, 100);

        $files = iterator_to_array($this->storage->scan());

        $this->assertSame(['019186.photo.jpg', '019187.signature.png'], array_column($files, 'source_filename'));
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
