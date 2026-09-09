<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * The import review renders inside the shared layout as one flat per-person table.
 * Its steps are a real sequence, so a wizard fits here where it did not on the entry
 * form: step 2 never needs step 1 visible and the server state changes between them.
 */
final class ImportWizardViewTest extends CIUnitTestCase
{
    private function render(): string
    {
        helper('ui');

        return view('Family/import-review', [
            'jobId'   => 5,
            'summary' => [
                'file'        => 'import.xlsx',
                'counts'      => ['rows' => 10, 'blocking' => 1, 'warnings' => 2, 'families' => 3,
                                  'newFamilies' => 3, 'appends' => 0, 'existing' => 0],
                'codes'       => [['code' => 'SEX', 'label' => 'Invalid sex', 'severity' => 'blocking']],
                'fileNotices' => [],
            ],
            'fieldOptions' => ['sex' => ['Male', 'Female']],
        ]);
    }

    public function testItCarriesNoPrivateHtmlShell(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('<html', $html);
        $this->assertStringNotContainsString('import-review.css', $html);
        $this->assertStringNotContainsString('navbar-dark', $html);
    }

    public function testStepsUseTheStepperComponent(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('stepper stepper-horizontal', $html);
        $this->assertStringContainsString('Upload</span>', $html);
        $this->assertStringContainsString('Review and Fix</span>', $html);
        $this->assertStringNotContainsString('Column Mapping', $html,
            'The workbook comes from our own template; columns are known.');
    }

    public function testTheAdvertisedDoneStepIsGone(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('3. Done', $html);
        $this->assertStringNotContainsString('>Done<', $html);
    }

    public function testABlockingCountPutsTheReviewStepInError(): void
    {
        // The seeded summary in render() carries blocking => 1.
        $html = $this->render();

        $this->assertStringContainsString('data-state="error"', $html);
    }

    public function testTheStepsAreNotLinks(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('<a class="stepper-step-link"', $html);
    }

    public function testTheStatCardRowAndGroupContainersAreGone(): void
    {
        $html = $this->render();

        $this->assertStringNotContainsString('importReviewStats', $html);
        $this->assertStringNotContainsString('importReviewGroups', $html);
    }

    public function testTheTableIsPerPersonWithSeverityFiltering(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('id="importReviewTable"', $html);

        foreach (['ID', 'Role', 'Row', 'Last Name', 'First Name'] as $column) {
            $this->assertStringContainsString($column, $html);
        }
    }

    public function testTheUploadStepIsAPageNotAModal(): void
    {
        helper('ui');

        $html = view('Family/import-upload', [
            'action'      => site_url('records/import'),
            'templateUrl' => site_url('records/template'),
        ]);

        $this->assertStringContainsString('stepper stepper-horizontal', $html);
        $this->assertStringContainsString('data-family-import', $html);
        $this->assertStringNotContainsString('data-bs-dismiss="modal"', $html);
    }

    public function testItCarriesTheRowsAndApplyEndpoints(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('data-rows-url', $html);
        $this->assertStringContainsString('data-apply-url', $html);
    }

    public function testItShipsNoPerPersonJsonIsland(): void
    {
        // The whole point of the rows endpoint: a 10,000-row file must not be inlined
        // into the page. Only the summary and the dropdown options travel in the HTML.
        $html = $this->render();

        $this->assertStringNotContainsString('id="importReviewData"', $html);
        $this->assertStringContainsString('importReviewSummary', $html);
        $this->assertStringContainsString('importReviewFieldOptions', $html);
    }

    public function testItRendersTheToolbarSearchAndPageSize(): void
    {
        $html = html_entity_decode($this->render(), ENT_QUOTES | ENT_HTML5);

        $this->assertStringContainsString('id="importReviewSearch"', $html);
        $this->assertStringContainsString('id="importReviewDatabaseSearch"', $html);
        $this->assertStringContainsString('Search this import...', $html);
        $this->assertStringContainsString('id="importReviewPerPage"', $html);
    }

    public function testItRendersTheSeverityPillsAndThePager(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('segmented-tabs', $html);
        $this->assertStringContainsString('data-severity="all"', $html);
        $this->assertStringContainsString('data-severity="problems"', $html);
        $this->assertStringContainsString('data-severity="blocking"', $html);
        $this->assertStringContainsString('data-severity="warning"', $html);
        $this->assertStringContainsString('id="importReviewPager"', $html);
    }

    public function testItRendersAnIssuesColumn(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('>Issues<', $html);
    }

    public function testItUsesTheSemanticButtonHelperForActions(): void
    {
        // btn() is the single source of truth for button colour. Checked against
        // the view SOURCE, not the rendered HTML: btn('save') renders to the exact
        // same string ("btn btn-primary") a hand-written class would, so rendered
        // output can never tell correct helper use apart from the violation it is
        // meant to catch.
        $source = file_get_contents(APPPATH . 'Views/Family/import-review.php');
        $this->assertIsString($source);

        // Named, not just "some btn() call somewhere in the file": a btn() on an
        // unrelated control would satisfy the loose form while the confirm button
        // carried a hand-written class.
        $this->assertStringContainsString("class=\"<?= btn('add') ?>\" id=\"importReviewConfirm\"", $source,
            'The confirm action should be styled through btn(), not a literal class.');

        // A hand-written role class (what btn() would otherwise produce) must never
        // appear as source text: it can only get into the rendered HTML through a
        // btn() call, which does not spell these class names out in the view file.
        foreach (['btn-primary', 'btn-success', 'btn-danger', 'btn-warning'] as $literal) {
            $this->assertStringNotContainsString($literal, $source,
                "Found a hand-written {$literal} class; use btn() instead.");
        }
    }

    public function testItCarriesNoInlineStyles(): void
    {
        $this->assertStringNotContainsString('style="', $this->render());
    }
}
