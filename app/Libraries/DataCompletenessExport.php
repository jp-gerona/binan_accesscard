<?php

namespace App\Libraries;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Builds the Data Completeness .xlsx checklist: one row per person whose
 * record carries a blank (the head first, then the gapped members), with
 * MISSING marking each blank field. The encoder prints it or brings it on
 * field work and ticks people off as the data is collected; the fixes
 * themselves happen through the family edit form, which is audit-trailed.
 */
class DataCompletenessExport
{
    /**
     * Assemble the .xlsx worksheet from the filtered completeness family list.
     *
     * @param list<array{qr: ?int, head: string, barangay: string, headGaps: list<string>, members: list<array{name: string, relationship: string, gaps: list<string}>}> $families
     */
    public static function build(array $families): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Data Completeness');

        $headers = ['QR', 'Family Head', 'Barangay', 'Member', 'Relationship',
            'Birthday', 'Sex', 'Civil Status', 'Education', 'Job', 'Monthly Income', 'Address'];

        // Cell writes go through Coordinate::stringFromColumnIndex() so the
        // installed PhpSpreadsheet does not hit the removed byColumnAndRow
        // family; the address form is what 5.x documents.
        foreach ($headers as $index => $header) {
            $coordinate = Coordinate::stringFromColumnIndex($index + 1) . '1';
            $sheet->getCell($coordinate)->setValue($header);
        }

        $row = 2;

        foreach ($families as $family) {
            $personRows = array_merge(
                [['name' => $family['head'], 'relationship' => 'HEAD', 'gaps' => $family['headGaps']]],
                $family['members']
            );

            foreach ($personRows as $person) {
                $marks = array_fill_keys($person['gaps'], 'MISSING');

                $values = [
                    (string) ($family['qr'] ?? ''),
                    $family['head'],
                    $marks['Barangay'] ?? $family['barangay'],
                    $person['name'],
                    $person['relationship'],
                    $marks['Birthday'] ?? '',
                    $marks['Sex'] ?? '',
                    $marks['Civil Status'] ?? '',
                    $marks['Education'] ?? '',
                    $marks['Job'] ?? '',
                    $marks['Monthly Income'] ?? '',
                    $marks['Address'] ?? '',
                ];

                foreach ($values as $index => $value) {
                    $coordinate = Coordinate::stringFromColumnIndex($index + 1) . $row;
                    $sheet->getCell($coordinate)->setValueExplicit((string) $value, DataType::TYPE_STRING);
                }

                $row++;
            }
        }

        foreach (range(1, count($headers)) as $column) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
        }

        return $spreadsheet;
    }
}