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