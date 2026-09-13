<?php

namespace Tests\Unit;

use App\Libraries\FamilyExcelTemplate;
use CodeIgniter\Test\CIUnitTestCase;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ver4 template coverage: the Families sheet's CHECK column carries approved
 * preflight labels, stays Excel-only (never feeds the importer), and
 * colors Must fix problems red while Head card-readiness warnings are yellow.
 * The guidance keeps the import contract explicit for workers.
 *
 * @internal
 */
final class FamilyExcelTemplateTest extends CIUnitTestCase
{
    /** The approved Ver4 CHECK labels for identity and household structure. */
    private const APPROVED_LABELS = [
        'OK',
        'Missing QR',
        'Missing LastName',
        'Missing FirstName',
        'No Head in Family',
        'Multiple Heads (Same Family)',
        'Duplicate QR (Multiple Families)',
    ];

    /** CHECK is the 19th column, one past the 18 data columns. */
    private const CHECK_RANGE = 'S3:S1000';

    private function familiesSheet(): Worksheet
    {
        return (new FamilyExcelTemplate())->build()->getSheetByName(FamilyExcelTemplate::DATA_SHEET);
    }

    public function testCheckFormulaCarriesEveryApprovedLabel(): void
    {
        $formula = (string) $this->familiesSheet()->getCell('S3')->getValue();

        foreach (self::APPROVED_LABELS as $label) {
            $this->assertStringContainsString(
                $label,
                $formula,
                'the preflight formula must produce "' . $label . '"'
            );
        }
    }

    public function testCheckHeaderCommentMarksExcelOnlyPreflight(): void
    {
        $comment = $this->familiesSheet()->getComment('S2')->getText()->getPlainText();

        $this->assertStringContainsString('Excel-only preflight', $comment);
    }

    public function testRedFormattingAppliesToMustFixOutput(): void
    {
        $red = $this->matchingRule(self::CHECK_RANGE, static fn (string $condition): bool =>
            str_contains($condition, '<>"OK"') && str_contains($condition, '<>"Card Readiness"'));

        $this->assertNotNull($red, 'a red Must fix rule must exclude only OK and Card Readiness warnings');
        $this->assertSame(Fill::FILL_SOLID, $red->getStyle()->getFill()->getFillType());
        $this->assertSame('FFC7CE', $red->getStyle()->getFill()->getStartColor()->getRGB());
        $this->assertSame('9C0006', $red->getStyle()->getFont()->getColor()->getRGB());
    }

    public function testYellowFormattingAppliesToCardReadinessWarnings(): void
    {
        $yellow = $this->matchingRule(self::CHECK_RANGE, static fn (string $condition): bool =>
            str_contains($condition, '"Card Readiness"') && ! str_contains($condition, '<>'));

        $this->assertNotNull($yellow, 'a yellow warning rule must be keyed by Card Readiness');
        $this->assertSame(Fill::FILL_SOLID, $yellow->getStyle()->getFill()->getFillType());
        $this->assertSame('FFF2CC', $yellow->getStyle()->getFill()->getStartColor()->getRGB());
    }

    public function testTemplateMarksHeadCardFieldsButNotOptionalProfileFieldsAsCardData(): void
    {
        $sheet = (new FamilyExcelTemplate())->build()->getSheetByName('Example');
        $guide = (string) $sheet->getCell('A8')->getValue();

        $this->assertStringContainsString('Card Readiness', $guide);
        $this->assertStringNotContainsString('Multiple Addresses in Family', $guide);
        $this->assertStringNotContainsString('Missing Income', $guide);
    }

    public function testCheckFormulaCountsTheWholeEntryAreaWithBoundedScalarCriteria(): void
    {
        $formula = (string) $this->familiesSheet()->getCell('S3')->getValue();

        // The Check formula reads the whole entry area with COUNTIFS over absolute
        // bounded ranges and scalar criteria. There is deliberately no division and
        // no array criteria: PhpSpreadsheet corrupts the XML for dynamic-array
        // idioms, and a scalar criteria COUNTIFS cannot produce #DIV/0! because
        // nothing divides. Every family-block condition must cover the same row
        // window (3 through 1000) or the sheet's own guidance under-reports.
        $this->assertStringContainsString(
            'COUNTIFS($A$3:$A$1000,$A3,$B$3:$B$1000,"Head")',
            $formula,
            'the head count must read the whole QR and Relationship columns'
        );
        $this->assertStringContainsString(
            'COUNTIFS($A$3:$A$1000,$A3,$B$3:$B$1000,"Head",$C$3:$C$1000,"<>"&$C3)',
            $formula,
            'the duplicate-QR check must compare head last names across the entry area'
        );
        foreach (['No Head in Family', 'Multiple Heads (Same Family)', 'Duplicate QR (Multiple Families)', 'Card Readiness: Missing Birthday', 'Card Readiness: Missing Sex', 'Card Readiness: Missing Contact Number', 'Card Readiness: Missing Address', 'Card Readiness: Missing Barangay', 'OK'] as $label) {
            $this->assertStringContainsString('"' . $label . '"', $formula);
        }

        // The array-criteria idiom this formula replaced is what corrupted the
        // generated XML; it must stay out.
        $this->assertStringNotContainsString('COUNTIFS($A$3:$A$1000,$A$3:$A$1000', $formula);
    }

    /**
     * The first conditional rule over the CHECK range whose condition text
     * satisfies $match, or null when no rule does.
     *
     * @param list<Conditional> $styles
     */
    private function matchingRule(string $range, callable $match): ?Conditional
    {
        foreach ($this->familiesSheet()->getConditionalStyles($range) as $rule) {
            if ($match(implode(' ', $rule->getConditions()))) {
                return $rule;
            }
        }

        return null;
    }
}
