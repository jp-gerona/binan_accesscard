<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Database\DumpSchema;

/**
 * Feature coverage for FamilyController::profile() through the real route,
 * session filters, and controller - not just Family/profile.php rendered in
 * isolation, which is all FamilyProfilePageTest exercises. This is the surface
 * fix round 1 found broken: the missing truncation sentinel, the missing JS
 * init marker, the Salary/salary casing miss, and the active-only option list.
 *
 * Schema comes from the dump (Tests\Support\Database\DumpSchema), so the
 * `member`/`member_services`/`qr_control`/`sector`/`users` tables this needs
 * carry the column set production runs on.
 */
final class FamilyControllerProfileTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        DumpSchema::create(db_connect());
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        DumpSchema::drop(db_connect());
    }

    public function testEditPageIsEditableSavableAndKeepsArchivedAssignments(): void
    {
        $db = db_connect();

        $userId = $this->user('administrator');

        // Archived (dt_deleted set) - absent from SectorModel::getActive()'s list -
        // but still assigned to the head below. getViewData() would silently drop
        // it from the rendered options; getViewDataForEdit() must grandfather it in.
        $db->table('sector')->insert([
            'sectorID' => 99, 'shortcode' => 'ARC', 'name' => 'Archived Sector',
            'description' => 'x', 'dt_deleted' => date('Y-m-d H:i:s'),
        ]);

        // The head's barangay is a real foreign key since V22, so the barangay
        // has to exist before the member pointing at it does.
        $db->table('barangay')->insert(['barangayID' => 3, 'name' => 'CANLALAY']);

        $db->table('member')->insert([
            'memberID'  => 7,
            'lastname'  => 'DELA CRUZ',
            'firstname' => 'JUAN',
            'middlename' => '',
            'headID'    => 7,
            'salary'    => 8000,
            'address'   => 'PUROK 1',
            'barangayID' => 3,
        ]);
        $db->table('member_sectors')->insert(['memberID' => 7, 'sectorID' => 99]);

        $result = $this->withSession([
            'is_logged_in' => true,
            'role'         => 'admin',
            'user_id'      => $userId,
        ])->get('records/7/edit');

        $result->assertStatus(200);
        $html = (string) $result->response()->getBody();

        // Critical 1: the truncation sentinel FamilyController::submissionWasTruncated()
        // requires on every save - omitting it 422s every Save from this page.
        $this->assertStringContainsString('name="_form_end" value="1"', $html);
        $this->assertStringContainsString('data-members-count', $html);

        // Critical 2: the marker manage-family-modal.js's initFamilyEntryPage() looks
        // for to wire up member-row toggling, Other-selects, and the AJAX submit
        // handler on a page with no control-number gate.
        $this->assertStringContainsString('data-family-entry-form', $html);

        // Important 1: member.Salary (capital S) must resolve to the lowercase
        // head_salary field name the renderer looks up, or the required select
        // renders with nothing selected and the browser blocks Save.
        $this->assertMatchesRegularExpression(
            '/<select[^>]*name="head_salary"[^>]*>.*?<option value="8000"[^>]*selected/s',
            $html
        );

        // Important 2: the archived-but-assigned sector must still render, checked -
        // proof the page used getViewDataForEdit() (which grandfathers it in), not
        // getViewData() (active-only, which would drop it and delete it on save).
        $this->assertMatchesRegularExpression('/value="99"[^>]*checked/', $html);
    }

    public function testViewerCannotReachTheUpdateRoute(): void
    {
        $db = db_connect();

        $userId = $this->user('viewer');

        $db->table('member')->insert([
            'memberID' => 7, 'lastname' => 'DELA CRUZ', 'firstname' => 'JUAN',
            'middlename' => '', 'headID' => 7, 'salary' => 0,
        ]);

        $session = [
            'is_logged_in' => true,
            'role'         => 'viewer',
            'user_id'      => $userId,
        ];

        $profile = $this->withSession($session)->get('records/7');
        $profile->assertStatus(200);

        $html = (string) $profile->response()->getBody();
        $this->assertStringNotContainsString('data-family-save', $html, 'A Viewer must not get a Save button.');
        $this->assertStringNotContainsString('<form', $html, 'The read view prints the record; it carries no form at all.');

        // records/{id} is read-only for everyone, so the edit page is a separate
        // route the manifest keeps off a Viewer entirely.
        $edit = $this->withSession($session)->get('records/7/edit');
        $this->assertNotSame(200, $edit->response()->getStatusCode());

        // The manifest keeps records-update off Viewer, so the update route itself
        // must reject a Viewer, independent of what the profile page renders.
        $update = $this->withSession($session)->post('records/7/update', []);
        $this->assertNotSame(200, $update->response()->getStatusCode());
    }

    /**
     * A user row at the given account level. username and password are the two
     * columns the dump requires of every account; nothing here reads them.
     */
    private function user(string $level): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'      => $level . '-fixture',
            'password'      => 'x',
            'account_level' => $level,
        ]);

        return (int) $db->insertID();
    }

    /** @return array{is_logged_in: true, role: string, user_id: int} */
    private function encoderSession(): array
    {
        return [
            'is_logged_in' => true,
            'role'         => 'encoder',
            'user_id'      => $this->user('encoder'),
        ];
    }

    public function testAnAvailableNumberCarriesAQrPreview(): void
    {
        $response = $this->withSession($this->encoderSession())
            ->get('records/qr-check?control_no=999999&head_id=0');

        $json = json_decode($response->getJSON(), true);

        $this->assertTrue($json['available']);
        $this->assertArrayHasKey('qr', $json);
        $this->assertStringStartsWith('data:image/png;base64,', $json['qr']);
    }

    public function testATakenNumberCarriesNoQrPreview(): void
    {
        $db = db_connect();
        $db->table('member')->insert([
            'memberID' => 7, 'lastname' => 'DELA CRUZ', 'firstname' => 'JUAN',
            'middlename' => '', 'headID' => 7, 'salary' => 0,
        ]);
        $db->table('qr_control')->insert(['control_no' => 12345, 'headID' => 7]);

        // A number already issued may have a card printed against it; rendering a
        // code for it here would suggest otherwise.
        $response = $this->withSession($this->encoderSession())
            ->get('records/qr-check?control_no=12345&head_id=0');

        $json = json_decode($response->getJSON(), true);

        $this->assertFalse($json['available']);
        $this->assertArrayNotHasKey('qr', $json);
    }

    public function testAnInvalidNumberCarriesNoQrPreview(): void
    {
        $response = $this->withSession($this->encoderSession())
            ->get('records/qr-check?control_no=abc&head_id=0');

        $json = json_decode($response->getJSON(), true);

        $this->assertArrayNotHasKey('qr', $json);
    }

    public function testViewerProfileOmitsLinkedMediaUrls(): void
    {
        $db = db_connect();

        $userId = $this->user('viewer');

        $db->table('member')->insert([
            'memberID' => 7, 'lastname' => 'DELA CRUZ', 'firstname' => 'JUAN',
            'middlename' => '', 'headID' => 7, 'salary' => 0,
        ]);
        $this->linkMedia(7, 'photo', '019186.photo.jpg', 19186);

        $session = [
            'is_logged_in' => true,
            'role'         => 'viewer',
            'user_id'      => $userId,
        ];

        $profile = $this->withSession($session)->get('records/7');
        $profile->assertStatus(200);

        $html = (string) $profile->response()->getBody();
        $this->assertStringNotContainsString('Family Media', $html, 'A Viewer must not see the media panel.');
        $this->assertStringNotContainsString('records/7/media/photo', $html, 'A Viewer must not receive private media URLs.');
    }

    public function testEncoderProfileRendersLinkedMediaUrls(): void
    {
        $db = db_connect();

        $userId = $this->user('encoder');

        $db->table('member')->insert([
            'memberID' => 7, 'lastname' => 'DELA CRUZ', 'firstname' => 'JUAN',
            'middlename' => '', 'headID' => 7, 'salary' => 0,
        ]);
        $this->linkMedia(7, 'photo', '019186.photo.jpg', 19186);

        $session = [
            'is_logged_in' => true,
            'role'         => 'encoder',
            'user_id'      => $userId,
        ];

        $profile = $this->withSession($session)->get('records/7');
        $profile->assertStatus(200);

        $html = html_entity_decode((string) $profile->response()->getBody(), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('Family Media', $html);
        $this->assertStringContainsString('records/7/media/photo', $html);
        $this->assertMatchesRegularExpression(
            '/<img[^>]*src="[^"]*records\/7\/media\/photo[^"]*"[^>]*alt="Portrait of JUAN DELA CRUZ"/',
            $html
        );

        // Editors never see a raw storage path, only the private route URL.
        $this->assertStringNotContainsString('019186.photo.jpg', $html);
    }

    public function testProfileMediaUsesCssClassesForLabelsAndImages(): void
    {
        $source = file_get_contents(APPPATH . 'Views/Family/profile-view.php');

        $this->assertIsString($source);
        $this->assertStringContainsString('family-media-label', $source);
        $this->assertStringContainsString('family-media-image family-media-image-photo', $source);
        $this->assertStringContainsString('family-media-image family-media-image-signature', $source);
        $this->assertStringNotContainsString('style="max-height: 200px;', $source);
        $this->assertStringNotContainsString('style="max-height: 120px;', $source);
    }

    /**
     * Seeds a linked family-media registry row for the profile page to surface.
     * The controller only reads `media_url` for the view, so no file is needed.
     */
    private function linkMedia(int $headId, string $kind, string $filename, int $controlNo): void
    {
        $mediaModel = new \App\Models\Families\FamilyMediaModel();
        $mediaId = $mediaModel->upsertPending([
            'source_control_no' => $controlNo,
            'source_filename'   => $filename,
            'kind'              => $kind,
        ]);
        $this->assertGreaterThan(0, $mediaId);
        $this->assertTrue($mediaModel->link(
            $mediaId,
            $headId,
            'records/' . $headId . '/media/' . $kind,
            ['content_sha256' => str_repeat('a', 64), 'byte_size' => 123, 'source_modified_at' => '2026-09-08 10:00:00']
        ));
    }
}
