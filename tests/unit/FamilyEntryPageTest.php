<?php

namespace Tests\Unit;

use App\Models\Families\FamilyFormOptionsModel;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The entry page is a vertical stepper spine, not a wizard: the control number
 * is step 1 and gates the three steps below it (head, photo and signature,
 * members), and all four stay reachable at once (non-linear) because the
 * officer is transcribing from one paper sheet where head and members are both
 * visible.
 */
final class FamilyEntryPageTest extends CIUnitTestCase
{
    private function render(): string
    {
        helper(['ui', 'family_modal']);

        return view('Family/entry', [
            'head'        => [],
            'members'     => [],
            'readOnly'    => false,
            'sectors'     => [['sectorID' => 1, 'shortcode' => 'SC', 'name' => 'Senior Citizen']],
            'services'    => [['serviceID' => 1, 'shortcode' => 'FA2', 'name' => 'Burial Assistance']],
            'categories'  => [],
            'formOptions' => (new FamilyFormOptionsModel())->staticOptionLists(),
        ]);
    }

    public function testTheSpineHasFourSteps(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('stepper stepper-vertical', $html);
        $this->assertSame(4, substr_count($html, '<li class="stepper-step"'));
        $this->assertStringContainsString('Control Number', $html);
        $this->assertStringContainsString('Head of Family', $html);
        $this->assertStringContainsString('Photo & Signature', $html);
        $this->assertStringContainsString('Members of the Family', $html);
    }

    public function testTheGateStillCarriesItsCheckEndpoint(): void
    {
        // The gate's fetch call reads this off the dataset; asserting the
        // attribute name alone would still pass after a route rename, so this
        // pins the actual records/qr-check path. esc(..., 'attr') entity-encodes
        // the slashes, hence the regex rather than a literal substring.
        $this->assertMatchesRegularExpression(
            '~data-qr-check-url="[^"]*records&#x2F;qr-check"~',
            $this->render()
        );
    }

    public function testHeadAndMembersAreBothPresentAndNeverTabbed(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('id="section-head"', $html);
        $this->assertStringContainsString('id="section-members"', $html);
        $this->assertStringNotContainsString('tab-pane', $html,
            'A tab split would hide the head while members are entered.');
    }

    public function testSectorsAndServicesAreNoLongerPageSections(): void
    {
        $html = $this->render();

        // They belong to the head and to every member alike, so they are not
        // steps of the page and carry no page-level anchor.
        $this->assertStringNotContainsString('id="section-sectors"', $html);
        $this->assertStringNotContainsString('id="section-services"', $html);
    }

    public function testEveryStepIsAnAnchorSoTheSpineIsNonLinear(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('href="#section-control"', $html);
        $this->assertStringContainsString('href="#section-head"', $html);
        $this->assertStringContainsString('href="#section-media"', $html);
        $this->assertStringContainsString('href="#section-members"', $html);
    }

    public function testTheGatedSectionsAreHiddenAndMarked(): void
    {
        $html = $this->render();

        $this->assertSame(3, substr_count($html, 'data-entry-section'));
        $this->assertSame(3, substr_count($html, 'class="stepper-step-content d-none"'));
    }

    public function testTheEntryRootWrapsTheFormSoTheSharedJsStillBinds(): void
    {
        $html = $this->render();

        // [data-family-entry-form] sits one level above <form>, matching
        // Family/profile.php's shape: manage-family-modal.js does
        // root.querySelector('form') from this marker in several places, which
        // would find nothing if the marker sat on the <form> itself.
        $this->assertMatchesRegularExpression('/data-family-entry-form[^>]*>\s*<form/', $html);
    }

    public function testTheControlNumberFieldDoesNotPost(): void
    {
        $html = $this->render();

        // It lives inside the form now, so a name attribute would post a field
        // the store() action does not expect. The hidden qr_control_no carries it.
        $this->assertStringNotContainsString('name="control_no"', $html);
    }

    public function testTheTruncationSentinelIsStillLast(): void
    {
        $html = $this->render();

        $this->assertGreaterThan(
            strrpos($html, 'name="members_meta_count"'),
            strrpos($html, 'name="_form_end"')
        );
    }

    public function testThereIsExactlyOneSaveButton(): void
    {
        $this->assertSame(1, substr_count($this->render(), 'data-family-save'));
    }

    public function testTheActionBarCarriesAPlaceForTheBlockedReason(): void
    {
        $html = $this->render();

        // reportSaveBlocked() writes the count of missing required fields here.
        // It has to be a live region, or the count changes silently for anyone
        // not looking at that corner of the screen.
        $this->assertStringContainsString('data-entry-blocked', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('data-family-save', $html);
    }
}
