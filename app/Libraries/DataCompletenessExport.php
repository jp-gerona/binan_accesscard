<?php

namespace App\Libraries;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Builds the Card Readiness .xlsx checklist, one active family head per row.
 */
class DataCompletenessExport
{
    /**
     * Assemble the filtered Card Readiness rows, retaining literal values even
     * when an imported name or address starts with a spreadsheet formula sign.
     *
     * @param list<array{control_no: int|string|null, firstname: string|null, lastname: string|null, suffix: string|null, sex: string|null, birthday: string|null, address: string|null, contactnumber: string|null, barangay: string|null, missing: list<string>}> $families
     */
    public static function build(array $families): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Card Readiness');

        $headers = ['Control Number', 'Head', 'Sex', 'Birthday', 'Address', 'Contact Number', 'Barangay', 'Missing Card Fields'];
        foreach ($headers as $index => $header) {
            $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1) . '1')->setValue($header);
        }

        $row = 2;
        foreach ($families as $family) {
            $marks = array_fill_keys($family['missing'], 'MISSING');
            $head = trim(implode(' ', array_filter([
                trim((string) ($family['firstname'] ?? '')),
                trim((string) ($family['lastname'] ?? '')),
                trim((string) ($family['suffix'] ?? '')),
            ], static fn (string $value): bool => $value !== '')));
            $values = [
                $marks['Control Number'] ?? (string) ($family['control_no'] ?? ''),
                $head,
                $marks['Sex'] ?? (string) ($family['sex'] ?? ''),
                $marks['Birthday'] ?? (string) ($family['birthday'] ?? ''),
                $marks['Address'] ?? (string) ($family['address'] ?? ''),
                $marks['Contact Number'] ?? (string) ($family['contactnumber'] ?? ''),
                $marks['Barangay'] ?? (string) ($family['barangay'] ?? ''),
                implode(', ', $family['missing']),
            ];

            foreach ($values as $index => $value) {
                $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1) . $row)
                    ->setValueExplicit((string) $value, DataType::TYPE_STRING);
            }
            $row++;
        }

        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        return $spreadsheet;
    }
}
