<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The profile page is the one surface for an existing record: it displays and edits.
 * Fields are always form controls with one Save at the bottom, because a read-to-edit
 * toggle would mean two render paths for every field on a page only reachable by
 * someone who came to change something.
 */
final class FamilyProfilePageTest extends CIUnitTestCase
{
    private function render(bool $readOnly = false): string
    {
        helper(['ui', 'family_modal']);

        return view('Family/profile', [
            'head' => ['memberID' => 7, 'lastname' => 'DELA CRUZ', 'firstname' => 'JUAN'],
            'members' => [
                ['memberID' => 8, 'lastname' => 'DELA CRUZ', 'firstname' => 'MARIA', 'relationship' => 'Asawa'],
            ],
            'controlNumber' => 142,
            'readOnly' => $readOnly,
            'sectors' => [], 'services' => [], 'categories' => [],
            'qrDataUri' => 'data:image/png;base64,stub',
        ]);
    }

    public function testMembersAreNestedInsideTheHeadCard(): void
    {
        $html = $this->render();

        $headPos = strpos($html, 'data-head-card');
        $memberPos = strpos($html, 'data-member-card');
        $this->assertNotFalse($headPos);
        $this->assertNotFalse($memberPos);
        $this->assertLessThan($memberPos, $headPos, 'Members belong inside the head card.');
    }

    public function testMembersUseTheBootstrapGrid(): void
    {
        // The literal wrapper tag, not a bare 'col-12' substring check: that class
        // also appears inside _fields.php's person-field columns (rendered
        // unconditionally, including in the unused member-editor <template>), so a
        // bare substring match would still pass with the grid wrapper deleted.
        $this->assertStringContainsString('<div class="col-12" data-member-card>', $this->render());
    }

    public function testEditableRenderHasOneSaveButton(): void
    {
        $this->assertStringContainsString('data-family-save', $this->render(false));
    }

    public function testFieldsAreOneRenderPathNotATogglableDisplay(): void
    {
        // The same head_lastname input exists in both renders - `disabled` is the
        // only difference - proving there is one render path for a field, not a
        // display value plus a button that swaps in an editable one.
        preg_match('/<input[^>]*name="head_lastname"[^>]*>/', $this->render(false), $editable);
        preg_match('/<input[^>]*name="head_lastname"[^>]*>/', $this->render(true), $viewerOnly);

        $this->assertNotEmpty($editable, 'Expected a head_lastname input in the editable render.');
        $this->assertNotEmpty($viewerOnly, 'Expected the same head_lastname input in the read-only render.');
        $this->assertStringNotContainsString('disabled', $editable[0]);
        $this->assertStringContainsString('disabled', $viewerOnly[0]);
    }

    public function testViewerGetsDisabledControlsAndNoSave(): void
    {
        $html = $this->render(true);

        $this->assertStringContainsString('disabled', $html);
        $this->assertStringNotContainsString('data-family-save', $html);
    }

    public function testTheEditPageShowsTheCodeBesideTheControlNumber(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data:image/png;base64,', $html);
        // Decorative: alt="" so a screen reader does not repeat the number the
        // adjacent "Control No." badge already announces.
        $this->assertMatchesRegularExpression('/<img src="data:image\/png;base64,[^"]*" width="64" height="64" alt="">/', $html);
    }

    public function testTheCodeIsPlainWithNoCardOrRounding(): void
    {
        // Scoped to the card-header block that holds the control number and QR
        // code, not the whole page: Family/_fields (rendered inside this same
        // page for the member editor) uses 'rounded' classes unconditionally for
        // unrelated pills and empty-state borders, so a page-wide assertion would
        // fail regardless of this feature.
        $html = $this->render();
        preg_match('/<div class="card-header.*?<\/div>\s*<div class="card-body">/s', $html, $header);

        $this->assertNotEmpty($header, 'Expected to find the head card header block.');
        $this->assertStringNotContainsString('rounded', $header[0]);
    }

    public function testTheFormPostsToTheFlatUpdateUri(): void
    {
        // The form action is esc(..., 'attr')-encoded (site_url() output in an
        // attribute), which entity-encodes "/" and ":", so decode before matching
        // the plain URI rather than weakening the escaping to make this pass.
        $html = html_entity_decode($this->render(), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('records/7/update', $html);
    }

    public function testEditableFormHasOptionalMultipartMediaInputs(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('enctype="multipart/form-data"', $html);
        $this->assertStringContainsString('name="head_photo"', $html);
        $this->assertStringContainsString('accept="image/jpeg"', $html);
        $this->assertStringContainsString('name="head_signature"', $html);
        $this->assertStringContainsString('accept="image/png"', $html);
        $this->assertStringContainsString('(optional)', $html);
    }
}
