<?php

namespace App\Libraries;

use App\Models\Families\FamilyFormOptionsModel;
use App\Models\Lookups\SectorModel;
use App\Models\Lookups\ServiceModel;
use App\Support\FamilyProfilingFormV2;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Builds the blank, fillable .xlsx template workers use to collect family records
 * offline. The layout mirrors the CSWD Family Profiling Form v2: one row per person,
 * grouped by a "FamilyNo" column, with the Relationship = "Head" row marking the head.
 *
 * Design: a colored group-banner row (row 1) over the column headers (row 2), required
 * markers, per-column hover tooltips, fixed widths, zebra-striped entry rows, frozen
 * name columns and colored tabs - so the sheet reads like a form, not a raw grid.
 *
 * Sectors and services are entered as CODES (comma-separated) in single Sector/Services
 * columns; civil status and education dropdowns carry the form's short codes. The
 * Reference sheet lists every code. The matching importer is App\Libraries\FamilyExcelImporter
 * (it locates the header row by the "FamilyNo" cell, so the banner row is safe).
 */
class FamilyExcelTemplate
{
    /** Worksheet that holds the family rows the worker fills in. */
    public const DATA_SHEET = 'Families';

    /** Worksheet listing valid values + code legends. */
    private const REFERENCE_SHEET = 'Reference';

    /** Read-only illustration sheet; the importer never reads it. */
    private const EXAMPLE_SHEET = 'Example';

    /** Row 1 = group banners, row 2 = column headers, row 3+ = data. */
    private const HEADER_ROW = 2;
    private const FIRST_DATA_ROW = 3;
    private const LAST_TEMPLATE_ROW = 1000;

    /** Civil-status short codes (CSWD form) -> the full value stored in the DB. */
    public const CIVIL_STATUS_CODES = [
        'S' => 'SINGLE',
        'M' => 'MARRIED',
        'W' => 'WIDOW / WIDOWER',
        'H' => 'SEPARATED',
        'N' => 'LIVE-IN / NOT MARRIED',
    ];

    /** Education short codes (CSWD form) -> the full value stored in the DB. */
    public const EDUCATION_CODES = [
        'E'   => 'ELEMENTARY',
        'HS'  => 'HIGH SCHOOL',
        'UG'  => 'UNDERGRADUATE',
        'Voc' => 'VOCATIONAL',
        'CG'  => 'COLLEGE GRADUATE',
        'PG'  => 'POST GRADUATE',
    ];

    /** Ordered column headers for the Families sheet (matched case-insensitively). @var list<string> */
    public const COLUMNS = [
        'QR Number', 'Relationship', 'LastName', 'FirstName', 'MiddleName', 'Suffix',
        'Birthday', 'Sex', 'CivilStatus', 'ContactNumber', 'Religion', 'Education',
        'Job', 'MonthlyIncome', 'Address', 'Barangay', 'Sector', 'Services',
    ];

    /**
     * Starred columns (" *"): needed for a COMPLETE record, not before import.
     * A blank value imports as missing and the family enters Data Completeness
     * until the data is collected.
     */
    private const ALWAYS_REQUIRED = ['QR Number', 'Relationship', 'FirstName', 'LastName', 'Birthday', 'Sex', 'CivilStatus', 'Education', 'Job', 'MonthlyIncome'];

    /** Columns required only on the Head row (members inherit) - flagged via a header comment. */
    private const HEAD_REQUIRED = ['Address', 'Barangay'];

    /** Per-column entry widths (display only). */
    private const WIDTHS = [
        'QR Number' => 9, 'Relationship' => 14, 'LastName' => 16, 'FirstName' => 16,
        'MiddleName' => 14, 'Suffix' => 8, 'Birthday' => 13, 'Sex' => 10, 'CivilStatus' => 20,
        'ContactNumber' => 15, 'Religion' => 18, 'Education' => 20, 'Job' => 18,
        'MonthlyIncome' => 20, 'Address' => 26, 'Barangay' => 18, 'Sector' => 20, 'Services' => 22,
    ];

    /** Short hover tooltips shown when a cell is selected. */
    private const PROMPTS = [
        'QR Number'      => 'Same number for everyone in one family.',
        'Relationship'  => 'Use "Head" for the head of family; others: Spouse, Children, Parent...',
        'Birthday'      => 'Format: MM-DD-YYYY (e.g. 05-14-1980).',
        'ContactNumber' => '11 digits, e.g. 09171234567.',
        'CivilStatus'   => 'Pick a code: S, M, W, H, N.',
        'Education'     => 'Pick a code: E, HS, UG, Voc, CG, PG.',
        'Address'       => 'Head: full house/street address. Members: leave blank - they use the head\'s address.',
        'Barangay'      => 'Head: pick the barangay. Members: leave blank - they use the head\'s.',
        'Sector'        => 'WHO they are. Sector code(s), comma-separated: SC, PWD, SP, B, LGBT, OFW, IP, IDP, PDL. Use OTHER if none apply. See Reference.',
        'Services'      => 'Programs RECEIVED. Service code(s), comma-separated (e.g. SC1, FA6, 4PS, EDA5). See Reference.',
    ];

    /** Group banners over the header (start, end, label, fill RGB). @var list<array{0:int,1:int,2:string,3:string}> */
    private const BANNERS = [
        [1, 2, 'FAMILY', 'D9E1F2'],
        [3, 6, 'NAME', 'DDEBF7'],
        [7, 14, 'PERSONAL DETAILS', 'E2EFDA'],
        [15, 16, 'ADDRESS', 'FCE4D6'],
        [17, 17, 'SECTOR (who) - codes', 'E4DFEC'],
        [18, 18, 'SERVICES (programs) - codes', 'E4DFEC'],
    ];

    /** Builds the populated template workbook ready to stream/save. */
    public function build(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()->setCreator('Binan AccessCard')->setTitle('Family Records Import Template');

        $ranges = $this->buildReferenceSheet($spreadsheet);
        $this->buildExampleSheet($spreadsheet);
        $this->buildDataSheet($spreadsheet, $ranges);

        $spreadsheet->setActiveSheetIndexByName(self::DATA_SHEET);

        return $spreadsheet;
    }

    /**
     * Writes the Reference sheet and returns [list key => range] strings for the data
     * sheet's dropdowns.
     *
     * @return array<string, string>
     */
    private function buildReferenceSheet(Spreadsheet $spreadsheet): array
    {
        $sheet = new Worksheet($spreadsheet, self::REFERENCE_SHEET);
        $spreadsheet->addSheet($sheet);
        $sheet->getTabColor()->setRGB('2E75B6');

        $relationships = array_merge(['Head'], (new FamilyFormOptionsModel())->getOptions()['relationships'] ?? []);

        $ranges = [];
        $ranges['relationship'] = $this->writeList($sheet, 'A', 'Relationships', $relationships);
        $ranges['suffix']       = $this->writeList($sheet, 'B', 'Suffixes', FamilyProfilingFormV2::suffixes());
        $ranges['sex']          = $this->writeList($sheet, 'C', 'Sexes', ['MALE', 'FEMALE']);
        $ranges['civilstatus']  = $this->writeList($sheet, 'D', 'CivilStatus (code - name)', $this->codeNameList(self::CIVIL_STATUS_CODES, FamilyProfilingFormV2::civilStatuses()));
        $ranges['religion']     = $this->writeList($sheet, 'E', 'Religions', FamilyProfilingFormV2::religions());
        $ranges['education']    = $this->writeList($sheet, 'F', 'Education (code - name)', $this->codeNameList(self::EDUCATION_CODES, FamilyProfilingFormV2::educationLevels()));
        $ranges['job']          = $this->writeList($sheet, 'G', 'Jobs', FamilyProfilingFormV2::jobOptions());
        $ranges['income']       = $this->writeList($sheet, 'H', 'MonthlyIncome', $this->incomeLabels());
        $ranges['barangay']     = $this->writeList($sheet, 'I', 'Barangays', (new \App\Models\Lookups\BarangayModel())->activeNames());

        $sheet->setCellValue('K1', 'Sector code');
        $sheet->setCellValue('L1', 'Sector name');
        $row = 2;
        foreach ((new SectorModel())->getActive() as $sector) {
            $sheet->setCellValueExplicit('K' . $row, (string) ($sector['shortcode'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue('L' . $row, (string) ($sector['name'] ?? ''));
            $row++;
        }

        $sheet->setCellValue('N1', 'Service code');
        $sheet->setCellValue('O1', 'Service name');
        $sheet->setCellValue('P1', 'Service category');
        $row = 2;
        foreach ((new ServiceModel())->getActive() as $service) {
            $sheet->setCellValueExplicit('N' . $row, (string) ($service['shortcode'] ?? ''), DataType::TYPE_STRING);
            $sheet->setCellValue('O' . $row, (string) ($service['name'] ?? ''));
            $sheet->setCellValue('P' . $row, (string) ($service['category'] ?? ''));
            $row++;
        }

        foreach (range('A', 'P') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $this->styleFlatHeader($sheet, 'A1:P1');
        $sheet->freezePane('A2');
        $sheet->setSelectedCell('A1');

        return $ranges;
    }

    /** Writes the Families entry sheet with banners, headers, dropdowns, striping. */
    private function buildDataSheet(Spreadsheet $spreadsheet, array $ranges): void
    {
        $sheet = $spreadsheet->getSheet(0);
        $sheet->setTitle(self::DATA_SHEET);
        $sheet->getTabColor()->setRGB('548235');

        $this->writeBanners($sheet);
        $this->writeHeaderRow($sheet);
        $this->addCheckColumn($sheet);

        $lastColumn = $this->columnLetter(count(self::COLUMNS));
        $firstRow = self::FIRST_DATA_ROW;
        $lastRow = self::LAST_TEMPLATE_ROW;

        // QR Number, Birthday + contact number as text so long/leading-zero
        // numbers and dates survive Excel round-trip.
        $sheet->getStyle('A' . $firstRow . ':A' . $lastRow)->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('G' . $firstRow . ':G' . $lastRow)->getNumberFormat()->setFormatCode('@');
        $sheet->getStyle('J' . $firstRow . ':J' . $lastRow)->getNumberFormat()->setFormatCode('@');

        $this->applyDropdown($sheet, 'B', $ranges['relationship']);
        $this->applyDropdown($sheet, 'F', $ranges['suffix']);
        $this->applyDropdown($sheet, 'H', $ranges['sex']);
        $this->applyDropdown($sheet, 'I', $ranges['civilstatus']);
        $this->applyDropdown($sheet, 'K', $ranges['religion']);
        $this->applyDropdown($sheet, 'L', $ranges['education']);
        $this->applyDropdown($sheet, 'M', $ranges['job']);
        $this->applyDropdown($sheet, 'N', $ranges['income']);
        $this->applyDropdown($sheet, 'P', $ranges['barangay']);

        // Free-text columns get a prompt-only tooltip.
        $this->applyPromptOnly($sheet, 'A');
        $this->applyPromptOnly($sheet, 'G');
        $this->applyPromptOnly($sheet, 'J');
        $this->applyPromptOnly($sheet, 'O');
        $this->applyPromptOnly($sheet, 'Q');
        $this->applyPromptOnly($sheet, 'R');

        // Column widths.
        $index = 1;
        foreach (self::COLUMNS as $heading) {
            $sheet->getColumnDimension($this->columnLetter($index))->setWidth((float) (self::WIDTHS[$heading] ?? 14));
            $index++;
        }

        // Zebra striping + light borders across the entry area.
        $entryRange = 'A' . $firstRow . ':' . $lastColumn . $lastRow;
        $zebra = new Conditional();
        $zebra->setConditionType(Conditional::CONDITION_EXPRESSION)->setConditions(['MOD(ROW(),2)=1']);
        $zebra->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F6FC');
        $zebra->getStyle()->getFill()->getEndColor()->setRGB('F2F6FC');
        $sheet->getStyle($entryRange)->setConditionalStyles([$zebra]);
        $sheet->getStyle($entryRange)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');

        $sheet->getRowDimension(self::HEADER_ROW)->setRowHeight(28);
        // Freeze the banner + header rows and the FamilyNo/Relationship columns.
        $sheet->freezePane('C' . self::FIRST_DATA_ROW);
        $sheet->setSelectedCell('A' . self::FIRST_DATA_ROW);
    }

    /**
     * Builds the read-only Example sheet: same banners + headers, greyed example rows
     * for TWO families, and a note. Never imported.
     */
    private function buildExampleSheet(Spreadsheet $spreadsheet): void
    {
        $sheet = new Worksheet($spreadsheet, self::EXAMPLE_SHEET);
        $spreadsheet->addSheet($sheet);
        $sheet->getTabColor()->setRGB('A6A6A6');

        $this->writeBanners($sheet);
        $this->writeHeaderRow($sheet);

        $this->writeExampleRow($sheet, 3, ['1', 'Head', 'Dela Cruz', 'Juan', 'Santos', '', '05-14-1958', 'Male', 'M - Married', '09171234567', 'Roman Catholic', 'CG - College Graduate', 'Retired', 'PHP 8,000 - 13,000', '123 Rizal St.', 'Poblacion', 'SC', 'SC1, SC2, FA6']);
        $this->writeExampleRow($sheet, 4, ['1', 'Spouse', 'Dela Cruz', 'Maria', 'Reyes', '', '09-02-1962', 'Female', 'M - Married', '09170001111', 'Roman Catholic', 'HS - High School', 'Homemaker', 'No regular income', '', '', 'SP', 'SP1']);
        $this->writeExampleRow($sheet, 5, ['1', 'Child', 'Dela Cruz', 'Jose', 'R', '', '01-10-2014', 'Male', 'S - Single', '', '', 'E - Elementary', 'Student', '', '', '', 'B', 'B2, B3']);
        $this->writeExampleRow($sheet, 6, ['2', 'Head', 'Reyes', 'Pedro', '', '', '07-07-1990', 'Male', 'S - Single', '09181234567', 'Islam', 'UG - Undergraduate', 'Driver', 'PHP 13,001 - 18,000', '5 Mabini St.', 'Malaban', 'PWD, IP', 'PWD1, EDA5, 4PS']);

        $lastColumn = $this->columnLetter(count(self::COLUMNS));
        $noteRow = 8;
        $sheet->setCellValue('A' . $noteRow, 'Examples only - enter real data on the "' . self::DATA_SHEET . '" sheet. One row per person. Name order is Last Name, First Name, Middle Name. Birthday is MM-DD-YYYY. Mark each head of family with Relationship = Head. Put as many families as you like in one file: each family gets its own QR number, shared by its members. Members leave Address and Barangay blank - they automatically use the head\'s address. SECTOR = WHO the person is (SC, PWD, SP, B, LGBT, OFW, IP, IDP, PDL, or OTHER) and SERVICES = the programs they RECEIVED (e.g. SC1, FA6, EDA5, 4PS) - both take CODES separated by commas; see the Reference sheet. " * " marks the columns needed for a complete record (incl. Birthday, Sex, Civil Status, Education, Job and Monthly Income). Blank values import as missing: they do not block the import, and the family stays on the Data Completeness report until the data is collected. The Head row also carries the family\'s Address and Barangay.');
        $sheet->mergeCells('A' . $noteRow . ':' . $lastColumn . $noteRow);
        $sheet->getStyle('A' . $noteRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle('A' . $noteRow)->getFont()->setBold(true);
        $sheet->getRowDimension($noteRow)->setRowHeight(70);

        $index = 1;
        foreach (self::COLUMNS as $heading) {
            $sheet->getColumnDimension($this->columnLetter($index))->setWidth((float) (self::WIDTHS[$heading] ?? 14));
            $index++;
        }
        $sheet->freezePane('C' . self::FIRST_DATA_ROW);
    }

    /**
     * Adds a helper "Check" column after the data columns: a per-row formula that flags
     * problems live in Excel (missing fields, head counts, duplicate QR across families,
     * several addresses under one QR), colored red for Must fix output, yellow for
     * incomplete-data output (Missing Income), green for OK. Excel-only preflight: the
     * importer never reads this column - the server-side import validates everything.
     */
    private function addCheckColumn(Worksheet $sheet): void
    {
        $col = $this->columnLetter(count(self::COLUMNS) + 1);

        // Banner cell (row 1).
        $sheet->setCellValue($col . '1', 'CHECK');
        $banner = $sheet->getStyle($col . '1');
        $banner->getFont()->setBold(true)->getColor()->setRGB('7F6000');
        $banner->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $banner->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
        $banner->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('FFFFFF');

        // Header (row 2).
        $headerCell = $col . self::HEADER_ROW;
        $sheet->setCellValue($headerCell, 'Check');
        $this->styleFlatHeader($sheet, $headerCell . ':' . $headerCell);
        $sheet->getStyle($headerCell)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getComment($headerCell)->getText()->createTextRun('Excel-only preflight. Fix any row that is not "OK" before importing; a yellow "Missing Income" imports as missing data and enters Data Completeness. This column is ignored on import.');
        $sheet->getColumnDimension($col)->setWidth(26);

        // Per-row validation formula.
        for ($row = self::FIRST_DATA_ROW; $row <= self::LAST_TEMPLATE_ROW; $row++) {
            $sheet->setCellValue($col . $row, $this->checkFormula($row));
        }

        // Red for Must fix output, yellow for the single incomplete-data outcome
        // (Missing Income), green when the row is ready.
        $range = $col . self::FIRST_DATA_ROW . ':' . $col . self::LAST_TEMPLATE_ROW;
        $mustFix = new Conditional();
        $mustFix->setConditionType(Conditional::CONDITION_EXPRESSION)->setConditions(['AND($' . $col . self::FIRST_DATA_ROW . '<>"",$' . $col . self::FIRST_DATA_ROW . '<>"OK",$' . $col . self::FIRST_DATA_ROW . '<>"Missing Income")']);
        $mustFix->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC7CE');
        $mustFix->getStyle()->getFill()->getEndColor()->setRGB('FFC7CE');
        $mustFix->getStyle()->getFont()->setBold(true)->getColor()->setRGB('9C0006');
        $incomplete = new Conditional();
        $incomplete->setConditionType(Conditional::CONDITION_EXPRESSION)->setConditions(['$' . $col . self::FIRST_DATA_ROW . '="Missing Income"']);
        $incomplete->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF2CC');
        $incomplete->getStyle()->getFill()->getEndColor()->setRGB('FFF2CC');
        $incomplete->getStyle()->getFont()->setBold(true)->getColor()->setRGB('7F6000');
        $ok = new Conditional();
        $ok->setConditionType(Conditional::CONDITION_EXPRESSION)->setConditions(['$' . $col . self::FIRST_DATA_ROW . '="OK"']);
        $ok->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('C6EFCE');
        $ok->getStyle()->getFill()->getEndColor()->setRGB('C6EFCE');
        $ok->getStyle()->getFont()->getColor()->setRGB('006100');
        $sheet->getStyle($range)->setConditionalStyles([$mustFix, $incomplete, $ok]);
    }

    /**
     * Builds the Check-column formula for one row. Blank rows show nothing; otherwise it
     * reports the first problem found, else "OK". Row conditions: empty QR/relationship/
     * name, and blank income as the yellow incomplete-data outcome. Family-block
     * conditions read the whole QR + flag columns (COUNTIFS / SUMPRODUCT across the
     * entry area): no head, multiple heads, two blocks sharing a QR with different
     * head identities, or several addresses under one QR. Columns: A = QR Number,
     * B = Relationship, C = LastName, D = FirstName, N = MonthlyIncome, O = Address.
     */
    private function checkFormula(int $row): string
    {
        $from = self::FIRST_DATA_ROW;
        $to = self::LAST_TEMPLATE_ROW;
        $aRange = '$A$' . $from . ':$A$' . $to;
        $bRange = '$B$' . $from . ':$B$' . $to;
        $cRange = '$C$' . $from . ':$C$' . $to;
        $dRange = '$D$' . $from . ':$D$' . $to;
        $oRange = '$O$' . $from . ':$O$' . $to;
        $a = '$A' . $row;
        $b = '$B' . $row;
        $c = '$C' . $row;
        $d = '$D' . $row;
        $n = '$N' . $row;

        $heads = 'COUNTIFS(' . $aRange . ',' . $a . ',' . $bRange . ',"Head")';
        // Two separated blocks sharing one QR each carry their own Head; distinct head
        // identities under a QR is the preflight's Duplicate-QR signal. The denominator
        // mirrors every range (A/B/C/D) in its criteria pairs, so each row's own key
        // counts itself and no element divides by zero.
        $headIdentities = 'SUMPRODUCT((' . $aRange . '=' . $a . ')*(' . $bRange . '="Head")/COUNTIFS(' . $aRange . ',' . $aRange . ',' . $bRange . ',' . $bRange . ',' . $cRange . ',' . $cRange . ',' . $dRange . ',' . $dRange . '))';
        // One QR = one household: more than one non-blank address under a QR means rows
        // from two different households were pasted together. The denominator mirrors the
        // A and O ranges in its criteria pairs for the same zero-division reason.
        $addresses = 'SUMPRODUCT((' . $aRange . '=' . $a . ')*(' . $oRange . '<>"")/COUNTIFS(' . $aRange . ',' . $aRange . ',' . $oRange . ',' . $oRange . '))';

        // Innermost "OK" first; each issue wraps the previous one as its else-branch,
        // so the formula evaluates from the outermost check inward. A row with more
        // than one distinct Head person under a QR is almost certainly two households
        // sharing it, so Duplicate QR is reported before the leftover extra-heads case;
        // Missing Income (yellow incomplete data) is reached only when nothing Must fix
        // is left.
        $formula = '"OK"';
        $checks = [
            $n . '=""'             => 'Missing Income',
            $addresses . '>1'       => 'Multiple Addresses in Family',
            $heads . '>1'           => 'Multiple Heads (Same Family)',
            $headIdentities . '>1'  => 'Duplicate QR (Multiple Families)',
            $heads . '=0'           => 'No Head in Family',
            $d . '=""'             => 'Missing FirstName',
            $c . '=""'             => 'Missing LastName',
            $b . '=""'             => 'Missing Relationship',
            $a . '=""'             => 'Missing QR',
        ];

        foreach ($checks as $condition => $label) {
            $formula = 'IF(' . $condition . ',"' . $label . '",' . $formula . ')';
        }

        $blank = 'AND(' . $a . '="",' . $b . '="",' . $c . '="",' . $d . '="")';

        return '=IF(' . $blank . ',"",' . $formula . ')';
    }

    // -- shared header pieces --------------------------------------------------

    /** Writes the merged, colored group-banner row (row 1). */
    private function writeBanners(Worksheet $sheet): void
    {
        foreach (self::BANNERS as [$start, $end, $label, $rgb]) {
            $range = $this->columnLetter($start) . '1:' . $this->columnLetter($end) . '1';
            $sheet->mergeCells($range);
            $sheet->setCellValue($this->columnLetter($start) . '1', $label);
            $style = $sheet->getStyle($range);
            $style->getFont()->setBold(true)->getColor()->setRGB('1F3864');
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
            $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER);
            $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('FFFFFF');
        }
        $sheet->getRowDimension(1)->setRowHeight(20);
    }

    /** Writes the column-header row (row 2): required markers, comments, styling. */
    private function writeHeaderRow(Worksheet $sheet): void
    {
        $index = 1;
        foreach (self::COLUMNS as $heading) {
            $letter = $this->columnLetter($index);
            $cell = $letter . self::HEADER_ROW;
            $display = $heading . (in_array($heading, self::ALWAYS_REQUIRED, true) ? ' *' : '');
            $sheet->setCellValue($cell, $display);

            if (in_array($heading, self::HEAD_REQUIRED, true)) {
                $sheet->getComment($cell)->getText()->createTextRun('Required for the Head of family row.');
            }

            $index++;
        }

        $this->styleFlatHeader($sheet, 'A' . self::HEADER_ROW . ':' . $this->columnLetter(count(self::COLUMNS)) . self::HEADER_ROW);
        $sheet->getStyle('A' . self::HEADER_ROW . ':' . $this->columnLetter(count(self::COLUMNS)) . self::HEADER_ROW)
            ->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_CENTER);
    }

    /**
     * The dropdown option lists the data sheet attaches to each column, keyed by the importer's
     * normalized field. Single source of truth for BOTH the Excel data-validation dropdowns and
     * the import reviewer's inline <select> cells, so the two always offer the same choices.
     * Sector/Services are omitted - they are comma-separated code lists, not single dropdowns.
     *
     * @return array<string, list<string>>
     */
    public function dropdownOptions(): array
    {
        $relationships = array_merge(['Head'], (new FamilyFormOptionsModel())->getOptions()['relationships'] ?? []);

        return [
            'relationship'  => $relationships,
            'suffix'        => FamilyProfilingFormV2::suffixes(),
            'sex'           => ['MALE', 'FEMALE'],
            'civilstatus'   => $this->codeNameList(self::CIVIL_STATUS_CODES, FamilyProfilingFormV2::civilStatuses()),
            'religion'      => FamilyProfilingFormV2::religions(),
            'education'     => $this->codeNameList(self::EDUCATION_CODES, FamilyProfilingFormV2::educationLevels()),
            'job'           => FamilyProfilingFormV2::jobOptions(),
            'monthlyincome' => $this->incomeLabels(),
            'barangay'      => (new \App\Models\Lookups\BarangayModel())->activeNames(),
        ];
    }

    // -- low-level helpers -----------------------------------------------------

    /**
     * Writes a header at row 1 and the given list of values down a column,
     * returning the absolute range for use as a dropdown source.
     *
     * @param list<string> $values
     */
    private function writeList(Worksheet $sheet, string $column, string $header, array $values): string
    {
        $sheet->setCellValue($column . '1', $header);

        $row = 2;
        foreach ($values as $value) {
            $sheet->setCellValueExplicit($column . $row, (string) $value, DataType::TYPE_STRING);
            $row++;
        }

        return sprintf('%s!$%s$2:$%s$%d', self::REFERENCE_SHEET, $column, $column, max(2, $row - 1));
    }

    /**
     * Builds "CODE - Name" option strings for a code map, in $allValues order.
     *
     * @param array<string,string> $codeMap   code => full value
     * @param list<string>         $allValues full values in display order
     * @return list<string>
     */
    private function codeNameList(array $codeMap, array $allValues): array
    {
        $byValue = [];
        foreach ($codeMap as $code => $value) {
            $byValue[strtolower($value)] = $code;
        }

        $options = [];
        foreach ($allValues as $value) {
            $code = $byValue[strtolower($value)] ?? '';
            $options[] = $code !== '' ? ($code . ' - ' . $value) : $value;
        }

        return $options;
    }

    /** Attaches a list dropdown (sourced from a Reference range) to a column's entry rows. */
    private function applyDropdown(Worksheet $sheet, string $column, string $range): void
    {
        $heading = self::COLUMNS[$this->columnIndex($column) - 1] ?? '';
        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_LIST);
        $validation->setErrorStyle(DataValidation::STYLE_INFORMATION);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setShowDropDown(true);
        $validation->setErrorTitle('Invalid entry');
        $validation->setError('Pick a value from the dropdown list.');

        if (isset(self::PROMPTS[$heading])) {
            $validation->setPromptTitle($heading);
            $validation->setPrompt(self::PROMPTS[$heading]);
        }

        $validation->setFormula1($range);
        $sheet->setDataValidation($column . self::FIRST_DATA_ROW . ':' . $column . self::LAST_TEMPLATE_ROW, $validation);
    }

    /** Attaches a prompt-only (no list) tooltip to a free-text column. */
    private function applyPromptOnly(Worksheet $sheet, string $column): void
    {
        $heading = self::COLUMNS[$this->columnIndex($column) - 1] ?? '';

        if (! isset(self::PROMPTS[$heading])) {
            return;
        }

        $validation = new DataValidation();
        $validation->setType(DataValidation::TYPE_NONE);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setPromptTitle($heading);
        $validation->setPrompt(self::PROMPTS[$heading]);

        $sheet->setDataValidation($column . self::FIRST_DATA_ROW . ':' . $column . self::LAST_TEMPLATE_ROW, $validation);
    }

    /** Writes one greyed, italic example data row. @param list<string> $values */
    private function writeExampleRow(Worksheet $sheet, int $row, array $values): void
    {
        $index = 1;
        foreach ($values as $value) {
            $sheet->setCellValueExplicit($this->columnLetter($index) . $row, (string) $value, DataType::TYPE_STRING);
            $index++;
        }

        $range = 'A' . $row . ':' . $this->columnLetter(count(self::COLUMNS)) . $row;
        $style = $sheet->getStyle($range);
        $style->getFont()->setItalic(true)->getColor()->setRGB('808080');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D9D9D9');
    }

    /** Bold white-on-blue flat header styling for a range. */
    private function styleFlatHeader(Worksheet $sheet, string $range): void
    {
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('2F5496');
        $style->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT)->setVertical(Alignment::VERTICAL_CENTER);
        $style->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('FFFFFF');
    }

    /** 1-based column index -> letter (A, B, ... Z, AA). */
    private function columnLetter(int $index): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index);
    }

    /** Column letter -> 1-based index. */
    private function columnIndex(string $letter): int
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($letter);
    }

    /**
     * Income bracket labels (without the placeholder "Select") for the dropdown.
     *
     * @return list<string>
     */
    private function incomeLabels(): array
    {
        $labels = [];

        foreach ((new FamilyFormOptionsModel())->getOptions()['income_ranges'] ?? [] as $range) {
            $value = (string) ($range['value'] ?? '');
            $label = (string) ($range['label'] ?? '');

            if ($value === '' || $label === '') {
                continue;
            }

            $labels[] = $label;
        }

        return $labels;
    }
}
