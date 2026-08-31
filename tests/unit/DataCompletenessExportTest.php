<?php

namespace Tests\Unit;

use App\Libraries\DataCompletenessExport;
use PHPUnit\Framework\TestCase;

final class DataCompletenessExportTest extends TestCase
{
    public function testBuildsOneRowPerPersonWithMissingMarkers(): void
    {
        $families = [
            ['qr' => 6001, 'head' => 'Juan Cruz', 'barangay' => 'Malaban',
             'headGaps' => ['Monthly Income', 'Address'], 'members' => [
                 ['name' => 'Jose Cruz', 'relationship' => 'CHILD', 'gaps' => ['Education']],
             ], 'gapCount' => 3],
        ];

        $sheet = DataCompletenessExport::build($families)->getActiveSheet();

        $this->assertSame('Data Completeness', $sheet->getTitle());
        // Header row. Columns: A QR, B Family Head, C Barangay, D Member,
        // E Relationship, F Birthday, G Sex, H Civil Status, I Education,
        // J Job, K Monthly Income, L Address.
        $this->assertSame('QR', $sheet->getCell('A1')->getValue());
        $this->assertSame('Member', $sheet->getCell('D1')->getValue());
        // Head row: the two head gaps marked.
        $this->assertSame('Juan Cruz', $sheet->getCell('D2')->getValue());
        $this->assertSame('MISSING', $sheet->getCell('K2')->getValue()); // Monthly Income
        $this->assertSame('MISSING', $sheet->getCell('L2')->getValue()); // Address
        // Member row: its gap marked, head gaps not repeated.
        $this->assertSame('Jose Cruz', $sheet->getCell('D3')->getValue());
        $this->assertSame('MISSING', $sheet->getCell('I3')->getValue()); // Education
        $this->assertNotSame('MISSING', (string) $sheet->getCell('K3')->getValue());
    }
}