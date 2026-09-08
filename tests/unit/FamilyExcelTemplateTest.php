<?php

namespace Tests\Unit;

use App\Libraries\FamilyExcelTemplate;
use CodeIgniter\Test\CIUnitTestCase;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ver4 template coverage: the Families sheet's CHECK column carries the ten
 * approved preflight labels, stays Excel-only (never feeds the importer), and
 * colors Must fix problems red while incomplete-data output such as Missing
 * Income is yellow. The asterisk guidance keeps the completeness contract
 * explicit for workers.
 *
 * @internal
 */
final class FamilyExcelTemplateTest extends CIUnitTestCase
{
    /** The ten approved Ver4 CHECK labels, exactly as the task brief lists them. */
    private const APPROVED_LABELS = [
        'OK',
        'Missing QR',
        'Missing Relationship',
        'Missing LastName',
        'Missing FirstName',
        'Missing Income',
        'No Head in Family',
        'Multiple Heads (Same Family)',
        'Duplicate QR (Multiple Families)',
        'Multiple Addresses in Family',
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
            str_contains($condition, '<>"OK"') && str_contains($condition, '<>"Missing Income"'));

        $this->assertNotNull($red, 'a red Must fix rule must exclude only OK and Missing Income');
        $this->assertSame(Fill::FILL_SOLID, $red->getStyle()->getFill()->getFillType());
        $this->assertSame('FFC7CE', $red->getStyle()->getFill()->getStartColor()->getRGB());
        $this->assertSame('9C0006', $red->getStyle()->getFont()->getColor()->getRGB());
    }

    public function testYellowFormattingAppliesToMissingIncome(): void
    {
        $yellow = $this->matchingRule(self::CHECK_RANGE, static fn (string $condition): bool =>
            str_contains($condition, '"Missing Income"') && ! str_contains($condition, '<>'));

        $this->assertNotNull($yellow, 'a yellow incomplete-data rule must be keyed by Missing Income');
        $this->assertSame(Fill::FILL_SOLID, $yellow->getStyle()->getFill()->getFillType());
        $this->assertSame('FFF2CC', $yellow->getStyle()->getFill()->getStartColor()->getRGB());
    }

    public function testAsteriskGuidanceExplainsCompleteness(): void
    {
        $sheet = (new FamilyExcelTemplate())->build()->getSheetByName('Example');
        $guide = (string) $sheet->getCell('A8')->getValue();

        $this->assertStringContainsString('complete record', $guide);
        $this->assertStringContainsString('import as missing', $guide);
        $this->assertStringContainsString('Data Completeness', $guide);
    }

    public function testCheckFormulaDistinctCountDenominatorsMirrorTheirRanges(): void
    {
        $formula = (string) $this->familiesSheet()->getCell('S3')->getValue();

        // The distinct-count idioms in the Check formula divide by a COUNTIFS whose
        // criteria pairs must mirror the SAME ranges they count over. Scalar criteria
        // ("$A3" / "Head" / "$A3" + current address) leave a 0 denominator for
        // differently-named members and trailing blank rows, which real Excel turns
        // into #DIV/0! for the whole column.
        $this->assertStringContainsString(
            'COUNTIFS($A$3:$A$1000,$A$3:$A$1000,$B$3:$B$1000,$B$3:$B$1000,$C$3:$C$1000,$C$3:$C$1000,$D$3:$D$1000,$D$3:$D$1000)',
            $formula,
            'head-identity denominator must mirror the A/B/C/D ranges'
        );
        $this->assertStringContainsString(
            'COUNTIFS($A$3:$A$1000,$A$3:$A$1000,$O$3:$O$1000,$O$3:$O$1000)',
            $formula,
            'address denominator must mirror the A and O ranges'
        );
        $this->assertStringNotContainsString(
            'COUNTIFS($A$3:$A$1000,$A3,$B$3:$B$1000,"Head",$C$3:$C$1000',
            $formula,
            'head-identity denominator must not use scalar QR/Head criteria'
        );
        $this->assertStringNotContainsString(
            'COUNTIFS($A$3:$A$1000,$A3,$O$3:$O$1000,',
            $formula,
            'address denominator must not use a scalar QR criterion'
        );
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
