<?php

namespace Tests\Unit;

use App\Libraries\DataCompletenessExport;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PHPUnit\Framework\TestCase;

final class DataCompletenessExportTest extends TestCase
{
    public function testFormulaLikeCardDataCellsAreExplicitStrings(): void
    {
        $sheet = DataCompletenessExport::build([[
            'control_no' => '=6001', 'firstname' => '=JUAN', 'lastname' => '=CRUZ', 'suffix' => null,
            'sex' => 'MALE', 'birthday' => '1990-01-01', 'address' => '=ADDRESS',
            'contactnumber' => '=09171234567', 'barangay' => '=BARANGAY', 'missing' => [],
        ]])->getActiveSheet();

        foreach (['A2', 'B2', 'C2', 'D2', 'G2', 'H2', 'I2'] as $coordinate) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($coordinate)->getDataType());
        }
    }

    public function testBuildsOneHeadRowWithMissingMarkers(): void
    {
        $rows = [[
            'control_no' => 6001, 'firstname' => 'Juan', 'lastname' => 'Cruz', 'suffix' => 'JR',
            'sex' => null, 'birthday' => '1990-01-01', 'address' => null,
            'contactnumber' => '09171234567', 'barangay' => 'Malaban',
            'missing' => ['Sex', 'Address'],
        ]];

        $sheet = DataCompletenessExport::build($rows)->getActiveSheet();

        $this->assertSame('Card Readiness', $sheet->getTitle());
        $this->assertSame('Control Number', $sheet->getCell('A1')->getValue());
        $this->assertSame('First Name', $sheet->getCell('B1')->getValue());
        $this->assertSame('Last Name', $sheet->getCell('C1')->getValue());
        $this->assertSame('Suffix', $sheet->getCell('D1')->getValue());
        $this->assertSame('Juan', $sheet->getCell('B2')->getValue());
        $this->assertSame('Cruz', $sheet->getCell('C2')->getValue());
        $this->assertSame('JR', $sheet->getCell('D2')->getValue());
        $this->assertSame('MISSING', $sheet->getCell('E2')->getValue());
        $this->assertSame('1990-01-01', $sheet->getCell('F2')->getValue());
        $this->assertSame('MISSING', $sheet->getCell('G2')->getValue());
        $this->assertSame('Malaban', $sheet->getCell('I2')->getValue());
    }

    public function testMissingRequiredNameColumnsDoNotHideASuppliedSuffix(): void
    {
        $sheet = DataCompletenessExport::build([[
            'control_no' => 6002, 'firstname' => null, 'lastname' => null, 'suffix' => 'III',
            'sex' => 'MALE', 'birthday' => '1990-01-01', 'address' => 'SAMPLE STREET',
            'contactnumber' => '09171234567', 'barangay' => 'Malaban',
            'missing' => ['First Name', 'Last Name'],
        ]])->getActiveSheet();

        $this->assertSame('MISSING', $sheet->getCell('B2')->getValue());
        $this->assertSame('MISSING', $sheet->getCell('C2')->getValue());
        $this->assertSame('III', $sheet->getCell('D2')->getValue());
    }
}
