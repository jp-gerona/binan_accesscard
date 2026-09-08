<?php

namespace App\Libraries;

/**
 * Builds the human-readable "changes you've made" log for the Import Review screen: a
 * before/after diff of one QR group's staged rows after an in-place Edit, or a note for a
 * Remove. Entries are appended to the staged bundle so they survive re-staging and a page
 * reload, and are shown so the worker can double-check their edits before committing.
 *
 * Pure - no DB, request, or session - so it is unit-testable and reused by
 * FamilyImportController.
 */
class ImportReviewChangeLog
{
    /** Person fields worth showing in a diff line, in display order. */
    private const FIELDS = [
        'relationship' => 'Relationship', 'lastname' => 'Last Name', 'firstname' => 'First Name',
        'middlename' => 'Middle Name', 'suffix' => 'Suffix', 'birthday' => 'Birthday',
        'sex' => 'Sex', 'civilstatus' => 'Civil Status', 'contactnumber' => 'Contact Number',
        'religion' => 'Religion', 'education' => 'Education', 'job' => 'Job',
        'monthlyincome' => 'Monthly Income', 'address' => 'Address', 'barangay' => 'Barangay',
        'sector' => 'Sector', 'services' => 'Services',
    ];

    /**
     * A change entry for an in-place edit, or null when nothing actually changed. Rows are
     * matched by sheet row: a row present in both is diffed field-by-field; a row only in the
     * new set is "Added", only in the old set is "Removed".
     *
     * @param list<array{sheetRow:int,data:array<string,string>}> $oldRows
     * @param list<array{sheetRow:int,data:array<string,string>}> $newRows
     * @return array{at:string,action:string,qr:string,head:string,lines:list<string>}|null
     */
    public static function edited(array $oldRows, array $newRows): ?array
    {
        $oldBy = self::bySheetRow($oldRows);
        $newBy = self::bySheetRow($newRows);

        $lines = [];

        $oldQr = self::groupQr($oldRows);
        $newQr = self::groupQr($newRows);
        if ($oldQr !== $newQr) {
            $lines[] = 'QR Number: ' . self::show($oldQr) . ' → ' . self::show($newQr);
        }

        foreach ($newBy as $sheetRow => $new) {
            if (! isset($oldBy[$sheetRow])) {
                $lines[] = 'Added ' . self::personName($new);
                continue;
            }

            $old = $oldBy[$sheetRow];
            foreach (self::FIELDS as $key => $label) {
                $o = trim((string) ($old[$key] ?? ''));
                $n = trim((string) ($new[$key] ?? ''));

                if (self::norm($o) !== self::norm($n)) {
                    $lines[] = self::personName($new) . ' · ' . $label . ': ' . self::show($o) . ' → ' . self::show($n);
                }
            }
        }

        foreach ($oldBy as $sheetRow => $old) {
            if (! isset($newBy[$sheetRow])) {
                $lines[] = 'Removed ' . self::personName($old);
            }
        }

        if ($lines === []) {
            return null;
        }

        return self::entry('Edited', $newRows !== [] ? $newRows : $oldRows, $lines);
    }

    /**
     * Records the duplicate copies omitted while keeping one candidate active.
     *
     * @param list<array{sheetRow:int,data:array<string,string>}> $rows
     * @return array{at:string,action:string,qr:string,head:string,lines:list<string>}
     */
    public static function discarded(array $rows, int $keptRow): array
    {
        $lines = ['Kept duplicate candidate row ' . $keptRow . '.'];

        foreach ($rows as $row) {
            $sheetRow = (int) ($row['sheetRow'] ?? 0);

            if ($sheetRow !== $keptRow) {
                $lines[] = 'Discarded duplicate row ' . $sheetRow . ' ('
                    . self::personName(is_array($row['data'] ?? null) ? $row['data'] : []) . ').';
            }
        }

        return self::entry('Discarded', $rows, $lines);
    }

    /**
     * Records a duplicate candidate returning to the active validation set.
     *
     * @param list<array{sheetRow:int,data:array<string,string>}> $rows
     * @return array{at:string,action:string,qr:string,head:string,lines:list<string>}
     */
    public static function restored(array $rows, int $sheetRow): array
    {
        $row = [];

        foreach ($rows as $candidate) {
            if ((int) ($candidate['sheetRow'] ?? 0) === $sheetRow) {
                $row = is_array($candidate['data'] ?? null) ? $candidate['data'] : [];
                break;
            }
        }

        return self::entry('Restored', $rows, ['Restored duplicate row ' . $sheetRow . ' (' . self::personName($row) . ').']);
    }

    /**
     * @param list<array{sheetRow:int,data:array<string,string>}> $rows
     * @param list<string>                                        $lines
     * @return array{at:string,action:string,qr:string,head:string,lines:list<string>}
     */
    private static function entry(string $action, array $rows, array $lines): array
    {
        return [
            'at'     => date('g:i A'),
            'action' => $action,
            'qr'     => self::groupQr($rows),
            'head'   => self::personName(self::headRow($rows)),
            'lines'  => array_values($lines),
        ];
    }

    /** @return array<int,array<string,string>> */
    private static function bySheetRow(array $rows): array
    {
        $out = [];

        foreach ($rows as $r) {
            $out[(int) ($r['sheetRow'] ?? 0)] = is_array($r['data'] ?? null) ? $r['data'] : [];
        }

        return $out;
    }

    /** @param array<string,string> $data */
    private static function personName(array $data): string
    {
        $name = trim((string) ($data['firstname'] ?? '') . ' ' . (string) ($data['lastname'] ?? ''));

        return $name !== '' ? $name : 'this person';
    }

    /** @return array<string,string> */
    private static function headRow(array $rows): array
    {
        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];

            if (strcasecmp(trim((string) ($data['relationship'] ?? '')), 'Head') === 0) {
                return $data;
            }
        }

        $first = $rows[0]['data'] ?? [];

        return is_array($first) ? $first : [];
    }

    private static function groupQr(array $rows): string
    {
        foreach ($rows as $r) {
            $data = is_array($r['data'] ?? null) ? $r['data'] : [];
            $qr   = trim((string) ($data['familyno'] ?? ''));

            if ($qr !== '') {
                return $qr;
            }
        }

        return '';
    }

    private static function show(string $value): string
    {
        return $value === '' ? '(blank)' : '"' . $value . '"';
    }

    private static function norm(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }
}
