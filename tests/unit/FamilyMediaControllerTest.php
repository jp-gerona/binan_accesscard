<?php

namespace Tests\Unit;

use App\Controllers\Families\FamilyMediaController;
use App\Models\Families\FamilyMediaModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use Tests\Support\Database\DumpSchema;
use Tests\Support\Database\ReferentialFixture;

/**
 * Feature coverage for the private media endpoint: the `records-media` filter,
 * the streaming controller, and the profile page's media panel. The storage root
 * points at a temporary directory that actually holds the seeded image bytes, so
 * the whole path is exercised rather than mocked.
 */
final class FamilyMediaControllerTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        DumpSchema::create(db_connect());

        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'family-media-serve-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0700, true);

        // The controller builds `new FamilyMediaStorage()` from the shared config,
        // so the test points that config at this temporary root for the request.
        $settings = config('FamilyMediaSettings');
        $settings->root = $this->root;
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        $settings = config('FamilyMediaSettings');
        $settings->root = '';

        DumpSchema::drop(db_connect());
        parent::tearDown();
    }

    public function testEncoderStreamsALinkedPortraitButViewerGets404(): void
    {
        $headId = $this->seedLinkedPhoto();

        $encoder = $this->withSession($this->sessionFor('encoder'))->get('records/' . $headId . '/media/photo');
        $encoder->assertStatus(200);
        $encoder->assertHeader('Content-Type', 'image/jpeg');

        $this->withSession($this->sessionFor('viewer'))->get('records/' . $headId . '/media/photo')
            ->assertStatus(404);
    }

    public function testSignatureStreamsTheExpectedMime(): void
    {
        $headId = $this->seedLinkedSignature();

        $response = $this->withSession($this->sessionFor('encoder'))->get('records/' . $headId . '/media/signature');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'image/png');
        $this->assertSame(
            hash('sha256', (string) file_get_contents($this->storePath($headId, 'signature'))),
            hash('sha256', (string) $response->response()->getBody()),
            'The streamed body must be the stored file bytes.'
        );
    }

    public function testUnknownKindGets404(): void
    {
        $headId = $this->seedLinkedPhoto();

        $this->withSession($this->sessionFor('encoder'))->get('records/' . $headId . '/media/family')
            ->assertStatus(404);
    }

    public function testMissingSourceFileGets404(): void
    {
        $headId = 119188;
        ReferentialFixture::heads(db_connect(), [$headId]);
        // A linked registry row whose source file is not on disk.
        $this->linkRow($headId, FamilyMediaModel::KIND_PHOTO, '019188.photo.jpg', 19188);

        $this->withSession($this->sessionFor('encoder'))->get('records/' . $headId . '/media/photo')
            ->assertStatus(404);
    }

    public function testUnexpectedImageMimeGets404(): void
    {
        $headId = 119190;
        ReferentialFixture::heads(db_connect(), [$headId]);
        $this->writeStoredPng($headId, FamilyMediaModel::KIND_PHOTO);
        $this->linkRow($headId, FamilyMediaModel::KIND_PHOTO, '019190.photo.jpg', 19190);

        $this->withSession($this->sessionFor('encoder'))->get('records/' . $headId . '/media/photo')
            ->assertStatus(404);
    }

    public function testCorruptLinkedRowForANonHeadGets404(): void
    {
        $headId = 119191;
        $memberId = $headId + 1;
        ReferentialFixture::heads(db_connect(), [$headId]);
        db_connect()->table('member')->insert([
            'memberID'   => $memberId,
            'headID'     => $headId,
            'firstname'  => 'MEMBER',
            'middlename' => '',
            'lastname'   => 'FIXTURE',
        ]);
        $this->writeStoredJpeg($headId, FamilyMediaModel::KIND_PHOTO);
        $this->linkRow($headId, FamilyMediaModel::KIND_PHOTO, '019191.photo.jpg', 19191);
        db_connect()->table('family_media')
            ->where('source_filename', '019191.photo.jpg')
            ->update([
                'headID'    => $memberId,
                'media_url' => 'records/' . $memberId . '/media/photo',
            ]);

        $this->withSession($this->sessionFor('encoder'))->get('records/' . $memberId . '/media/photo')
            ->assertStatus(404);
    }

    public function testNonHeadGets404(): void
    {
        $headId = 119189;
        ReferentialFixture::heads(db_connect(), [$headId]);
        $this->writeStoredJpeg($headId, FamilyMediaModel::KIND_PHOTO);
        $this->linkRow($headId, FamilyMediaModel::KIND_PHOTO, '019189.photo.jpg', 19189);

        // A relative of the head: linked rows are only ever attached to heads, so
        // this member id must answer identically to an unknown id.
        $memberId = $headId + 1;
        db_connect()->table('member')->insert([
            'memberID'   => $memberId,
            'headID'     => $headId,
            'firstname'  => 'MEMBER',
            'middlename' => '',
            'lastname'   => 'FIXTURE',
        ]);

        $this->withSession($this->sessionFor('encoder'))->get('records/' . $memberId . '/media/photo')
            ->assertStatus(404);
    }

    public function testTraversalishKindIsRejectedByTheController(): void
    {
        // The `(:alpha)` route placeholder can never admit a traversal kind, and
        // this is the defense behind it: the controller accepts only the two
        // registry kinds and never a path from the request.
        $controller = new FamilyMediaController();
        $controller->initController(Services::request(), Services::response(), Services::logger());

        $this->assertSame(404, $controller->show(7, '../photo')->getStatusCode());
        $this->assertSame(404, $controller->show(7, 'photo/../../etc/passwd')->getStatusCode());
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $headId = $this->seedLinkedPhoto();

        $response = $this->get('records/' . $headId . '/media/photo');

        $this->assertSame(302, $response->response()->getStatusCode());
        $this->assertStringContainsString('/login', $response->response()->getHeaderLine('Location'));
    }

    public function testViewerProfileOmitsMediaUrls(): void
    {
        $headId = $this->seedLinkedPhoto();

        $response = $this->withSession($this->sessionFor('viewer'))->get('records/' . $headId);
        $response->assertStatus(200);

        $html = (string) $response->response()->getBody();
        $this->assertStringNotContainsString('Family Media', $html);
        $this->assertStringNotContainsString('records/' . $headId . '/media/photo', $html);
    }

    public function testEncoderProfileRendersPrivateMediaUrls(): void
    {
        $headId = $this->seedLinkedPhoto();

        $response = $this->withSession($this->sessionFor('encoder'))->get('records/' . $headId);
        $response->assertStatus(200);

        $html = html_entity_decode((string) $response->response()->getBody(), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('Family Media', $html);
        $this->assertStringContainsString('records/' . $headId . '/media/photo', $html);

        // The portrait image carries descriptive alt text naming the head.
        $this->assertMatchesRegularExpression(
            '/<img[^>]*src="[^"]*records\/' . $headId . '\/media\/photo[^"]*"[^>]*alt="Portrait of HEAD' . $headId . ' FIXTURE"/',
            $html
        );
    }

    /**
     * A head with a linked photo registry row plus real photo bytes in the store.
     */
    private function seedLinkedPhoto(int $headId = 119186): int
    {
        ReferentialFixture::heads(db_connect(), [$headId]);
        $this->writeStoredJpeg($headId, FamilyMediaModel::KIND_PHOTO);
        $this->linkRow($headId, FamilyMediaModel::KIND_PHOTO, '019186.photo.jpg', 19186);

        return $headId;
    }

    /**
     * A head with a linked signature registry row plus real signature bytes in the store.
     */
    private function seedLinkedSignature(int $headId = 119187): int
    {
        ReferentialFixture::heads(db_connect(), [$headId]);
        $this->writeStoredPng($headId, FamilyMediaModel::KIND_SIGNATURE);
        $this->linkRow($headId, FamilyMediaModel::KIND_SIGNATURE, '019187.signature.png', 19187);

        return $headId;
    }

    private function linkRow(int $headId, string $kind, string $filename, int $controlNo): void
    {
        $model = new FamilyMediaModel();
        $mediaId = $model->upsertPending([
            'source_control_no' => $controlNo,
            'source_filename'   => $filename,
            'kind'              => $kind,
        ]);
        $this->assertGreaterThan(0, $mediaId);
        $path = $this->storePath($headId, $kind);
        $this->assertTrue($model->link(
            $mediaId,
            $headId,
            'records/' . $headId . '/media/' . $kind,
            ['content_sha256' => str_repeat('a', 64), 'byte_size' => is_file($path) ? (int) filesize($path) : 0, 'source_modified_at' => date('Y-m-d H:i:s')]
        ));
    }

    /** @return array{is_logged_in: true, role: string, user_id: int} */
    private function sessionFor(string $role): array
    {
        return [
            'is_logged_in' => true,
            'role'         => $role,
            'user_id'      => $this->user($role),
        ];
    }

    /** A users row the RoleNavFilter sessionUserExists() check can see. */
    private function user(string $level): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'      => $level . '-media-fixture',
            'password'      => 'x',
            'account_level' => $level,
        ]);

        return (int) $db->insertID();
    }

    private function storePath(int $headId, string $kind): string
    {
        return $this->root . DIRECTORY_SEPARATOR . 'store' . DIRECTORY_SEPARATOR . intdiv($headId, 100)
            . DIRECTORY_SEPARATOR . $headId . DIRECTORY_SEPARATOR . $kind . '.' . ($kind === 'photo' ? 'jpg' : 'png');
    }

    private function writeStoredJpeg(int $headId, string $kind): void
    {
        $path = $this->storePath($headId, $kind);
        @mkdir(dirname($path), 0770, true);
        $image = imagecreatetruecolor(640, 480);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagejpeg($image, $path, 90);
        imagedestroy($image);
    }

    private function writeStoredPng(int $headId, string $kind): void
    {
        $path = $this->storePath($headId, $kind);
        @mkdir(dirname($path), 0770, true);
        $image = imagecreatetruecolor(200, 100);
        imagepng($image, $path);
        imagedestroy($image);
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
