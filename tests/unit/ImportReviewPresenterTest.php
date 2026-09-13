<?php

namespace Tests\Unit;

use App\Libraries\ImportReviewPresenter;
use App\Libraries\ImportReviewQuery;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Unit coverage for the review table's row shaping.
 *
 * The screen's job is that no problem in the file is invisible. The old table showed
 * four columns, so an error on barangay or relationship flagged a row red with nothing
 * to edit and no way to reach it. These tests pin the replacement rule: a row lists
 * every distinct issue it has, and offers an editor for every field carrying one,
 * whatever column that field belongs to.
 *
 * @internal
 */
final class ImportReviewPresenterTest extends CIUnitTestCase
{
    public function testAPageCarriesTheSliceAndTheTotals(): void
    {
        $rows = [];

        for ($i = 3; $i < 13; $i++) {
            $rows[] = $this->row($i, '6001', $i === 3 ? 'Head' : 'Child');
        }

        $page = $this->page(['rows' => $rows], ['per' => 25]);

        $this->assertCount(10, $page['rows']);
        $this->assertSame(10, $page['total']);
        $this->assertSame(10, $page['filtered']);
        $this->assertSame(1, $page['page']);
        $this->assertSame(25, $page['per']);
    }

    public function testASecondPageStartsWhereTheFirstEnded(): void
    {
        $rows = [];

        for ($i = 3; $i < 13; $i++) {
            $rows[] = $this->row($i, '6001', $i === 3 ? 'Head' : 'Child');
        }

        $page = $this->page(['rows' => $rows], ['per' => 25, 'page' => 2]);

        $this->assertSame([], $page['rows']);
        $this->assertSame(10, $page['total']);

        $second = $this->page(['rows' => $rows], ['per' => 25, 'page' => 1]);
        $this->assertSame(3, $second['rows'][0]['sheetRow']);
    }

    public function testAnErrorOnAnUndisplayedFieldStillOffersAnEditor(): void
    {
        // The whole point. Barangay is not a table column; before this change the row
        // went red and the operator had nothing to click.
        $page = $this->page([
            'rows'    => [$this->row(3, '6001', 'Head')],
            'errors'  => [$this->error(3, '6001', 'BRGY', 'warning', 'barangay')],
            'columns' => ['barangay' => 'P'],
        ]);

        $fields = array_column($page['rows'][0]['fields'], 'field');

        $this->assertSame(['barangay'], $fields);
        $this->assertSame('P3', $page['rows'][0]['fields'][0]['cell']);
        $this->assertSame('Barangay', $page['rows'][0]['fields'][0]['label']);
    }

    public function testMemberHouseholdCellsAreQuietInTheReview(): void
    {
        $page = $this->page([
            'rows' => [$this->row(3, '6001', 'Child')],
            'errors' => [
                $this->error(3, '6001', 'BRGY', 'warning', 'barangay'),
                $this->error(3, '6001', 'ADDRESS', 'blocking', 'address'),
            ],
        ]);

        $this->assertSame('', $page['rows'][0]['severity']);
        $this->assertSame([], $page['rows'][0]['issues']);
        $this->assertSame([], $page['rows'][0]['fields']);
    }

    public function testAMissingHeadIsFixableThroughItsRelationshipField(): void
    {
        // HEAD-NONE is recorded against relationship, so it needs no special case:
        // the operator sets one person's Relationship to Head and the file unblocks.
        $page = $this->page([
            'rows'   => [$this->row(3, '6001', 'Child')],
            'errors' => [$this->error(3, '6001', 'HEAD-NONE', 'blocking', 'relationship')],
        ]);

        $this->assertSame(['relationship'], array_column($page['rows'][0]['fields'], 'field'));
        $this->assertSame('blocking', $page['rows'][0]['severity']);
    }

    public function testAFieldlessIssueIsListedButOffersNoEditor(): void
    {
        // DUP-EXISTS reports what the import will do (skip the family). There is
        // nothing to correct, so it must show up as text and not as an input.
        $page = $this->page([
            'rows'   => [$this->row(3, '6001', 'Head')],
            'errors' => [$this->error(3, '6001', 'DUP-EXISTS', 'warning', null)],
        ]);

        $this->assertSame([], $page['rows'][0]['fields']);
        $this->assertSame(['DUP-EXISTS'], array_column($page['rows'][0]['issues'], 'code'));
        $this->assertSame('Already in the system', $page['rows'][0]['issues'][0]['label']);
    }

    public function testARowListsEveryDistinctIssueItHas(): void
    {
        $page = $this->page([
            'rows'   => [$this->row(3, '6001', 'Head')],
            'errors' => [
                $this->error(3, '6001', 'SEX', 'blocking', 'sex'),
                $this->error(3, '6001', 'BRGY', 'warning', 'barangay'),
                $this->error(3, '6001', 'BRGY', 'warning', 'barangay'),
            ],
        ]);

        $codes = array_column($page['rows'][0]['issues'], 'code');
        sort($codes);

        $this->assertSame(['BRGY', 'SEX'], $codes);
    }

    public function testABlockingErrorBeatsAWarningOnTheSameField(): void
    {
        $page = $this->page([
            'rows'   => [$this->row(3, '6001', 'Head')],
            'errors' => [
                $this->error(3, '6001', 'BDAY-RANGE', 'warning', 'birthday'),
                $this->error(3, '6001', 'BDAY', 'blocking', 'birthday'),
            ],
        ]);

        $this->assertCount(1, $page['rows'][0]['fields']);
        $this->assertSame('blocking', $page['rows'][0]['fields'][0]['severity']);
    }

    public function testTheProblemsFilterKeepsOnlyFlaggedRows(): void
    {
        $page = $this->page([
            'rows'   => [$this->row(3, '6001', 'Head'), $this->row(4, '6001', 'Child')],
            'errors' => [$this->error(4, '6001', 'SEX', 'blocking', 'sex')],
        ], ['severity' => 'problems']);

        $this->assertSame(2, $page['total']);
        $this->assertSame(1, $page['filtered']);
        $this->assertSame(4, $page['rows'][0]['sheetRow']);
    }

    public function testTheBlockingFilterExcludesWarningOnlyRows(): void
    {
        $page = $this->page([
            'rows'   => [$this->row(3, '6001', 'Head'), $this->row(4, '6001', 'Child')],
            'errors' => [
                $this->error(3, '6001', 'BRGY', 'warning', 'barangay'),
                $this->error(4, '6001', 'SEX', 'blocking', 'sex'),
            ],
        ], ['severity' => 'blocking']);

        $this->assertSame(1, $page['filtered']);
        $this->assertSame(4, $page['rows'][0]['sheetRow']);
    }

    public function testTheCodeFilterKeepsOnlyRowsCarryingThatCode(): void
    {
        $page = $this->page([
            'rows'   => [$this->row(3, '6001', 'Head'), $this->row(4, '6001', 'Child')],
            'errors' => [
                $this->error(3, '6001', 'BRGY', 'warning', 'barangay'),
                $this->error(4, '6001', 'SEX', 'blocking', 'sex'),
            ],
        ], ['code' => 'SEX']);

        $this->assertSame(1, $page['filtered']);
        $this->assertSame(4, $page['rows'][0]['sheetRow']);
    }

    public function testSearchMatchesNameQrAndFamilyLabelCaseInsensitively(): void
    {
        $rows = [
            $this->row(3, '6001', 'Head'),
            ['sheetRow' => 4, 'data' => [
                'familyno' => '6002', 'relationship' => 'Head', 'lastname' => 'Santos',
                'firstname' => 'Maria', 'birthday' => '01-01-1990', 'sex' => 'Female',
            ]],
        ];

        $this->assertSame(1, $this->page(['rows' => $rows], ['q' => 'santos'])['filtered']);
        $this->assertSame(1, $this->page(['rows' => $rows], ['q' => 'MARIA'])['filtered']);
        $this->assertSame(1, $this->page(['rows' => $rows], ['q' => '6002'])['filtered']);
        $this->assertSame(2, $this->page(['rows' => $rows], ['q' => ''])['filtered']);
    }

    public function testAFamilyIsLabelledByItsHeadsLastName(): void
    {
        $page = $this->page(['rows' => [
            $this->row(3, '6001', 'Head'),
            // Deliberately not Cruz: if the label came from each row's own lastname
            // instead of the head's, this row would read Santos and the test would fail.
            ['sheetRow' => 4, 'data' => [
                'familyno' => '6001', 'relationship' => 'Child', 'lastname' => 'Santos',
                'firstname' => 'Ana', 'birthday' => '02-02-2010', 'sex' => 'Female',
            ]],
        ]]);

        $this->assertSame('Cruz', $page['rows'][0]['family']);
        $this->assertSame('Cruz', $page['rows'][1]['family']);
        $this->assertSame('Head', $page['rows'][0]['role']);
        $this->assertSame('Child', $page['rows'][1]['role']);
    }

    public function testAGroupWithNoHeadFallsBackToItsQrAsTheLabel(): void
    {
        $page = $this->page(['rows' => [$this->row(3, '6001', 'Child')]]);

        $this->assertSame('QR 6001', $page['rows'][0]['family']);
    }

    public function testARowWithNoQrIsLabelledNoQr(): void
    {
        $page = $this->page(['rows' => [$this->row(3, '', 'Head')]]);

        $this->assertSame('No QR', $page['rows'][0]['family']);
    }

    public function testBuildReturnsTheSummaryOnlyAndNotTheRows(): void
    {
        $summary = (new ImportReviewPresenter())->build([
            'file'   => 'import.xlsx',
            'rows'   => [$this->row(3, '6001', 'Head')],
            'errors' => [$this->error(3, '6001', 'SEX', 'blocking', 'sex')],
            'counts' => ['rows' => 1, 'blocking' => 1, 'warnings' => 0],
        ]);

        $this->assertSame('import.xlsx', $summary['file']);
        $this->assertSame(1, $summary['counts']['blocking']);
        $this->assertArrayNotHasKey('people', $summary);
        $this->assertArrayNotHasKey('families', $summary);
        $this->assertArrayNotHasKey('ready', $summary);
        $this->assertArrayNotHasKey('unassigned', $summary);
    }

    public function testBuildListsTheIssueCodesPresentSoTheFilterCanOfferThem(): void
    {
        $summary = (new ImportReviewPresenter())->build([
            'rows'   => [$this->row(3, '6001', 'Head')],
            'errors' => [
                $this->error(3, '6001', 'SEX', 'blocking', 'sex'),
                $this->error(3, '6001', 'SEX', 'blocking', 'sex'),
                $this->error(3, '6001', 'BRGY', 'warning', 'barangay'),
            ],
        ]);

        $codes = array_column($summary['codes'], 'code');
        sort($codes);

        $this->assertSame(['BRGY', 'SEX'], $codes);
    }

    public function testBuildSurfacesWholeFileProblemsAsNotices(): void
    {
        $summary = (new ImportReviewPresenter())->build([
            'rows'   => [],
            'errors' => [[
                'sheetRow' => null, 'familyNo' => '', 'code' => 'EMPTY',
                'field' => null, 'message' => 'No family rows were found.',
                'severity' => 'blocking',
            ]],
        ]);

        $this->assertSame(['No family rows were found.'], $summary['fileNotices']);
    }

    public function testAMergedQrCellSurfacesAsAFileNoticeAndNotAsACode(): void
    {
        $summary = (new ImportReviewPresenter())->build([
            'rows'   => [$this->row(3, '6001', 'Head')],
            'errors' => [[
                'sheetRow' => null, 'familyNo' => '6001', 'code' => 'QR-11',
                'field' => null, 'message' => 'Unmerge the QR column and repeat the QR number on every row of the family.',
                'severity' => 'blocking',
            ]],
        ]);

        $this->assertSame(
            ['Unmerge the QR column and repeat the QR number on every row of the family.'],
            $summary['fileNotices']
        );
        $this->assertNotContains('QR-11', array_column($summary['codes'], 'code'));
    }

    public function testMissingAndAssignedIssueLabelsDescribeTheSpecificFieldAndOutcome(): void
    {
        $result = [
            'rows' => [
                $this->row(3, '6001', 'Head'),
                $this->row(4, '6002', 'Head'),
                $this->row(5, '6003', 'Head'),
                $this->row(6, '6004', 'Head'),
            ],
            'errors' => [
                $this->error(3, '6001', 'REQUIRED', 'blocking', 'firstname'),
                $this->error(4, '6002', 'INCOMPLETE', 'warning', 'monthlyincome'),
                $this->error(5, '6003', 'SERVICE', 'blocking', 'services'),
                $this->error(6, '6004', 'QR-TAKEN', 'blocking', 'familyno'),
            ],
        ];

        $page = $this->page($result);
        $issue = $page['rows'][0]['issues'][0];
        $incomeIssue = $page['rows'][1]['issues'][0];
        $serviceIssue = $page['rows'][2]['issues'][0];
        $takenIssue = $page['rows'][3]['issues'][0];

        $this->assertSame('Missing FirstName', $issue['label']);
        $this->assertSame('Missing Income', $incomeIssue['label']);
        $this->assertSame('Invalid Service Code', $serviceIssue['label']);
        $this->assertSame('QR already assigned to another family', $takenIssue['label']);

        $labels = array_column((new ImportReviewPresenter())->build($result)['codes'], 'label', 'code');
        $this->assertSame($issue['label'], $labels['REQUIRED']);
        $this->assertSame($incomeIssue['label'], $labels['INCOMPLETE']);
        $this->assertSame($serviceIssue['label'], $labels['SERVICE']);
        $this->assertSame($takenIssue['label'], $labels['QR-TAKEN']);
    }

    public function testMissingIssuesRetainEveryFieldLabelAndTheCodeFilterNamesAllFields(): void
    {
        $result = [
            'rows' => [$this->row(3, '6001', 'Head')],
            'errors' => [
                $this->error(3, '6001', 'REQUIRED', 'blocking', 'firstname'),
                $this->error(3, '6001', 'REQUIRED', 'blocking', 'lastname'),
                $this->error(3, '6001', 'INCOMPLETE', 'warning', 'birthday'),
                $this->error(3, '6001', 'INCOMPLETE', 'warning', 'monthlyincome'),
            ],
        ];

        $labels = array_column($this->page($result)['rows'][0]['issues'], 'label');
        sort($labels);

        $this->assertSame([
            'Missing Birthday',
            'Missing FirstName',
            'Missing Income',
            'Missing LastName',
        ], $labels);

        $filterLabels = array_column((new ImportReviewPresenter())->build($result)['codes'], 'label', 'code');
        $this->assertSame('Missing FirstName, Missing LastName', $filterLabels['REQUIRED']);
        $this->assertSame('Missing Birthday, Missing Income', $filterLabels['INCOMPLETE']);
    }

    public function testIssuesCarryTheirExcelCellReference(): void
    {
        $result = [
            'rows' => [['sheetRow' => 42, 'data' => [
                'familyno' => '6001', 'relationship' => 'Head',
                'firstname' => 'Juan', 'lastname' => 'Cruz', 'monthlyincome' => '',
            ]]],
            'errors' => [[
                'sheetRow' => 42, 'familyNo' => '6001', 'field' => 'monthlyincome',
                'code' => 'INCOMPLETE', 'message' => 'Monthly Income is blank - imports with no monthly income.',
                'severity' => 'warning',
            ]],
            'columns' => ['monthlyincome' => 'N'],
        ];

        $page = $this->page($result);
        $issue = $page['rows'][0]['issues'][0];

        $this->assertSame('INCOMPLETE', $issue['code']);
        $this->assertSame('N42', $issue['cell']);
    }

    public function testNewCodesHaveLabels(): void
    {
        // codesPresent() falls back to the raw code when GROUPS has no entry, which
        // renders as "INCOMPLETE" in the filter; every code the importer can now
        // emit needs a label.
        $result = ['rows' => [], 'errors' => [
            ['sheetRow' => 1, 'familyNo' => '6001', 'field' => 'birthday', 'code' => 'BDAY-FUTURE', 'message' => 'x', 'severity' => 'warning'],
            ['sheetRow' => 1, 'familyNo' => '6001', 'field' => 'birthday', 'code' => 'INCOMPLETE', 'message' => 'x', 'severity' => 'warning'],
            ['sheetRow' => 1, 'familyNo' => '6001', 'field' => 'sector', 'code' => 'SECTOR', 'message' => 'x', 'severity' => 'warning'],
        ]];

        $codes = array_column((new ImportReviewPresenter())->build($result)['codes'], 'label', 'code');

        $this->assertSame('Future birthday (imports blank)', $codes['BDAY-FUTURE']);
        $this->assertSame('Missing Birthday', $codes['INCOMPLETE']);
        $this->assertSame('Invalid Sector Code', $codes['SECTOR']);
    }

    public function testRowFetchesOneShapedRowBySheetRow(): void
    {
        $result = [
            'rows'   => [$this->row(3, '6001', 'Head'), $this->row(4, '6001', 'Child')],
            'errors' => [$this->error(4, '6001', 'SEX', 'blocking', 'sex')],
        ];

        $row = (new ImportReviewPresenter())->row($result, 4);

        $this->assertNotNull($row);
        $this->assertSame(4, $row['sheetRow']);
        $this->assertSame('blocking', $row['severity']);
        $this->assertNull((new ImportReviewPresenter())->row($result, 999));
    }

    public function testDiscardedRowsAreShownMutedWithoutTheirActiveErrorsOrEditors(): void
    {
        $result = [
            'rows' => [$this->row(589, '6001', 'Head'), $this->row(590, '6001', 'Head')],
            'errors' => [
                $this->error(589, '6001', 'DUP-ROW', 'blocking', null),
                $this->error(590, '6001', 'DUP-ROW', 'blocking', null),
            ],
            'discarded' => [590 => ['keptRow' => 589, 'reason' => 'duplicate']],
            'duplicateGroups' => [['rows' => [589, 590], 'qr' => '6001']],
        ];

        $all = $this->page($result);
        $row = $all['rows'][1];

        $this->assertTrue($row['discarded']);
        $this->assertSame('Discarded as duplicate of row 589', $row['issues'][0]['label']);
        $this->assertSame([], $row['fields']);
        $this->assertNull($row['duplicateGroup']);

        $discarded = $this->page($result, ['severity' => 'discarded']);
        $this->assertSame([590], array_column($discarded['rows'], 'sheetRow'));

        $blocking = $this->page($result, ['severity' => 'blocking']);
        $this->assertSame([589], array_column($blocking['rows'], 'sheetRow'));
    }

    public function testAnActiveDuplicateRowCarriesItsResolverGroup(): void
    {
        $page = $this->page([
            'rows' => [$this->row(589, '6001', 'Head'), $this->row(590, '6001', 'Head')],
            'errors' => [
                $this->error(589, '6001', 'DUP-ROW', 'blocking', null),
                $this->error(590, '6001', 'DUP-ROW', 'blocking', null),
            ],
            'duplicateGroups' => [['rows' => [589, 590], 'qr' => '6001']],
        ]);

        $this->assertFalse($page['rows'][0]['discarded']);
        $this->assertSame([589, 590], $page['rows'][0]['duplicateGroup']['rows']);
    }

    public function testReviewGroupLabelsMatchTheApprovedWording(): void
    {
        // The spec's exact wording, pinned here so a label regression fails the
        // build. Several of these codes surface only as a file notice or a filter
        // entry rather than a row issue, so read the map directly.
        $reflection = new \ReflectionClass(ImportReviewPresenter::class);
        $groups = $reflection->getReflectionConstant('GROUPS')->getValue();

        $expected = [
            'FILE'         => 'File cannot be imported',
            'QR-01'        => 'Missing QR',
            'QR-FORMAT'    => 'Invalid QR',
            'QR-05'        => 'QR is zero',
            'QR-07'        => 'QR is too large',
            'QR-08'        => 'QR cell contains an Excel error',
            'QR-12'        => 'QR cell is a formula',
            'HEAD-NONE'    => 'No Head in Family',
            'HEAD-MULTI'   => 'Multiple Heads (Same Family)',
            'FP-ADDR'      => 'Multiple Addresses in Family',
            'QR-TAKEN'     => 'QR already assigned to another family',
            'DUP-QR-FAMILY'=> 'Duplicate QR (Multiple Families)',
            'DUP-ROW'      => 'Duplicate Row',
            'SECTOR'       => 'Invalid Sector Code',
            'SERVICE'      => 'Invalid Service Code',
            'QR-CONTIG'    => 'Family rows not together',
        ];

        foreach ($expected as $code => $label) {
            $this->assertSame($label, $groups[$code]['label'], $code);
        }
    }

    /** @param array<string, mixed> $query */
    private function page(array $result, array $query = []): array
    {
        return (new ImportReviewPresenter())->page($result, ImportReviewQuery::fromArray($query));
    }

    /** One staged row in the importer's {sheetRow, data} shape. */
    private function row(int $sheetRow, string $qr, string $relationship): array
    {
        return [
            'sheetRow' => $sheetRow,
            'data'     => [
                'familyno'     => $qr,
                'relationship' => $relationship,
                'lastname'     => 'Cruz',
                'firstname'    => 'Juan',
                'birthday'     => '03-03-1980',
                'sex'          => 'Male',
                'barangay'     => 'Nowhere',
                'address'      => '1 Street',
            ],
        ];
    }

    /** One error in the importer's shape. */
    private function error(int $sheetRow, string $qr, string $code, string $severity, ?string $field): array
    {
        return [
            'sheetRow' => $sheetRow,
            'familyNo' => $qr,
            'code'     => $code,
            'field'    => $field,
            'message'  => $code . ' on row ' . $sheetRow,
            'severity' => $severity,
        ];
    }
}
