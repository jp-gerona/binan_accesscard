<?php

namespace CodeIgniter\HTTP\Files {
    /** Lets CI4 feature requests treat registered local fixtures as HTTP uploads. */
    function is_uploaded_file(string $filename): bool
    {
        return \Tests\Unit\FeatureUploadFiles::contains($filename) || \is_uploaded_file($filename);
    }
}

namespace Tests\Unit {

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\FamilyMediaSettings;
use Tests\Support\Database\DumpSchema;

final class FeatureUploadFiles
{
    /** @var array<string, true> */
    private static array $paths = [];

    public static function add(string $path): void
    {
        self::$paths[$path] = true;
    }

    public static function clear(): void
    {
        self::$paths = [];
    }

    public static function contains(string $path): bool
    {
        return isset(self::$paths[$path]);
    }
}

final class FamilyMediaUploadTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $root;
    private string $originalRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        DumpSchema::create($db);
        $this->configureSqliteAutoIncrementLookup();
        $db->table('barangay')->insert(['barangayID' => 3, 'name' => 'CANLALAY']);

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'family-media-feature-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);
        $settings = config(FamilyMediaSettings::class);
        $this->originalRoot = $settings->root;
        $settings->root = $this->root;
    }

    protected function tearDown(): void
    {
        service('superglobals')->setFilesArray([]);
        FeatureUploadFiles::clear();
        config(FamilyMediaSettings::class)->root = $this->originalRoot;
        array_map('unlink', glob($this->root . DIRECTORY_SEPARATOR . '*') ?: []);
        rmdir($this->root);
        DumpSchema::drop(db_connect());
        parent::tearDown();
    }

    public function testFamilySaveSucceedsWhenOptionalPhotoUploadFails(): void
    {
        $upload = tempnam(sys_get_temp_dir(), 'family-media-text-');
        file_put_contents($upload, 'not an image');

        $response = $this->postWithUploads(19186, ['head_photo' => $this->upload($upload, 'portrait.txt')]);

        $this->assertSame(200, $response->response()->getStatusCode(), $response->getBody());
        $json = json_decode((string) $response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('success', $json['status']);
        @unlink($upload);
    }

    public function testEncoderSeesOptionalMultipartInputsOnTheEntryForm(): void
    {
        $response = $this->withSession($this->encoderSession())->get('records/entry');

        $response->assertStatus(200);
        $html = (string) $response->response()->getBody();
        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="head_photo"', $html);
        $this->assertStringContainsString('name="head_signature"', $html);
    }

    public function testOptionalUploadWarningIsVisibleInTheRedirectedDashboardView(): void
    {
        $warning = 'The optional portrait photo could not be uploaded.';
        session()->setFlashdata('warning', $warning);

        $html = view('layout', [
            'activePage' => 'records',
            'role'       => 'encoder',
            'bodyView'   => 'Family/profile-view',
            'bodyData'   => [],
        ]);

        $this->assertStringContainsString('alert-warning', $html);
        $this->assertStringContainsString($warning, $html);
    }

    public function testCreateStoresJpegAndPngUnderCanonicalNames(): void
    {
        $photo = $this->imageUpload('jpg', [255, 0, 0]);
        $signature = $this->imageUpload('png', [0, 0, 0]);

        $response = $this->postWithUploads(19187, [
            'head_photo' => $photo,
            'head_signature' => $signature,
        ]);

        $this->assertSame(200, $response->response()->getStatusCode(), $response->getBody());
        $json = json_decode((string) $response->getJSON(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFileExists($this->root . DIRECTORY_SEPARATOR . '019187.photo.jpg');
        $this->assertFileExists($this->root . DIRECTORY_SEPARATOR . '019187.signature.png');
        $this->assertSame(1, db_connect()->table('job_queue')->where('type', 'media_reconcile')->countAllResults());
    }

    public function testEditReplacesAnUploadedPhotoAndOmittedMediaStaysUntouched(): void
    {
        $this->seedHead(7, 19188);
        $existing = $this->root . DIRECTORY_SEPARATOR . '019188.signature.png';
        $this->writeImage($existing, 'png', [0, 0, 0]);
        db_connect()->table('family_media')->insert([
            'headID' => 7,
            'kind' => 'signature',
            'source_control_no' => 19188,
            'source_filename' => '019188.signature.png',
            'media_url' => 'records/7/media/signature',
            'state' => 'linked',
        ]);

        $first = $this->imageUpload('jpg', [255, 0, 0]);
        $firstResponse = $this->postUpdateWithUploads(7, 19188, ['head_photo' => $first]);
        $this->assertSame(200, $firstResponse->response()->getStatusCode(), $firstResponse->getBody());
        $photoPath = $this->root . DIRECTORY_SEPARATOR . '019188.photo.jpg';
        $firstHash = hash_file('sha256', $photoPath);

        $replacement = $this->imageUpload('jpg', [0, 0, 255]);
        $this->postUpdateWithUploads(7, 19188, ['head_photo' => $replacement])->assertStatus(200);

        $this->assertNotSame($firstHash, hash_file('sha256', $photoPath));
        $this->assertSame('linked', db_connect()->table('family_media')
            ->where('headID', 7)->where('kind', 'signature')->get()->getRowArray()['state']);

        $this->postUpdateWithUploads(7, 19188, [])->assertStatus(200);
        $this->assertFileExists($photoPath);
        $this->assertFileExists($existing);
    }

    /**
     * FamilyRecordWriter reads MySQL's information_schema to reserve a self-referencing
     * head ID. Supply that production query's tiny contract when this feature suite uses
     * CI4's in-memory SQLite connection.
     */
    private function configureSqliteAutoIncrementLookup(): void
    {
        $db = db_connect();
        if ($db->DBDriver !== 'SQLite3') {
            return;
        }

        $db->connID->createFunction('DATABASE', static fn (): string => 'tests');
        $databases = $db->query('PRAGMA database_list')->getResultArray();
        foreach ($databases as $database) {
            if (($database['name'] ?? '') === 'information_schema') {
                return;
            }
        }

        $db->query("ATTACH DATABASE ':memory:' AS information_schema");
        $db->query('CREATE TABLE information_schema.TABLES (TABLE_SCHEMA TEXT, TABLE_NAME TEXT, AUTO_INCREMENT INTEGER)');
        $db->query("INSERT INTO information_schema.TABLES (TABLE_SCHEMA, TABLE_NAME, AUTO_INCREMENT) VALUES ('tests', 'member', 1)");
    }

    private function postWithUploads(int $controlNo, array $uploads)
    {
        service('superglobals')->setFilesArray($uploads);

        return $this->withSession($this->encoderSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('records', $this->familyPayload($controlNo));
    }

    private function postUpdateWithUploads(int $headId, int $controlNo, array $uploads)
    {
        service('superglobals')->setFilesArray($uploads);

        return $this->withSession($this->encoderSession())
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post('records/' . $headId . '/update', $this->familyPayload($controlNo));
    }

    private function familyPayload(int $controlNo): array
    {
        return [
            '_form_end' => '1',
            'members_meta_count' => '0',
            'head_firstname' => 'JUAN',
            'head_middlename' => '',
            'head_lastname' => 'DELA CRUZ',
            'head_birthday' => '1980-01-01',
            'head_sex' => 'MALE',
            'head_civilstatus' => 'SINGLE',
            'head_education' => 'COLLEGE',
            'head_job' => 'LABORER',
            'head_salary' => '5000',
            'head_address' => 'PUROK 1',
            'head_barangay' => 'CANLALAY',
            'qr_control_no' => $controlNo,
        ];
    }

    private function encoderSession(): array
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username' => 'media-encoder-' . bin2hex(random_bytes(4)),
            'password' => 'x',
            'account_level' => 'encoder',
        ]);

        return ['is_logged_in' => true, 'role' => 'encoder', 'user_id' => (int) $db->insertID()];
    }

    private function seedHead(int $headId, int $controlNo): void
    {
        $db = db_connect();
        $db->table('member')->insert([
            'memberID' => $headId, 'headID' => $headId, 'lastname' => 'DELA CRUZ',
            'firstname' => 'JUAN', 'middlename' => '', 'salary' => 5000, 'barangayID' => 3,
        ]);
        $db->table('qr_control')->insert(['control_no' => $controlNo, 'headID' => $headId]);
    }

    private function imageUpload(string $format, array $colour): array
    {
        $path = tempnam(sys_get_temp_dir(), 'family-media-image-');
        $this->writeImage($path, $format, $colour);

        return $this->upload($path, 'upload.' . $format);
    }

    private function upload(string $path, string $name): array
    {
        FeatureUploadFiles::add($path);

        return [
            'name' => $name,
            'type' => 'application/octet-stream',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => filesize($path),
        ];
    }

    private function writeImage(string $path, string $format, array $colour): void
    {
        $image = imagecreatetruecolor(20, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, ...$colour));
        if ($format === 'jpg') {
            imagejpeg($image, $path, 90);
        } else {
            imagepng($image, $path);
        }
        imagedestroy($image);
    }
}
}
