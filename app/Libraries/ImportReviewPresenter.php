<?php

namespace App\Libraries;

/**
 * Shapes a staged family-import job (the review-phase bundle produced by
 * App\Jobs\FamilyImportJob) into what the review screen renders.
 *
 * build() returns the page-load summary: the file name, the counts, whole-file
 * notices, and the issue codes present so the filter can offer them. page()
 * returns one server-side slice of rows, so a 10,000-row file never crosses the
 * wire whole.
 *
 * A row lists every distinct issue it carries and offers an editor for every
 * field carrying one, whatever column that field belongs to. That is the rule
 * that keeps a problem from being invisible: the old table showed four columns,
 * so an error on barangay or relationship flagged a row with nothing to fix.
 *
 * Pure presentation - no DB, request, or session.
 */
class ImportReviewPresenter
{
    /**
     * Ordered buckets. Blocking problems first (they stop the import), then the
     * informational ones.
     *
     * @var array<string, array{label: string, hint: string}>
     */
    private const GROUPS = [
        'FILE'       => ['label' => 'File problem',                'hint' => 'The file could not be read. Fix it in Excel and upload again.'],
        'EMPTY'      => ['label' => 'Empty file',                  'hint' => 'No family rows were found.'],
        'QR-11'      => ['label' => 'Merged QR cells',             'hint' => 'Unmerge the QR column and repeat the QR number on every row of the family.'],
        'QR-01'      => ['label' => 'Missing QR Number',           'hint' => 'Every person needs their family QR number.'],
        'QR-FORMAT'  => ['label' => 'Invalid QR Number',           'hint' => 'Must be a whole number - no letters, decimals, or commas.'],
        'QR-05'      => ['label' => 'QR Number is zero',           'hint' => 'The QR number must be greater than zero.'],
        'QR-07'      => ['label' => 'QR Number too large',         'hint' => 'Above the allowed maximum.'],
        'QR-08'      => ['label' => 'QR Number is an error cell',  'hint' => 'The cell holds an Excel error value. Retype the number.'],
        'QR-12'      => ['label' => 'QR Number is a formula',      'hint' => 'Type the number itself, not a formula.'],
        'QR-TAKEN'   => ['label' => 'QR already assigned to another family', 'hint' => 'That QR is already used by a DIFFERENT family in the system. Correct the QR number, or give this family its own.'],
        'HEAD-NONE'  => ['label' => 'No Head in the family',       'hint' => 'Set Relationship = Head on exactly one person.'],
        'HEAD-MULTI' => ['label' => 'More than one Head',          'hint' => 'Only one person per family can be the Head.'],
        'FP-ADDR'    => ['label' => 'Two addresses under one QR',  'hint' => 'One QR = one household. Fix the mistyped QR, or give the other household its own QR.'],
        'REQUIRED'   => ['label' => 'Missing required value',      'hint' => 'Fill in the cell.'],
        'INCOMPLETE' => ['label' => 'Missing value (imports blank)', 'hint' => 'Blank cells import as blank. The family is listed on the Data Completeness report until the data is collected.'],
        'BDAY'       => ['label' => 'Invalid birthday',            'hint' => 'Could not be read; imports with a blank birthday. The family is listed on the Data Completeness report.'],
        'SEX'        => ['label' => 'Invalid sex',                 'hint' => 'Not Male or Female; imports with no sex. The family is listed on the Data Completeness report.'],
        'INCOME'     => ['label' => 'Invalid monthly income',      'hint' => 'Not a bracket or readable amount; imports with no income. The family is listed on the Data Completeness report.'],
        'SERVICE'    => ['label' => 'Invalid Service Code',        'hint' => 'The code is not on the Reference sheet. Choose a listed service code before importing.'],
        'LENGTH'     => ['label' => 'Value too long',              'hint' => 'Shorten it to fit the database limit.'],
        'ADD-MEMBER' => ['label' => 'Will be added to an existing family', 'hint' => 'The QR already belongs to a family. These people are ADDED to it on import - to skip one, delete the row from the file.'],
        'DUP-EXISTS' => ['label' => 'Already in the system',       'hint' => 'Same QR, same head (name + birthday) as a family already on file. SKIPPED on import.'],
        'DUP-DB'     => ['label' => 'Person already in the system','hint' => 'This person is already on file under another family. A HEAD already on file means the whole group is skipped - check the QR.'],
        'DUP-DIFF'   => ['label' => 'Details differ from the system', 'hint' => 'Same family, but the file disagrees with what is stored. The import skips it, so nothing here is saved - edit the record in Manage Family.'],
        'DUP-PERSON' => ['label' => 'Possible duplicate person',   'hint' => 'Same name, birthday and address as another row. Imports anyway - delete a row if it really is a duplicate.'],
        'BRGY'       => ['label' => 'Barangay not recognised',     'hint' => 'Not an official Biñan barangay; imports with no barangay. The family is listed on the Data Completeness report.'],
        'SECTOR'     => ['label' => 'Invalid Sector Code',         'hint' => 'The code is not on the Reference sheet. Choose a listed sector code before importing.'],
        'CONTACT'    => ['label' => 'Contact number format',       'hint' => 'Should start with 09 and be 11 digits. Imports as typed.'],
        'SUFFIX'     => ['label' => 'Suffix adjusted',             'hint' => 'Changed to the matching dropdown value, or left blank if it matches none.'],
        'BDAY-RANGE' => ['label' => 'Birthday out of range',       'hint' => 'Over 150 years ago. Imports as typed.'],
        'BDAY-FUTURE' => ['label' => 'Future birthday (imports blank)', 'hint' => 'A future date cannot be stored; the birthday imports blank and the family is listed on the Data Completeness report.'],
        'QR-CONTIG'  => ['label' => 'Family rows not together',    'hint' => 'Warning only - the family imports, but check the grouping.'],
    ];

    /**
     * Friendly column names, keyed by the importer's normalized field (matches the Excel
     * headers). Doubles as the allowlist of fields an inline cell edit may target.
     */
    public const FIELD_LABELS = [
        'familyno' => 'QR Number', 'relationship' => 'Relationship', 'lastname' => 'LastName',
        'firstname' => 'FirstName', 'middlename' => 'MiddleName', 'suffix' => 'Suffix',
        'birthday' => 'Birthday', 'sex' => 'Sex', 'civilstatus' => 'CivilStatus',
        'contactnumber' => 'ContactNumber', 'religion' => 'Religion', 'education' => 'Education',
        'job' => 'Job', 'monthlyincome' => 'MonthlyIncome', 'address' => 'Address',
        'barangay' => 'Barangay', 'sector' => 'Sector', 'services' => 'Services',
    ];

    /**
     * The page-load summary. Deliberately carries no rows: those arrive from
     * page() over the rows endpoint.
     *
     * @param array $result the staged bundle {rows, errors, counts, columns, file}
     */
    public function build(array $result): array
    {
        $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $counts = is_array($result['counts'] ?? null) ? $result['counts'] : [];
        $rows   = is_array($result['rows'] ?? null) ? $result['rows'] : [];

        return [
            'file'   => (string) ($result['file'] ?? 'import.xlsx'),
            'counts' => [
                'rows'     => (int) ($counts['rows'] ?? count($rows)),
                'groups'   => (int) ($counts['groups'] ?? 0),
                'families' => (int) ($counts['families'] ?? 0),
                'members'  => (int) ($counts['members'] ?? 0),
                'people'   => (int) ($counts['people'] ?? 0),
                'existing' => (int) ($counts['existing'] ?? 0),
                'newFamilies' => max(0, (int) ($counts['families'] ?? 0) - (int) ($counts['existing'] ?? 0)),
                'appends'  => (int) ($counts['appends'] ?? 0),
                'blocking' => (int) ($counts['blocking'] ?? 0),
                'warnings' => (int) ($counts['warnings'] ?? 0),
            ],
            // The codes actually present, so the filter dropdown offers only what
            // this file can be narrowed to.
            'codes'       => $this->codesPresent($errors),
            'fileNotices' => $this->fileNotices($errors),
        ];
    }

    /**
     * One slice of the review table.
     *
     * Filtering runs over every staged row before the slice is cut, so `filtered`
     * counts what the current narrowing matches and `total` counts the file.
     *
     * @return array{rows: list<array>, total: int, filtered: int, page: int, per: int}
     */
    public function page(array $result, ImportReviewQuery $query): array
    {
        $rows  = is_array($result['rows'] ?? null) ? $result['rows'] : [];
        $shaped = $this->shapeRows($result);
        $kept   = [];

        foreach ($shaped as $row) {
            if ($this->matches($row, $query)) {
                $kept[] = $row;
            }
        }

        return [
            'rows'     => array_values(array_slice($kept, $query->offset(), $query->per)),
            'total'    => count($rows),
            'filtered' => count($kept),
            'page'     => $query->page,
            'per'      => $query->per,
        ];
    }

    /**
     * One shaped row by its sheet row, or null when it is not staged. Apply uses
     * this to return just the row it changed rather than the whole report.
     */
    public function row(array $result, int $sheetRow): ?array
    {
        foreach ($this->shapeRows($result) as $row) {
            if ($row['sheetRow'] === $sheetRow) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Every staged row shaped for the table, in sheet order.
     *
     * @return list<array>
     */
    private function shapeRows(array $result): array
    {
        $rows    = is_array($result['rows'] ?? null) ? $result['rows'] : [];
        $errors  = is_array($result['errors'] ?? null) ? $result['errors'] : [];
        $columns = is_array($result['columns'] ?? null) ? $result['columns'] : [];

        // Index the errors once by sheet row; shaping 10,000 rows must not walk the
        // error list once per row.
        $errorsByRow = [];

        foreach ($errors as $error) {
            $sheetRow = $error['sheetRow'] ?? null;

            if ($sheetRow !== null) {
                $errorsByRow[(int) $sheetRow][] = $error;
            }
        }

        $labelByQr = $this->familyLabels($rows);
        $out       = [];

        foreach ($rows as $entry) {
            $sheetRow = (int) ($entry['sheetRow'] ?? 0);
            $data     = is_array($entry['data'] ?? null) ? $entry['data'] : [];
            $qr       = trim((string) ($data['familyno'] ?? ''));
            $own      = $errorsByRow[$sheetRow] ?? [];

            $out[] = [
                'sheetRow' => $sheetRow,
                'qr'       => $qr,
                'family'   => $qr === '' ? 'No QR' : ($labelByQr[$qr] ?? 'QR ' . $qr),
                'role'     => $this->isHeadRow($data)
                    ? 'Head'
                    : (trim((string) ($data['relationship'] ?? '')) ?: 'Member'),
                'values'   => [
                    'lastname'   => (string) ($data['lastname'] ?? ''),
                    'firstname'  => (string) ($data['firstname'] ?? ''),
                    'middlename' => (string) ($data['middlename'] ?? ''),
                    'birthday'   => (string) ($data['birthday'] ?? ''),
                    'sex'        => (string) ($data['sex'] ?? ''),
                ],
                'severity' => $this->worstSeverity($own),
                'issues'   => $this->issuesFor($own, $columns, $sheetRow),
                'fields'   => $this->fieldsFor($own, $data, $columns, $sheetRow),
            ];
        }

        return $out;
    }

    /**
     * The household label per QR: the head's last name, so the grouping reads as a
     * family rather than as a number. A group with no head falls back to its QR.
     *
     * @return array<string, string>
     */
    private function familyLabels(array $rows): array
    {
        $out = [];

        foreach ($rows as $entry) {
            $data = is_array($entry['data'] ?? null) ? $entry['data'] : [];
            $qr   = trim((string) ($data['familyno'] ?? ''));

            if ($qr === '') {
                continue;
            }

            if (! isset($out[$qr])) {
                $out[$qr] = 'QR ' . $qr;
            }

            if ($this->isHeadRow($data) && trim((string) ($data['lastname'] ?? '')) !== '') {
                $out[$qr] = trim((string) ($data['lastname'] ?? ''));
            }
        }

        return $out;
    }

    /** @param list<array> $errors this row's errors */
    private function worstSeverity(array $errors): string
    {
        $worst = '';

        foreach ($errors as $error) {
            if (($error['severity'] ?? 'blocking') === 'blocking') {
                return 'blocking';
            }

            $worst = 'warning';
        }

        return $worst;
    }

    /**
     * The row's distinct problems, blocking first: what the Issues column lists.
     * Field-less codes (a family already on file, members being appended) belong
     * here too - they report what the import will do, so they must be readable
     * even though they offer nothing to edit.
     *
     * @param list<array>           $errors  this row's errors
     * @param array<string, string> $columns [field => Excel column letter]
     * @return list<array{code: string, label: string, severity: string, message: string, cell: string}>
     */
    private function issuesFor(array $errors, array $columns, int $sheetRow): array
    {
        $byIssue = [];

        foreach ($errors as $error) {
            $code = (string) ($error['code'] ?? '');

            if ($code === '') {
                continue;
            }

            $severity = (($error['severity'] ?? 'blocking') === 'blocking') ? 'blocking' : 'warning';
            $field = $error['field'] ?? null;
            // REQUIRED and INCOMPLETE can apply to several cells on one row. Keep
            // one issue per code/field pair, while still coalescing exact repeats.
            $key = $code . "\0" . (string) $field;

            if (isset($byIssue[$key]) && ($byIssue[$key]['severity'] === 'blocking' || $severity !== 'blocking')) {
                continue;
            }

            $byIssue[$key] = [
                'code'     => $code,
                'label'    => $this->issueLabel($error),
                'severity' => $severity,
                'message'  => (string) ($error['message'] ?? ''),
                'cell'     => ($field !== null && isset($columns[(string) $field]))
                    ? (string) $columns[(string) $field] . $sheetRow
                    : '',
            ];
        }

        $out = array_values($byIssue);

        usort($out, static fn (array $a, array $b): int =>
            (($a['severity'] === 'blocking') ? 0 : 1) <=> (($b['severity'] === 'blocking') ? 0 : 1));

        return $out;
    }

    /**
     * The row's editable fields: one entry per field carrying an error, whatever
     * column it belongs to. This is what makes an error on barangay, relationship
     * or income reachable, where the four-column table left it invisible.
     *
     * A blocking error beats a warning on the same field, so a cell offers one
     * editor carrying the reason that matters.
     *
     * @param list<array>           $errors  this row's errors
     * @param array<string, string> $data    the row's staged cell values
     * @param array<string, string> $columns [field => Excel column letter]
     * @return list<array{field: string, label: string, cell: string, value: string, severity: string, message: string}>
     */
    private function fieldsFor(array $errors, array $data, array $columns, int $sheetRow): array
    {
        $byField = [];

        foreach ($errors as $error) {
            $field = $error['field'] ?? null;

            // A field-less code has nothing to type into; it stays in issuesFor().
            if ($field === null || ! isset(self::FIELD_LABELS[(string) $field])) {
                continue;
            }

            $field    = (string) $field;
            $severity = (($error['severity'] ?? 'blocking') === 'blocking') ? 'blocking' : 'warning';

            if (isset($byField[$field]) && ($byField[$field]['severity'] === 'blocking' || $severity !== 'blocking')) {
                continue;
            }

            $letter = isset($columns[$field]) ? (string) $columns[$field] : '';

            $byField[$field] = [
                'field'    => $field,
                'label'    => self::FIELD_LABELS[$field],
                'cell'     => $letter !== '' ? $letter . $sheetRow : '',
                'value'    => (string) ($data[$field] ?? ''),
                'severity' => $severity,
                'message'  => (string) ($error['message'] ?? ''),
            ];
        }

        return array_values($byField);
    }

    /**
     * The distinct issue codes anywhere in the file, for the filter dropdown.
     *
     * @param list<array> $errors
     * @return list<array{code: string, label: string, severity: string}>
     */
    private function codesPresent(array $errors): array
    {
        $byCode = [];

        foreach ($errors as $error) {
            $code = (string) ($error['code'] ?? '');

            if ($code === '' || in_array($code, ['FILE', 'EMPTY', 'QR-11'], true)) {
                continue;
            }

            $severity = (($error['severity'] ?? 'blocking') === 'blocking') ? 'blocking' : 'warning';

            if (! isset($byCode[$code])) {
                $byCode[$code] = [
                    'code'     => $code,
                    'severity' => $severity,
                    'labels'   => [],
                ];
            }

            if ($severity === 'blocking') {
                $byCode[$code]['severity'] = 'blocking';
            }

            // A filter covers every field carrying its code. List every friendly
            // field label instead of falsely naming only the first such field.
            $byCode[$code]['labels'][$this->issueLabel($error)] = true;
        }

        ksort($byCode);

        foreach ($byCode as &$entry) {
            $entry['label'] = implode(', ', array_keys($entry['labels']));
            unset($entry['labels']);
        }
        unset($entry);

        return array_values($byCode);
    }

    /**
     * Returns the friendly label shown for one row issue. REQUIRED and INCOMPLETE
     * each cover several columns, so their label identifies the cell to fill.
     */
    private function issueLabel(array $error): string
    {
        $code = (string) ($error['code'] ?? '');

        if (in_array($code, ['REQUIRED', 'INCOMPLETE'], true)) {
            $field = (string) ($error['field'] ?? '');
            $fieldLabel = $field === 'monthlyincome'
                ? 'Income'
                : (self::FIELD_LABELS[$field] ?? 'value');

            return 'Missing ' . $fieldLabel;
        }

        return self::GROUPS[$code]['label'] ?? $code;
    }

    /** Whether a shaped row survives the current narrowing. */
    private function matches(array $row, ImportReviewQuery $query): bool
    {
        $severity = (string) $row['severity'];

        if ($query->severity === 'problems' && $severity === '') {
            return false;
        }

        if (in_array($query->severity, ['blocking', 'warning'], true) && $severity !== $query->severity) {
            return false;
        }

        if ($query->code !== '' && ! in_array($query->code, array_column($row['issues'], 'code'), true)) {
            return false;
        }

        if ($query->q === '') {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', [
            $row['family'], $row['qr'], $row['role'],
            $row['values']['lastname'], $row['values']['firstname'],
        ]));

        return str_contains($haystack, mb_strtolower($query->q));
    }

    /**
     * Whole-file problems (unreadable / empty) - there is nothing to edit, so these surface as
     * a single page notice rather than an editable row.
     *
     * @param list<array> $errors
     * @return list<string>
     */
    private function fileNotices(array $errors): array
    {
        $out = [];

        foreach ($errors as $error) {
            if (in_array((string) ($error['code'] ?? ''), ['FILE', 'EMPTY', 'QR-11'], true)) {
                $out[] = (string) ($error['message'] ?? '');
            }
        }

        return $out;
    }

    /** @param array<string, string> $data */
    private function isHeadRow(array $data): bool
    {
        return strcasecmp(trim((string) ($data['relationship'] ?? '')), 'Head') === 0;
    }
}
