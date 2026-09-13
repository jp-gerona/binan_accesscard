<?php

namespace App\Libraries;

use App\Models\Families\FamilyFormOptionsModel;
use App\Models\Families\MemberModel;
use App\Models\Lookups\SectorModel;
use App\Models\Lookups\ServiceModel;
use App\Models\Scanner\QrControlModel;
use App\Support\ContactNumber;
use App\Support\FamilyAgeEligibility;
use App\Support\FamilyProfilingFormV2;
use App\Support\MemberFieldNormalizer;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads and validates a filled family-records .xlsx (produced from
 * App\Libraries\FamilyExcelTemplate) into ready-to-persist family payloads.
 *
 * Layout: the "Families" sheet has one row per person; a "FamilyNo" / "QR Number"
 * column groups a family; the row with Relationship = "Head" is the head. Sectors and
 * Services are comma-separated CODES. Civil status and education accept the CSWD form
 * short codes (translated to the full stored value).
 *
 * Two-step pipeline so the SAME validators run on an uploaded file AND on rows a
 * reviewer has edited in the browser:
 *   1. parseFile() - PhpSpreadsheet read -> a normalized row set (+ file-level errors)
 *   2. validateAndBuild() - runs every validator over a row set -> families + errors
 * stage() runs both and returns a review-ready bundle. Unlike the old all-or-nothing
 * flow, validateAndBuild reports EVERY row's problems (it does not stop at the first bad
 * family), so the reviewer sees the whole picture up front.
 *
 * An error is `['sheetRow'=>?int, 'familyNo'=>string, 'field'=>?string, 'code'=>string,
 * 'message'=>string, 'severity'=>'blocking'|'warning']`. `code` groups the review UI's
 * buckets; `field` targets the editable cell; `severity` decides whether it blocks the
 * import. Built families are shaped exactly for FamilyRecordWriter::persistFamily.
 */
class FamilyExcelImporter
{
    /** MySQL signed INT max - the QR control number ceiling. */
    public const QR_MAX = 2147483647;

    /**
     * Common suffix spellings → the canonical dropdown value (JR/SR/I-V). Keys are
     * lowercased with dots removed. Lets "Junior", "the 3rd", "2nd" map to a valid enum
     * value instead of being dropped, so the DB never sees an out-of-enum suffix.
     */
    private const SUFFIX_ALIASES = [
        'jr' => 'JR', 'junior' => 'JR',
        'sr' => 'SR', 'senior' => 'SR',
        'i' => 'I', '1' => 'I', '1st' => 'I', 'first' => 'I',
        'ii' => 'II', '2' => 'II', '2nd' => 'II', 'second' => 'II',
        'iii' => 'III', '3' => 'III', '3rd' => 'III', 'third' => 'III',
        'iv' => 'IV', '4' => 'IV', '4th' => 'IV', 'fourth' => 'IV',
        'v' => 'V', '5' => 'V', '5th' => 'V', 'fifth' => 'V',
    ];

    /** @var list<array{familyNo: string, headName: string, headPayload: array, headServiceIds: int[], memberPayloads: list<array{payload: array, serviceIds: int[]}>}> */
    private array $families = [];

    /** @var list<array{sheetRow: ?int, familyNo: string, field: ?string, code: string, message: string, severity: string}> */
    private array $errors = [];

    /** Members whose QR already belongs to a family - added to it on import. */
    private array $appends = [];

    /**
     * QRs already in the DB, with the head stored under each - NOT just a name: the review
     * has to check the incoming head IS that person before it can call a group a duplicate.
     *
     * @var array<int, array{headID: int, name: string, record: array<string, string|null>}>
     */
    private array $existingHeads = [];

    /**
     * People already on file, keyed by identity (see identityKey()). Catches the person
     * re-entered under a NEW QR - which the write step silently skips - and the member
     * re-added to a family that already has them.
     *
     * @var array<string, array{name: string, qr: int, headID: int, isHead: bool}>
     */
    private array $existingPeople = [];

    /** @var list<array{rows:list<int>,qr:string}> Exact in-file duplicate row candidates. */
    private array $duplicateGroups = [];

    private int $memberCount = 0;

    /**
     * What was actually IN the last validated file: every non-empty person row, and the QR
     * groups they formed. Deliberately separate from the families/members counts, which only
     * ever describe families the importer could BUILD - a row in a head-less or bad-QR group
     * builds nothing, so counting people that way silently hides the very rows with problems.
     */
    private int $rowCount = 0;

    private int $groupCount = 0;

    private ?array $sectorRows  = null;
    private ?array $serviceRows = null;
    private ?array $sectorByCode  = null;
    private ?array $serviceByCode = null;
    private ?array $incomeByLabel = null;

    // Normalized official-barangay lookup, built once from FamilyProfilingFormV2.
    private ?array $barangayLookup = null;

    // Folded barangay name to barangayID (App\Models\Lookups\BarangayModel), built
    // once per importer instance so resolving every head's barangayID doesn't cost
    // a query per row.
    private ?array $barangayIdMap = null;

    // -- public API ------------------------------------------------------------

    /**
     * Parses + validates a workbook into a review-ready bundle. On a hard parse
     * failure (unreadable / wrong sheet / missing columns) `ok` is false and `errors`
     * carries the single file-level reason.
     *
     * @return array{ok: bool, rows: list<array{sheetRow: int, data: array<string,string>}>, errors: list<array>, families: list<array>, counts: array{families:int, members:int, blocking:int, warnings:int}}
     */
    public function stage(string $filePath): array
    {
        $parsed = $this->parseFile($filePath);

        if (! $parsed['ok']) {
            return [
                'ok'         => false,
                'rows'       => [],
                'errors'     => $parsed['errors'],
                'fileErrors' => $parsed['errors'],
                'families'   => [],
                'duplicateGroups' => [],
                'counts'     => $this->summarize([], $parsed['errors']),
            ];
        }

        $existingHeads  = $this->existingHeadsForRows($parsed['rows']);
        $existingPeople = $this->existingPeopleForRows($parsed['rows']);
        $built  = $this->validateAndBuild($parsed['rows'], $existingHeads, $existingPeople);
        $errors = array_merge($parsed['errors'], $built['errors']);

        return [
            'ok'         => true,
            'rows'       => $parsed['rows'],
            'errors'     => $errors,
            'fileErrors' => $parsed['errors'],
            // [field => Excel column letter] so the review can name the exact cell.
            'columns'    => $parsed['columns'] ?? [],
            'families'   => $built['families'],
            'appends'    => $built['appends'],
            'duplicateGroups' => $built['duplicateGroups'],
            'counts'     => $this->summarize($built['families'], $errors, $built['appends']),
        ];
    }

    /**
     * Canonicalizes a row set for review and validation. QR and birthday values stay
     * intact for their dedicated parsers; all other known textual fields are staged in
     * their canonical form.
     *
     * @param list<array{sheetRow:int|string,data:array<string,string>}> $rows
     * @return list<array{sheetRow:int|string,data:array<string,string>}>
     */
    public function normalizeRows(array $rows): array
    {
        foreach ($rows as $index => $entry) {
            $rows[$index]['data'] = $this->normalizeRow(
                is_array($entry['data'] ?? null) ? $entry['data'] : []
            );
        }

        return $rows;
    }

    /**
     * Reads a workbook into a normalized row set. Each row is
     * `['sheetRow'=>int, 'data'=>[normalizedHeader => trimmed string]]`. QR cells are
     * kept verbatim (placeholders like "N/A" are NOT blanked, so they surface as a
     * format error rather than a silent "missing").
     *
     * @return array{ok: bool, rows: list<array{sheetRow: int, data: array<string,string>}>, errors: list<array>}
     */
    public function parseFile(string $filePath): array
    {
        try {
            $spreadsheet = IOFactory::load($filePath);
        } catch (Throwable $exception) {
            return $this->parseFailure('The file could not be read as an Excel workbook. Make sure it is a .xlsx file saved from the template.');
        }

        $sheet = $spreadsheet->getSheetByName(FamilyExcelTemplate::DATA_SHEET);

        if ($sheet === null) {
            return $this->parseFailure('The "' . FamilyExcelTemplate::DATA_SHEET . '" sheet was not found. Please use the downloaded template.');
        }

        $headerRow = $this->headerRowIndex($sheet);
        $columnMap = $this->mapHeaders($sheet, $headerRow);

        $required = ['familyno', 'relationship', 'firstname', 'lastname'];
        $missing  = array_diff($required, array_keys($columnMap));

        if ($missing !== []) {
            return $this->parseFailure('The template is missing required column(s): ' . implode(', ', $missing) . '. Please use the downloaded template.');
        }

        $errors       = [];
        $qrLetter     = $columnMap['familyno'];
        $firstDataRow = $headerRow + 1;

        // QR-11: a merged QR cell in the DATA rows leaves every row but the top one blank.
        // The template's banner/header merges (e.g. "A1:B1" over the title row) are NOT a
        // problem, so only flag merges that reach into the data region.
        foreach ($sheet->getMergeCells() as $range) {
            if ($this->rangeTouchesColumn($range, $qrLetter) && $this->rangeMaxRow($range) >= $firstDataRow) {
                $errors[] = $this->makeError(null, '', 'QR-11', 'familyno',
                    'The QR Number column has merged cells (' . $range . '). Unmerge it, repeat the QR number on every row of the family, then re-upload.');
            }
        }

        $rows = $this->readRows($sheet, $columnMap, $headerRow);

        if ($rows === []) {
            $errors[] = $this->makeError(null, '', 'EMPTY', null, 'No family rows were found on the "' . FamilyExcelTemplate::DATA_SHEET . '" sheet.');
        }

        // The column map is carried through so the review can print the EXACT Excel cell
        // to fix (e.g. "H42") - the operator fixes the file, not a copy of it.
        return ['ok' => true, 'rows' => $rows, 'errors' => $errors, 'columns' => $columnMap];
    }

    /**
     * Validates a row set and builds persist-ready families. Reports every row's
     * problems (never skips a row's field checks because its family failed a
     * family-level check). Resets and repopulates the instance's families/errors.
     *
     * @param list<array{sheetRow: int|string, data: array<string,string>}> $rows
     * @return array{families: list<array>, errors: list<array>, counts: array{families:int, members:int, blocking:int, warnings:int}}
     */
    public function validateAndBuild(array $rows, array $existingHeads = [], array $existingPeople = []): array
    {
        $this->families       = [];
        $this->errors         = [];
        $this->appends        = [];
        $this->existingHeads  = $existingHeads;
        $this->existingPeople = $existingPeople;
        $this->duplicateGroups = [];
        $this->memberCount    = 0;
        $this->rowCount       = 0;
        $this->groupCount     = 0;
        $rows                 = $this->normalizeRows($rows);

        $sectorByCode  = $this->sectorCodeMap();
        $serviceByCode = $this->serviceCodeMap();
        $incomeByLabel = $this->incomeLabelMap();

        // STAGE 3-4: validate + normalise each QR, then group. A row whose QR cannot be
        // validated is reported and left ungrouped (it has no usable family key). Blocks
        // retain the order in which populated QR runs occur; global groups alone cannot
        // distinguish a second family from a separated continuation.
        $groups = [];
        $blocksByQr = [];
        $previousQr = null;
        $blockIndex = null;

        foreach ($rows as $entry) {
            $sheetRow = (int) $entry['sheetRow'];
            $data     = $entry['data'];

            if ($this->rowIsEmpty($data)) {
                continue;
            }

            // Counted BEFORE the QR check: a row with an unusable QR is still a person in
            // the file, and the review must not pretend they are not there.
            $this->rowCount++;

            $qr = $this->validateQr((string) ($data['familyno'] ?? ''));

            if (! $qr['ok']) {
                $this->addError($sheetRow, (string) ($data['familyno'] ?? ''), $qr['code'], 'familyno', $qr['msg']);
                // A populated row with no usable QR interrupts a QR block.
                $previousQr = null;
                $blockIndex = null;
                continue;
            }

            $familyNo = (string) $qr['qr'];
            $familyRow = ['row' => $sheetRow, 'data' => $data];
            $groups[$familyNo][] = $familyRow;

            if ($previousQr !== $familyNo) {
                $blocksByQr[$familyNo][] = [];
                $blockIndex = array_key_last($blocksByQr[$familyNo]);
            }

            $blocksByQr[$familyNo][$blockIndex][] = $familyRow;
            $previousQr = $familyNo;
        }

        $this->groupCount = count($groups);

        foreach ($groups as $familyNo => $familyRows) {
            $this->processFamily(
                (string) $familyNo,
                $familyRows,
                $blocksByQr[(string) $familyNo],
                $sectorByCode,
                $serviceByCode,
                $incomeByLabel,
            );
        }

        $this->duplicateGroups = $this->classifyDuplicateRows($groups);
        // Same idea against the DB: people this batch is re-entering under a different QR.
        $this->checkExistingPeople($groups);

        return [
            'families' => $this->families,
            'errors'   => $this->errors,
            'appends'  => $this->appends,
            'duplicateGroups' => $this->duplicateGroups,
            'counts'   => $this->summarize($this->families, $this->errors, $this->appends),
        ];
    }

    /**
     * Normalises + validates a single raw QR string. Returns
     * `['ok'=>true,'qr'=>int]` or `['ok'=>false,'code'=>string,'msg'=>string]`.
     *
     * Order matters: the strict regex runs BEFORE the int cast, so "5880.0" (a dot) is
     * rejected instead of silently becoming 5880 and filing a person into a stranger's
     * family. (A purely numeric whole-number cell reads back as that int and is accepted
     * - 5880.0 and 5880 are the same value in xlsx and cannot be told apart; the regex
     * only rescues the text-formatted ".0" case, which the template's text column gives.)
     */
    public function validateQr(string $raw): array
    {
        // Excel error literal (#REF!, #N/A) - poisons helper formulas if trusted.
        if (str_starts_with($raw, '#')) {
            return ['ok' => false, 'code' => 'QR-08', 'msg' => 'The QR Number cell holds an Excel error value (like #REF!). Retype the number.'];
        }

        // A formula leaked through (getValue returns "=A4", not a number).
        if (str_starts_with($raw, '=')) {
            return ['ok' => false, 'code' => 'QR-12', 'msg' => 'The QR Number cell holds a formula. Type the number itself, not a formula.'];
        }

        // STAGE 2 - normalise: strip NBSP + zero-width, trim. Do NOT strip commas.
        $s = str_replace(["\u{00A0}", "\u{200B}"], '', $raw);
        $s = trim($s);

        if ($s === '') {
            return ['ok' => false, 'code' => 'QR-01', 'msg' => 'QR Number is required (use the same number for everyone in one family).'];
        }

        // STAGE 3 - strict format. Rejects letters, placeholders, negatives, decimals,
        // integral-float text ("5880.0" has a dot), commas, scientific notation.
        if (! preg_match('/^[0-9]{1,10}$/', $s)) {
            return ['ok' => false, 'code' => 'QR-FORMAT', 'msg' => 'QR Number "' . $s . '" must be a whole number - no letters, symbols, decimals, or commas.'];
        }

        // QR-14 - leading zeros are accepted but logged (may signal a card convention).
        if (strlen($s) > 1 && $s[0] === '0') {
            log_message('warning', 'Import: QR Number had leading zeros: ' . $s);
        }

        $qr = (int) $s;

        // STAGE 4 - range.
        if ($qr < 1) {
            return ['ok' => false, 'code' => 'QR-05', 'msg' => 'QR Number must be greater than zero.'];
        }

        if ($qr > self::QR_MAX) {
            return ['ok' => false, 'code' => 'QR-07', 'msg' => 'QR Number "' . $s . '" is too large (maximum ' . self::QR_MAX . ').'];
        }

        return ['ok' => true, 'qr' => $qr];
    }

    /**
     * The distinct, valid QR numbers in a row set: the only thing
     * existingHeadsForRows() looks up by.
     *
     * Public and separate because ImportLookupCache's correctness rests on this being
     * the whole input. If a lookup ever starts reading another field, that field has to
     * appear here too, and ImportLookupCache::INVALIDATING_FIELDS has to grow with it.
     *
     * @param list<array{sheetRow: int|string, data: array<string,string>}> $rows
     * @return list<int>
     */
    public function qrKeysForRows(array $rows): array
    {
        $qrs = [];

        foreach ($rows as $entry) {
            $qr = $this->validateQr((string) ($entry['data']['familyno'] ?? ''));

            if ($qr['ok']) {
                $qrs[] = $qr['qr'];
            }
        }

        return array_values(array_unique($qrs));
    }

    /**
     * The distinct lastnames in a row set: the only thing existingPeopleForRows()
     * looks up by. See qrKeysForRows() for why this is public.
     *
     * @param list<array{sheetRow: int|string, data: array<string,string>}> $rows
     * @return list<string>
     */
    public function lastnameKeysForRows(array $rows): array
    {
        $lastnames = [];

        foreach ($rows as $entry) {
            $lastname = trim((string) ($entry['data']['lastname'] ?? ''));

            if ($lastname !== '') {
                $lastnames[] = $lastname;
            }
        }

        return array_values(array_unique($lastnames));
    }

    /**
     * Looks up which of a row set's QR numbers already exist in the DB, and the FULL stored
     * head record for each. Feeds validateAndBuild so it can tell apart: the same family
     * uploaded twice (skip), members being added to an existing family (append), a
     * genuinely head-less group, and a mistyped QR that landed on a stranger's family.
     * Bulk queries; safe when the tables are absent.
     *
     * @param list<array{sheetRow: int|string, data: array<string,string>}> $rows
     * @return array<int, array{headID: int, name: string, record: array<string, string|null>}>
     */
    public function existingHeadsForRows(array $rows): array
    {
        $qrs = $this->qrKeysForRows($rows);

        if ($qrs === []) {
            return [];
        }

        $qrModel  = new QrControlModel();
        $existing = $qrModel->existingControlNos($qrs);

        if ($existing === []) {
            return [];
        }

        $headByQr = [];

        foreach (array_chunk($existing, 1000) as $chunk) {
            foreach ($qrModel->whereIn('control_no', $chunk)->findAll() as $row) {
                $headByQr[(int) $row['control_no']] = (int) $row['headID'];
            }
        }

        $records = (new MemberModel())->identitiesForHeads(array_values($headByQr));

        $map = [];

        foreach ($headByQr as $qr => $headId) {
            $record = $records[$headId] ?? [];

            $map[$qr] = [
                'headID' => $headId,
                'name'   => $this->personName($record) ?: ('family ' . $qr),
                'record' => $record,
            ];
        }

        return $map;
    }

    /**
     * Indexes the people from this batch who are ALREADY on file, by identity. Without it
     * the review can only see QR collisions: a person re-entered under a brand-new QR looks
     * clean, then the write step silently skips their whole family (activeHeadExists) and
     * the operator is never told. Bulk queries; safe when the tables are absent.
     *
     * @param list<array{sheetRow: int|string, data: array<string,string>}> $rows
     * @return array<string, array{name: string, qr: int, headID: int, isHead: bool}>
     */
    public function existingPeopleForRows(array $rows): array
    {
        $lastnames = $this->lastnameKeysForRows($rows);

        if ($lastnames === []) {
            return [];
        }

        $people = (new MemberModel())->activePeopleByLastname($lastnames);

        if ($people === []) {
            return [];
        }

        $qrByHead = (new QrControlModel())->controlsForHeads(array_map(
            static fn (array $row): int => (int) ($row['headID'] ?? 0),
            $people,
        ));

        $index = [];

        foreach ($people as $row) {
            $key = $this->identityKey(
                (string) ($row['firstname'] ?? ''),
                (string) ($row['lastname'] ?? ''),
                $row['birthday'] ?? null,
            );

            if ($key === '') {
                continue;
            }

            $headId = (int) ($row['headID'] ?? 0);

            // First match wins - a person filed twice is the DB's problem, not the import's.
            $index[$key] ??= [
                'name'   => $this->personName($row),
                'qr'     => $qrByHead[$headId] ?? 0,
                'headID' => $headId,
                'isHead' => (int) ($row['memberID'] ?? 0) === $headId,
            ];
        }

        return $index;
    }

    /**
     * Builds the "add this member to an existing family" entries for a head-less group
     * whose QR is already on file - the classic "worker forgot a member last batch" case.
     * These are ADDED automatically on import and listed in the review; to skip someone,
     * the operator deletes that row from the spreadsheet and re-uploads.
     *
     * @param list<array{row: int, data: array<string, string>}> $rows
     */
    private function collectAppends(string $familyNo, string $headName, array $rows, array $sectorByCode, array $serviceByCode, array $incomeByLabel): void
    {
        foreach ($rows as $entry) {
            $payload    = $this->buildPersonPayload($entry, $familyNo, false, $sectorByCode, $incomeByLabel);
            $serviceIds = $this->mapServices($entry, $familyNo, $serviceByCode);

            $this->validateAgeEligibility(
                (int) $entry['row'],
                $familyNo,
                (string) ($payload['birthday'] ?? ''),
                $payload['sector_ids'] ?? [],
                $serviceIds
            );

            $memberName = $this->personName($payload);

            $key = $this->identityKey(
                (string) ($payload['firstname'] ?? ''),
                (string) ($payload['lastname'] ?? ''),
                $payload['birthday'] ?? null,
            );
            $match = $key !== '' ? ($this->existingPeople[$key] ?? null) : null;

            // Already in this very family: the write step skips them (memberExistsUnderHead),
            // so promising an ADD would be a lie. Don't queue the append - report the truth.
            if ($match !== null && (int) $match['qr'] === (int) $familyNo) {
                $this->addError((int) $entry['row'], $familyNo, 'DUP-DB', 'familyno',
                    ($memberName !== '' ? $memberName : 'This person') . ' is already in family ' . $familyNo
                    . ($headName !== '' ? ' (' . $headName . ')' : '')
                    . ' - this row will be skipped, nothing is added twice.', 'warning');

                continue;
            }

            $this->appends[] = [
                'sheetRow'   => (int) $entry['row'],
                'qr'         => (int) $familyNo,
                'headName'   => $headName,
                'payload'    => $payload,
                'serviceIds' => $serviceIds,
            ];

            $this->addError((int) $entry['row'], $familyNo, 'ADD-MEMBER', 'familyno',
                ($memberName !== '' ? $memberName : 'This person') . ' will be ADDED to existing family ' . $familyNo
                . ($headName !== '' ? ' (' . $headName . ')' : '')
                . '. To skip them, delete this row from the file and upload again.', 'warning');
        }
    }

    /**
     * Builds the review counts from a family set, its errors, and the append list.
     *
     * TWO different populations here, and mixing them up misleads the operator:
     *   rows / groups - what is IN THE FILE. Every person row, and every QR group they
     *                       form, INCLUDING the broken ones.
     *   families / members / people
     * - only what the importer could BUILD. A head-less group, a group
     *                       with two heads, or a row with an unusable QR builds nothing, so
     *                       its people are absent from these. Never label them as a file
     *                       total: they hide exactly the rows that need attention.
     *
     * `existing` is duplicate families; `appends` are members bound for existing families.
     *
     * @param list<array> $families
     * @param list<array> $errors
     * @param list<array> $appends
     */
    public function summarize(array $families, array $errors, array $appends = []): array
    {
        $members = 0;

        foreach ($families as $family) {
            $members += count($family['memberPayloads'] ?? []);
        }

        $familyCount = count($families);

        return [
            // In the file.
            'rows'     => $this->rowCount,
            'groups'   => $this->groupCount,
            // Buildable.
            'families' => $familyCount,
            'members'  => $members,
            'people'   => $familyCount + $members,
            'existing' => count(array_filter($errors, static fn (array $e): bool => ($e['code'] ?? '') === 'DUP-EXISTS')),
            // Members that will be added to an already-existing family on import.
            'appends'  => count($appends),
            'blocking' => $this->tally($errors, 'blocking'),
            'warnings' => $this->tally($errors, 'warning'),
        ];
    }

    /** @return list<array> the last run's errors (richer shape; see class docblock). */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /** @return list<array> the last run's persist-ready families. */
    public function getFamilies(): array
    {
        return $this->families;
    }

    /** Counts for the success summary: ['families' => int, 'members' => int]. */
    public function getSummary(): array
    {
        return [
            'families' => count($this->families),
            'members'  => $this->memberCount,
        ];
    }

    /**
     * Legacy all-or-nothing entry point (parse + validate a file; true only when every
     * row is valid). Retained for callers that import nothing on any error.
     */
    public function process(string $filePath): bool
    {
        $staged = $this->stage($filePath);

        $this->families    = $staged['families'];
        $this->errors      = $staged['errors'];
        $this->memberCount = $staged['counts']['members'];

        return $staged['errors'] === [];
    }

    // -- parsing ---------------------------------------------------------------

    /**
     * Finds the header row by locating the "FamilyNo"/"QR Number" cell within the first
     * rows, so a decorative banner row above the headers does not break parsing.
     */
    private function headerRowIndex(Worksheet $sheet): int
    {
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $maxScan = min(20, max(1, $sheet->getHighestDataRow()));

        for ($row = 1; $row <= $maxScan; $row++) {
            for ($i = 1; $i <= $highestColumnIndex; $i++) {
                $header = $this->normalizeHeader($sheet->getCell(Coordinate::stringFromColumnIndex($i) . $row)->getValue());

                if ($header === 'familyno' || $header === 'qrnumber') {
                    return $row;
                }
            }
        }

        return 1;
    }

    /**
     * Reads the header row into [normalizedHeader => columnLetter], aliasing "QR Number"
     * to 'familyno' and dropping the template's helper columns.
     *
     * @return array<string, string>
     */
    private function mapHeaders(Worksheet $sheet, int $headerRow): array
    {
        $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $map = [];

        for ($i = 1; $i <= $highestColumnIndex; $i++) {
            $letter = Coordinate::stringFromColumnIndex($i);
            $header = $this->normalizeHeader($sheet->getCell($letter . $headerRow)->getValue());

            if ($header !== '') {
                $map[$header] = $letter;
            }
        }

        // Drop the template's helper column(s) - they hold Excel formulas, not data.
        foreach (['check', 'status', 'validation', 'notes'] as $helper) {
            unset($map[$helper]);
        }

        // The family-group key column may be headed "FamilyNo" or "QR Number".
        if (! isset($map['familyno']) && isset($map['qrnumber'])) {
            $map['familyno'] = $map['qrnumber'];
        }

        return $map;
    }

    /**
     * Reads every non-empty data row into the interchange shape used by
     * validateAndBuild.
     *
     * @param array<string, string> $columnMap
     * @return list<array{sheetRow: int, data: array<string, string>}>
     */
    private function readRows(Worksheet $sheet, array $columnMap, int $headerRow): array
    {
        $highestRow = $sheet->getHighestDataRow();
        $rows = [];

        for ($row = $headerRow + 1; $row <= $highestRow; $row++) {
            $values = $this->readRow($sheet, $columnMap, $row);

            if ($this->rowIsEmpty($values)) {
                continue;
            }

            $rows[] = ['sheetRow' => $row, 'data' => $values];
        }

        return $this->normalizeRows($rows);
    }

    /**
     * Canonicalizes one row's known textual fields while preserving unknown columns
     * for the review UI.
     *
     * @param array<string,string> $data
     * @return array<string,string>
     */
    private function normalizeRow(array $data): array
    {
        foreach ([
            'firstname', 'middlename', 'lastname', 'relationship', 'suffix', 'sex', 'civilstatus', 'contactnumber',
            'religion', 'education', 'job', 'monthlyincome', 'address', 'barangay',
            'sector', 'services',
        ] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = MemberFieldNormalizer::blankIfNoData($data[$field]);
            }
        }

        foreach (['firstname', 'middlename', 'lastname'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = MemberFieldNormalizer::cleanName($data[$field]);
            }
        }

        foreach (['address', 'barangay'] as $field) {
            if (array_key_exists($field, $data)) {
                // Staging equality preserves every character except redundant whitespace.
                $data[$field] = $this->canonicalAddress($data[$field]);
            }
        }

        foreach (['relationship', 'sex', 'civilstatus', 'religion', 'education', 'job'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = mb_strtoupper(trim($data[$field]), 'UTF-8');
            }
        }

        if (array_key_exists('suffix', $data)) {
            $data['suffix'] = $this->canonicalSuffix($data['suffix']);
        }

        foreach (['sector', 'services'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->canonicalCodeList($data[$field]);
            }
        }

        return $data;
    }

    /** Canonicalizes staged addresses without discarding punctuation. */
    private function canonicalAddress(string $value): string
    {
        return mb_strtoupper(
            trim((string) preg_replace('/\s+/u', ' ', $value)),
            'UTF-8'
        );
    }

    /** Maps a suffix alias to its enum value while retaining invalid input for validation. */
    private function canonicalSuffix(string $value): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', str_replace('.', '', $value)));
        $key   = (string) preg_replace('/^the\s+/', '', mb_strtolower($value, 'UTF-8'));

        return self::SUFFIX_ALIASES[$key] ?? mb_strtoupper($value, 'UTF-8');
    }

    /**
     * Reads one sheet row into [normalizedHeader => trimmed string value]. Birthday is
     * date-aware (a real Excel date becomes "MM-DD-YYYY"). The QR cell is kept verbatim
     * (no no-data blanking) so placeholders surface as a format error, not "missing".
     *
     * @param array<string, string> $columnMap
     * @return array<string, string>
     */
    private function readRow(Worksheet $sheet, array $columnMap, int $row): array
    {
        $values = [];

        foreach ($columnMap as $key => $letter) {
            $cell  = $sheet->getCell($letter . $row);
            $value = $cell->getValue();

            // QR: keep the raw text so "N/A"/"5880.0"/"=A4" reach the QR validator intact.
            if ($key === 'familyno') {
                $values[$key] = trim((string) $value);
                continue;
            }

            if ($key === 'birthday' && $value !== null && $value !== '' && ExcelDate::isDateTime($cell)) {
                $values[$key] = ExcelDate::excelToDateTimeObject($value)->format('m-d-Y');
                continue;
            }

            // Placeholder words ("none", "n/a", …) are treated as an empty cell.
            $values[$key] = MemberFieldNormalizer::blankIfNoData($value);
        }

        return $values;
    }

    // -- per-family validation + payload build ---------------------------------

    /**
     * Validates one family group. Emits family-level coherence errors (head count and
     * contiguity) AND validates every row's own fields, so all problems
     * surface at once. Only a coherent (exactly-one-head) family is appended as
     * persist-ready; field errors on it still block the import via the reviewer's gate.
     *
     * @param list<array{row: int, data: array<string, string>}> $rows
     * @param list<list<array{row: int, data: array<string, string>}>> $blocks
     * @param array<string, int>    $sectorByCode
     * @param array<string, int>    $serviceByCode
     * @param array<string, string> $incomeByLabel
     */
    private function processFamily(string $familyNo, array $rows, array $blocks, array $sectorByCode, array $serviceByCode, array $incomeByLabel): void
    {
        $heads   = [];
        $members = [];

        foreach ($rows as $entry) {
            if (strcasecmp(trim((string) ($entry['data']['relationship'] ?? '')), 'Head') === 0) {
                $heads[] = $entry;
            } else {
                $members[] = $entry;
            }
        }

        $existing         = $this->existingHeads[(int) $familyNo] ?? null;
        $existsInDb       = $existing !== null;
        $existingHeadName = (string) ($existing['name'] ?? '');

        // Family-level coherence (does not early-return - fields are still validated).
        $blockIssue = $this->checkQrBlocks($familyNo, $blocks);

        // A headless group whose QR already belongs to a family = members being ADDED to
        // that existing family (the worker's forgotten-member-next-batch case). Instead of
        // HEAD-NONE, surface each as an append the operator confirms or removes.
        if (count($heads) === 0 && $existsInDb) {
            $this->collectAppends($familyNo, $existingHeadName, $rows, $sectorByCode, $serviceByCode, $incomeByLabel);

            return;
        }

        if (count($heads) === 0 || $blockIssue) {
            if (count($heads) === 0) {
                [$anchorRow, $message] = $this->headlessDiagnosis($familyNo, $rows);
                $this->addError($anchorRow, $familyNo, 'HEAD-NONE', 'relationship', $message);
            }

            // Aggregate: still validate every row's fields so those errors surface now.
            foreach ($rows as $entry) {
                $isHead = strcasecmp(trim((string) ($entry['data']['relationship'] ?? '')), 'Head') === 0;
                $payload = $this->buildPersonPayload($entry, $familyNo, $isHead, $sectorByCode, $incomeByLabel);
                $serviceIds = $this->mapServices($entry, $familyNo, $serviceByCode);
                
                $this->validateAgeEligibility(
                    (int) $entry['row'],
                    $familyNo,
                    (string) ($payload['birthday'] ?? ''),
                    $payload['sector_ids'] ?? [],
                    $serviceIds
                );
            }

            return;
        }

        $headPayload    = $this->buildPersonPayload($heads[0], $familyNo, true, $sectorByCode, $incomeByLabel);
        $headServiceIds = $this->mapServices($heads[0], $familyNo, $serviceByCode);

        $this->validateAgeEligibility(
            (int) $heads[0]['row'],
            $familyNo,
            (string) ($headPayload['birthday'] ?? ''),
            $headPayload['sector_ids'] ?? [],
            $headServiceIds
        );

        // A QR already on file proves only that SOME family owns it - never that it is this
        // one. Check the incoming head IS the stored head before calling the group a
        // duplicate, otherwise a single mistyped digit reports someone else's family as
        // "already in the system" and the row is written against the wrong household.
        if ($existsInDb) {
            $this->checkExistingFamily($familyNo, $heads[0], $headPayload, $existing);
        }

        $memberPayloads = [];

        foreach ($members as $memberEntry) {
            $memberPayload = $this->buildPersonPayload($memberEntry, $familyNo, false, $sectorByCode, $incomeByLabel);
            // Members share the head's address (and barangay) - auto-fill so workers
            // never retype it.
            $memberPayload['address'] = $headPayload['address'];
            $memberPayload['barangayID'] = $headPayload['barangayID'];
            
            $memberServiceIds = $this->mapServices($memberEntry, $familyNo, $serviceByCode);

            $this->validateAgeEligibility(
                (int) $memberEntry['row'],
                $familyNo,
                (string) ($memberPayload['birthday'] ?? ''),
                $memberPayload['sector_ids'] ?? [],
                $memberServiceIds
            );

            $memberPayloads[] = [
                'payload'    => $memberPayload,
                'serviceIds' => $memberServiceIds,
            ];
        }

        $this->families[] = [
            'familyNo'       => $familyNo,
            'headName'       => trim(($headPayload['firstname'] ?? '') . ' ' . ($headPayload['lastname'] ?? '')),
            'headPayload'    => $headPayload,
            'headServiceIds' => $headServiceIds,
            'memberPayloads' => $memberPayloads,
        ];

        $this->memberCount += count($memberPayloads);
    }

    /** @param list<array{row: int, data: array<string, string>}> $rows
     * @return array{0: int, 1: string} [anchor sheet row, message]
     */
    private function headlessDiagnosis(string $familyNo, array $rows): array
    {
        return [
            (int) $rows[0]['row'],
            'Family ' . $familyNo . ' has no Head. Set one person as Head.',
        ];
    }

    /**
     * Distinguishes a separate household sharing a QR from a harmless separated
     * continuation. A multiple-head error is local to one contiguous block; heads in
     * different blocks only conflict when every block has one and their identities differ.
     *
     * @param list<list<array{row: int, data: array<string, string>}>> $blocks
     */
    private function checkQrBlocks(string $familyNo, array $blocks): bool
    {
        $hasBlockingIssue = false;
        $singleHeads = [];

        foreach ($blocks as $block) {
            $heads = array_values(array_filter($block, static fn (array $entry): bool =>
                strcasecmp(trim((string) ($entry['data']['relationship'] ?? '')), 'Head') === 0
            ));

            if (count($heads) > 1) {
                $this->addError($heads[1]['row'], $familyNo, 'HEAD-MULTI', 'relationship',
                    'Family ' . $familyNo . ' has more than one Head in the same contiguous family block. Only one person can be the Head.');
                $hasBlockingIssue = true;
            }

            if (count($heads) === 1) {
                $singleHeads[] = ['head' => $heads[0], 'block' => $block];
            }
        }

        if (count($blocks) < 2) {
            return $hasBlockingIssue;
        }

        // A different-family conflict is provable only when every separated block has
        // exactly one head. A headless block can be a continuation of the preceding one.
        if (count($singleHeads) === count($blocks)) {
            $identities = [];

            foreach ($singleHeads as $item) {
                $data = $item['head']['data'];
                $identities[] = implode('|', [
                    $this->normalizeText((string) ($data['firstname'] ?? '')),
                    $this->normalizeText((string) ($data['lastname'] ?? '')),
                    (string) $this->normalizeBirthday((string) ($data['birthday'] ?? '')),
                ]);
            }

            if (count(array_unique($identities)) > 1) {
                $descriptions = [];

                foreach ($singleHeads as $item) {
                    $block = $item['block'];
                    $range = (int) $block[0]['row'] . '-' . (int) $block[array_key_last($block)]['row'];
                    $name = $this->personName($item['head']['data']) ?: 'an unnamed Head';
                    $descriptions[] = 'rows ' . $range . ' (headed by ' . $name . ')';
                }

                foreach ($singleHeads as $item) {
                    $this->addError((int) $item['head']['row'], $familyNo, 'DUP-QR-FAMILY', 'familyno',
                        'QR ' . $familyNo . ' is used by separate family blocks: ' . implode(' and ', $descriptions)
                        . '. Give each family its own QR Number.');
                }

                return true;
            }
        }

        // Blank rows do not split blocks; reaching here means another populated QR block
        // did. It is safe only because the blocks did not prove different families.
        $this->addError((int) $blocks[0][0]['row'], $familyNo, 'QR-CONTIG', 'familyno',
            'Family ' . $familyNo . ' rows are not next to each other. This can happen after sorting or pasting - check the grouping.', 'warning');

        return $hasBlockingIssue;
    }

    /**
     * Validates and shapes one person into a `member` row payload, recording field
     * errors. Civil status and education accept short codes (translated to full values).
     *
     * @param array{row: int, data: array<string, string>} $entry
     * @param array<string, int>    $sectorByCode
     * @param array<string, string> $incomeByLabel
     */
    private function buildPersonPayload(array $entry, string $familyNo, bool $isHead, array $sectorByCode, array $incomeByLabel): array
    {
        $row  = $entry['row'];
        $data = $entry['data'];

        $firstName = (string) ($data['firstname'] ?? '');
        $lastName  = (string) ($data['lastname'] ?? '');

        $this->requireField($row, $familyNo, 'firstname', 'First Name', $firstName);
        $this->requireField($row, $familyNo, 'lastname', 'Last Name', $lastName);

        $birthday = $this->validateBirthday($row, $familyNo, (string) ($data['birthday'] ?? ''), $isHead);
        $sex      = $this->validateSex($row, $familyNo, (string) ($data['sex'] ?? ''), $isHead);

        $civilStatus = $this->fullValueFromCode((string) ($data['civilstatus'] ?? ''), FamilyExcelTemplate::CIVIL_STATUS_CODES);
        $education   = $this->fullValueFromCode((string) ($data['education'] ?? ''), FamilyExcelTemplate::EDUCATION_CODES);

        $income = $this->resolveIncome($row, $familyNo, (string) ($data['monthlyincome'] ?? ''), $incomeByLabel);
        $profile = $this->optionalProfileDefaults(
            $civilStatus,
            $education,
            (string) ($data['job'] ?? ''),
            (string) ($data['religion'] ?? ''),
            $income,
            trim((string) ($data['monthlyincome'] ?? '')) === ''
        );

        if ($isHead) {
            $address = trim((string) ($data['address'] ?? ''));

            if ($address === '') {
                $this->missingHeadCardField($row, $familyNo, 'address', 'Address');
            } else {
                $this->validateAddress($row, $familyNo, $address);
            }
            if (trim((string) ($data['barangay'] ?? '')) === '') {
                $this->missingHeadCardField($row, $familyNo, 'barangay', 'Barangay');
            }

            $this->validateBarangay($row, $familyNo, (string) ($data['barangay'] ?? ''));
        }

        $sectorIds = $this->mapSectors($entry, $familyNo, $sectorByCode);
        $contact = $this->contactValue($row, $familyNo, (string) ($data['contactnumber'] ?? ''), $isHead);
        // Suffix (optional): normalise "Jr."->"JR" / map "the 3rd"->"III"; an unmappable
        // suffix is left blank (so the DB enum insert can't fail) with a warning.
        $suffix = $this->validateSuffix($row, $familyNo, (string) ($data['suffix'] ?? ''));

        $firstClean  = MemberFieldNormalizer::cleanName($firstName);
        $middleClean = MemberFieldNormalizer::cleanName((string) ($data['middlename'] ?? ''));
        $lastClean   = MemberFieldNormalizer::cleanName($lastName);
        $civilValue  = $profile['civilstatus'];
        $religion    = $profile['religion'];

        // Guard the varchar column limits so an over-long value can't fail or silently
        // truncate at the write step. (Address / Job / Education / Relationship are TEXT.)
        $this->checkLength($row, $familyNo, 'firstname', 'First Name', $firstClean, 100);
        $this->checkLength($row, $familyNo, 'lastname', 'Last Name', $lastClean, 100);
        $this->checkLength($row, $familyNo, 'middlename', 'Middle Name', $middleClean, 50);
        $this->checkLength($row, $familyNo, 'civilstatus', 'Civil Status', $civilValue, 100);
        $this->checkLength($row, $familyNo, 'contactnumber', 'Contact Number', $contact, 20);
        $this->checkLength($row, $familyNo, 'religion', 'Religion', $religion, 100);

        return [
            'firstname'     => $firstClean,
            'middlename'    => $middleClean,
            'lastname'      => $lastClean,
            'suffix'        => $suffix,
            'birthday'      => $birthday,
            'civilstatus'   => $civilValue,
            'sex'           => $sex,
            'education'     => $profile['education'],
            'job'           => $profile['job'],
            'salary'        => $profile['salary'],
            'contactnumber' => $contact,
            'religion'      => $religion,
            // The sheet's Barangay column resolves to barangayID below; it is no
            // longer appended to the address, which holds the street address only.
            // The staged canonical address (uppercase, whitespace collapsed,
            // punctuation preserved) is what the review shows, so it must also be
            // what gets stored - re-cleaning here would strip punctuation the
            // operator already reviewed and approved.
            'address'       => MemberFieldNormalizer::nullableText(
                (string) ($data['address'] ?? '')
            ),
            'barangayID'    => $isHead ? $this->barangayIdForHead((string) ($data['barangay'] ?? '')) : null,
            'relationship'  => $isHead ? 'HEAD' : (MemberFieldNormalizer::nullableUpperText((string) ($data['relationship'] ?? '')) ?? 'MEMBER'),
            'sector_ids'    => $sectorIds,
        ];
    }

    /** Blocks an over-long value that would fail or truncate at the DB write. */
    private function checkLength(int $row, string $familyNo, string $field, string $label, ?string $value, int $max): void
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $this->addError($row, $familyNo, 'LENGTH', $field,
                $label . ' is too long (' . mb_strlen($value) . ' characters; the maximum is ' . $max . ').');
        }
    }

    /** Records an error when a required field is blank. */
    private function requireField(int $row, string $familyNo, string $field, string $label, string $value): void
    {
        if (trim($value) === '') {
            $this->addError($row, $familyNo, 'REQUIRED', $field, $label . ' is required.');
        }
    }

    /** Records a missing Head card field without preventing the import. */
    private function missingHeadCardField(int $row, string $familyNo, string $field, string $label): void
    {
        $this->addError($row, $familyNo, 'INCOMPLETE', $field,
            $label . ' is blank. The family imports but is not ready for an access card.', 'warning');
    }

    /** Blocks a supplied household address that cannot satisfy the member validation. */
    private function validateAddress(int $row, string $familyNo, string $value): void
    {
        $length = mb_strlen($value);

        if ($length < 2 || $length > 255) {
            $this->addError($row, $familyNo, 'ADDRESS', 'address',
                'Address must be between 2 and 255 characters.');
        }
    }

    /**
     * Returns the importer-only defaults for absent profiling values.
     *
     * @return array{civilstatus:string,education:string,job:string,religion:string,salary:?float}
     */
    private function optionalProfileDefaults(string $civilStatus, string $education, string $job, string $religion, ?string $income, bool $incomeWasBlank): array
    {
        return [
            'civilstatus' => MemberFieldNormalizer::nullableUpperText($civilStatus) ?? 'NOT PROVIDED',
            'education'   => MemberFieldNormalizer::nullableUpperText($education) ?? 'NOT PROVIDED',
            'job'         => MemberFieldNormalizer::nullableUpperText($job) ?? 'NOT PROVIDED',
            'religion'    => MemberFieldNormalizer::nullableUpperText($religion) ?? 'NOT PROVIDED',
            'salary'      => $incomeWasBlank ? 0.0 : MemberFieldNormalizer::moneyOrNull($income),
        ];
    }

    /** Validates a birthday cell without inventing a missing or malformed date. */
    private function validateBirthday(int $row, string $familyNo, string $value, bool $isHead): ?string
    {
        $value = trim($value);

        if ($value === '') {
            if ($isHead) {
                $this->missingHeadCardField($row, $familyNo, 'birthday', 'Birthday');
            }

            return null;
        }

        $date = $this->parseSheetBirthday($value);

        if ($date === null) {
            $this->addError($row, $familyNo, 'BDAY', 'birthday',
                'Birthday "' . $value . '" could not be read (use MM-DD-YYYY).');

            return null;
        }

        $today = new \DateTimeImmutable('today');

        if ($date > $today) {
            $this->addError($row, $familyNo, 'BDAY-FUTURE', 'birthday',
                'Birthday "' . $value . '" is in the future.');

            return null;
        }

        if ($date < $today->modify('-150 years')) {
            $this->addError($row, $familyNo, 'BDAY-RANGE', 'birthday',
                'Birthday "' . $value . '" is over 150 years ago - please check the year.', 'warning');
        }

        return $date->format('Y-m-d');
    }

    /**
     * Parses a sheet birthday tolerantly. Inner spaces are removed ("11- 30-2017"),
     * doubled dashes collapse ("10-12--2019"), "=" becomes "-" ("03-02=2020"), and
     * "/" separators become "-" in the template's M-D-Y order ("9/23/1989"). A
     * single-digit month or day is zero-padded ("9/23/1989" -> "09-23-1989") so the
     * M-D-Y round-trip accepts it. The value must still be a FULL real date:
     * truncated ("03-07"), year-only ("2008"), and 5-digit years fail the round-trip
     * and return null. Shared by validateBirthday() and normalizeBirthday() so the
     * duplicate checks see the same dates the payload stores.
     */
    private function parseSheetBirthday(string $value): ?\DateTimeImmutable
    {
        $s = trim($value);
        $s = preg_replace('/\s+/u', '', $s) ?? $s;
        $s = str_replace(['=', '/'], '-', $s);

        while (str_contains($s, '--')) {
            $s = str_replace('--', '-', $s);
        }

        // Zero-pad a single-digit month/day in what is clearly the M-D-Y form, so
        // the round-trip guard below sees "9-23-1989" as "09-23-1989".
        $parts = explode('-', $s);

        if (count($parts) === 3) {
            [$a, $b, $c] = $parts;

            if (preg_match('/^\d{1,2}$/', $a) && preg_match('/^\d{1,2}$/', $b) && preg_match('/^\d{4}$/', $c)) {
                $s = str_pad($a, 2, '0', STR_PAD_LEFT) . '-' . str_pad($b, 2, '0', STR_PAD_LEFT) . '-' . $c;
            }
        }

        foreach (['m-d-Y', 'Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $s);

            if ($date !== false && $date->format($format) === $s) {
                return $date;
            }
        }

        return null;
    }

    /** Validates a sex cell against Male/Female. */
    private function validateSex(int $row, string $familyNo, string $value, bool $isHead): ?string
    {
        $value = trim($value);

        if ($value === '') {
            if ($isHead) {
                $this->missingHeadCardField($row, $familyNo, 'sex', 'Sex');
            }

            return null;
        }

        if (strcasecmp($value, 'Male') === 0) {
            return 'MALE';
        }

        if (strcasecmp($value, 'Female') === 0) {
            return 'FEMALE';
        }

        $this->addError($row, $familyNo, 'SEX', 'sex',
            'Sex "' . $value . '" is not Male or Female.');

        return null;
    }

    /**
     * Resolves a monthly-income cell (a bracket label or a number) to its stored value.
     * A blank value is defaulted by optionalProfileDefaults().
     *
     * @param array<string, string> $incomeByLabel
     */
    private function resolveIncome(int $row, string $familyNo, string $value, array $incomeByLabel): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $key = strtolower($value);

        if (isset($incomeByLabel[$key])) {
            return $incomeByLabel[$key];
        }

        // Free-text amounts: strip a currency marker ("P3000", "PHP 15,000", "$1, 500"),
        // then commas and stray spaces, and accept what is left as a plain amount.
        $numeric = preg_replace('/^(?:php|p|₱|\$)\s*/ui', '', $value) ?? $value;
        $numeric = str_replace([',', "\u{00A0}", ' '], '', $numeric);

        if ($numeric !== '' && is_numeric($numeric)) {
            return $numeric;
        }

        $this->addError($row, $familyNo, 'INCOME', 'monthlyincome',
            'Monthly income "' . $value . '" could not be read as a bracket or amount - imports with no income.'
            . ' The family is listed on the Data Completeness report.', 'warning');

        return null;
    }

    /** Validates sector/service age rules (e.g. SC must be >= 60). */
    private function validateAgeEligibility(int $row, string $familyNo, string $birthday, array $sectorIds, array $serviceIds): void
    {
        if ($this->sectorRows === null) {
            if ($this->sectorByCode !== null) {
                $this->sectorRows = [];
                foreach ($this->sectorByCode as $code => $id) {
                    $this->sectorRows[] = ['sectorID' => $id, 'shortcode' => $code];
                }
            } else {
                $this->sectorCodeMap();
            }
        }
        if ($this->serviceRows === null) {
            if ($this->serviceByCode !== null) {
                $this->serviceRows = [];
                foreach ($this->serviceByCode as $code => $id) {
                    $this->serviceRows[] = ['serviceID' => $id, 'shortcode' => $code, 'category' => $code];
                }
            } else {
                $this->serviceCodeMap();
            }
        }

        $error = FamilyAgeEligibility::selectionError(
            $birthday,
            $sectorIds,
            $serviceIds,
            $this->sectorRows,
            $this->serviceRows
        );

        if ($error !== null) {
            $this->addError($row, $familyNo, 'AGE-ELIG', 'birthday', $error, 'blocking');
        }
    }

    /**
     * Maps a row's comma-separated sector codes to IDs. Every canonical token must
     * exist in the reference lookup: an unknown token blocks the import rather than
     * being silently filed under Other. A list containing any bad token returns no
     * IDs, so a blocked row never carries a partial sector assignment.
     *
     * @param array{row: int, data: array<string, string>} $entry
     * @param array<string, int> $sectorByCode
     * @return int[]
     */
    private function mapSectors(array $entry, string $familyNo, array $sectorByCode): array
    {
        $ids     = [];
        $invalid = false;

        foreach ($this->splitList((string) ($entry['data']['sector'] ?? '')) as $token) {
            if (isset($sectorByCode[$token])) {
                $ids[] = $sectorByCode[$token];
                continue;
            }

            $this->addError((int) $entry['row'], $familyNo, 'SECTOR', 'sector',
                'Sector code "' . $token . '" is not on the Reference sheet. Choose a listed sector code.');
            $invalid = true;
        }

        return $invalid ? [] : array_values(array_unique($ids));
    }

    /**
     * Maps a row's comma-separated service codes to IDs. Every canonical token must
     * resolve to a reference service. A list containing any bad token returns no IDs,
     * so the blocking review state cannot carry a partial service assignment.
     *
     * @param array{row: int, data: array<string, string>} $entry
     * @param array<string, int> $serviceByCode
     * @return int[]
     */
    private function mapServices(array $entry, string $familyNo, array $serviceByCode): array
    {
        $ids     = [];
        $invalid = false;

        foreach ($this->splitList((string) ($entry['data']['services'] ?? '')) as $token) {
            $codes = $this->serviceTokens((int) $entry['row'], $familyNo, $token, $serviceByCode);

            if ($codes === []) {
                $invalid = true;
                continue;
            }

            foreach ($codes as $code) {
                $ids[] = $serviceByCode[$code];
            }
        }

        return $invalid ? [] : array_values(array_unique($ids));
    }

    /**
     * Resolves one already-canonical comma-delimited token to its service code.
     * Whitespace is never a delimiter: canonicalization has removed it, so a value
     * such as "EDA8 EDA9" is one invalid token ("EDA8EDA9") until corrected with
     * a comma.
     *
     * @param array<string, int> $serviceByCode
     * @return list<string>
     */
    private function serviceTokens(int $row, string $familyNo, string $token, array $serviceByCode): array
    {
        if (isset($serviceByCode[$token])) {
            return [$token];
        }

        $this->addError($row, $familyNo, 'SERVICE', 'services',
            'Service code "' . $token . '" is not on the Reference sheet. Choose a listed service code.');

        return [];
    }

    /** Returns canonical contact digits, blocking malformed supplied values. */
    private function contactValue(int $row, string $familyNo, string $value, bool $isHead): ?string
    {
        $contact = ContactNumber::parse($value);

        if (! $contact['supplied']) {
            if ($isHead) {
                $this->missingHeadCardField($row, $familyNo, 'contactnumber', 'Contact Number');
            }

            return null;
        }

        if (! $contact['valid']) {
            $this->addError($row, $familyNo, 'CONTACT', 'contactnumber',
                'Contact number "' . trim($value) . '" is not a valid mobile or Biñan landline number.');

            return null;
        }

        return $contact['value'];
    }

    /**
     * Maps a name suffix to a valid dropdown value (JR, SR, I-V) so the DB enum is always
     * satisfied. Blank stays blank. A trivial cleanup (case / trailing dot) is applied
     * silently; a real change ("Junior" -> "JR", "the 3rd" -> "III") is coerced with a
     * warning. Anything that maps to nothing is left blank (also enum-safe) with a warning.
     */
    private function validateSuffix(int $row, string $familyNo, string $raw): ?string
    {
        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        // Lookup key: lowercase, drop dots, collapse spaces, drop a leading "the ".
        $key = trim((string) preg_replace('/\s+/', ' ', str_replace('.', '', mb_strtolower($value))));
        $key = (string) preg_replace('/^the\s+/', '', $key);

        $canonical = self::SUFFIX_ALIASES[$key] ?? null;

        if ($canonical !== null) {
            // Only warn when it was a real change, not just case or a trailing dot.
            if (strcasecmp(rtrim($value, '.'), $canonical) !== 0) {
                $this->addError($row, $familyNo, 'SUFFIX', 'suffix',
                    'Suffix "' . $value . '" was changed to "' . $canonical . '" (the matching dropdown option).', 'warning');
            }

            return $canonical;
        }

        $this->addError($row, $familyNo, 'SUFFIX', 'suffix',
            'Suffix "' . $value . '" is not a valid option (Jr, Sr, I-V) - it will be left blank.', 'warning');

        return null;
    }

    /**
     * Flags a supplied barangay outside the official Biñan reference list. The match is
     * tolerant (case, ñ, dots and the "(...)" alias are ignored) so "Biñan"/"Sto. Tomas"
     * still pass. Only a Head establishes household Barangay, so member cells are ignored.
     */
    private function validateBarangay(int $row, string $familyNo, string $value): void
    {
        $value  = trim($value);
        $known  = $this->barangayLookup();

        // No reference rows means there is nothing to check against, and flagging
        // every row would be noise about the database rather than the file.
        if ($value === '' || $known === []) {
            return;
        }

        if (! isset($known[$this->normalizeBarangay($value)])) {
            $this->addError($row, $familyNo, 'BRGY', 'barangay',
                'Barangay "' . $value . '" is not an official Biñan barangay.');
        }
    }

    /**
     * The 1:1 check behind "Already in the system". An existing QR proves only that SOME
     * family owns that number - never that it is this one. So compare the incoming head
     * against the stored head:
     *
     *   same person  -> DUP-EXISTS. A genuine re-upload; the write step skips it. Stored
     *                   details the file disagrees with become DUP-DIFF, because the skip
     *                   means the DB keeps its values and the operator's edit is lost.
     *   anyone else  -> QR-TAKEN (blocking). A mistyped QR. Left alone, the write step
     *                   neither skips (this head is new to the DB) nor inserts (qr_control
     *                   already owns the number), and the family dies mid-import.
     *
     * Identity is first + last + birthday - the same test MemberModel::activeHeadExists
     * applies at write time, so the review predicts the write exactly.
     */
    private function checkExistingFamily(string $familyNo, array $headEntry, array $headPayload, array $existing): void
    {
        $row    = (int) $headEntry['row'];
        $record = is_array($existing['record'] ?? null) ? $existing['record'] : [];
        $stored = (string) ($existing['name'] ?? '');

        $sameName = $this->normalizeText((string) ($headPayload['firstname'] ?? '')) === $this->normalizeText((string) ($record['firstname'] ?? ''))
            && $this->normalizeText((string) ($headPayload['lastname'] ?? '')) === $this->normalizeText((string) ($record['lastname'] ?? ''));

        if (! $sameName) {
            $incoming = $this->personName($headPayload);

            $this->addError($row, $familyNo, 'QR-TAKEN', 'familyno',
                'QR ' . $familyNo . ' already belongs to ' . $stored . ' in the system, but this row is '
                . ($incoming !== '' ? $incoming : 'someone else')
                . '. Check the QR number - if this really is a different family, give it its own QR.');

            return;
        }

        $fileBirthday   = trim((string) ($headPayload['birthday'] ?? ''));
        $storedBirthday = trim((string) ($record['birthday'] ?? ''));

        if ($fileBirthday !== $storedBirthday) {
            $this->addError($row, $familyNo, 'QR-TAKEN', 'birthday',
                'QR ' . $familyNo . ' belongs to ' . $stored . ', whose birthday on file is '
                . ($storedBirthday !== '' ? $storedBirthday : 'not set') . ', but this row says '
                . ($fileBirthday !== '' ? $fileBirthday : 'not set')
                . '. Fix whichever is wrong - as it stands the import cannot save this family.');

            return;
        }

        // Same person, same family: a re-upload. The write step skips it.
        $this->addError($row, $familyNo, 'DUP-EXISTS', 'familyno',
            'Family ' . $familyNo . ' is already in the system (' . $stored . '). It will be skipped if you import.', 'warning');

        $differences = $this->comparePersonToRecord($headPayload, $record);

        if ($differences !== []) {
            $this->addError($row, $familyNo, 'DUP-DIFF', 'familyno',
                'Family ' . $familyNo . ' (' . $stored . ') is already in the system, but the file does not match what is stored: '
                . implode('; ', $differences)
                . '. Families already on file are SKIPPED, so these changes will NOT be saved - edit the record in Manage Family instead.', 'warning');
        }
    }

    /**
     * Fields where the uploaded person and the stored record disagree. Identity fields are
     * excluded: a difference there is not a drifted detail, it is a different person, and
     * checkExistingFamily has already blocked it.
     *
     * @return list<string>
     */
    private function comparePersonToRecord(array $payload, array $record): array
    {
        $fields = [
            'middlename'    => 'Middle Name',
            'suffix'        => 'Suffix',
            'sex'           => 'Sex',
            'civilstatus'   => 'Civil Status',
            'contactnumber' => 'Contact Number',
            'religion'      => 'Religion',
            'address'       => 'Address',
        ];

        $differences = [];

        foreach ($fields as $key => $label) {
            $file   = trim((string) ($payload[$key] ?? ''));
            $stored = trim((string) ($record[$key] ?? ''));

            if ($this->normalizeText($file) === $this->normalizeText($stored)) {
                continue;
            }

            $differences[] = $label . ' - file has ' . ($file !== '' ? '"' . $file . '"' : '(blank)')
                . ', system has ' . ($stored !== '' ? '"' . $stored . '"' : '(blank)');
        }

        return $differences;
    }

    /**
     * Flags people in the batch who are ALREADY on file, matched on the same first + last +
     * birthday the write step uses. This is the gap that hurt most: a head re-entered under
     * a NEW QR reviews perfectly clean, then activeHeadExists silently skips the whole
     * family - members and all - and the operator is never told.
     *
     * A person matched to the family they are already in is skipped here: that is the
     * DUP-EXISTS re-upload (or the already-a-member append), both reported elsewhere.
     *
     * @param array<int|string, list<array{row: int, data: array<string,string>}>> $groups
     */
    private function checkExistingPeople(array $groups): void
    {
        if ($this->existingPeople === []) {
            return;
        }

        foreach ($groups as $qr => $rows) {
            foreach ($rows as $entry) {
                $data = $entry['data'];

                $key = $this->identityKey(
                    (string) ($data['firstname'] ?? ''),
                    (string) ($data['lastname'] ?? ''),
                    $this->normalizeBirthday((string) ($data['birthday'] ?? '')),
                );

                $match = $key !== '' ? ($this->existingPeople[$key] ?? null) : null;

                if ($match === null || (int) $match['qr'] === (int) $qr) {
                    continue;
                }

                $isHead = strcasecmp(trim((string) ($data['relationship'] ?? '')), 'Head') === 0;
                $where  = $match['qr'] > 0 ? 'family ' . $match['qr'] : 'another family';

                $this->addError((int) $entry['row'], (string) $qr, 'DUP-DB', 'familyno',
                    $match['name'] . ' is already in the system under ' . $where
                    . ($isHead
                        ? '. A family whose head is already on file is SKIPPED on import - this whole group, members and all, will NOT be saved. Check the QR number, or delete these rows from the file.'
                        : '. If this is the same person, delete this row; if it is a different person who happens to share the name and birthday, they will both be kept.'),
                    'warning');
            }
        }
    }

    /**
     * Y-m-d for a sheet birthday, or null when blank/unparseable. Emits no errors.
     * Tolerant, shared with parseSheetBirthday(), so identity keys match the payload's dates.
     */
    private function normalizeBirthday(string $value): ?string
    {
        $date = $this->parseSheetBirthday($value);

        return $date === null ? null : $date->format('Y-m-d');
    }

    /**
     * Identity for matching a person against the DB: first + last + birthday, folded.
     * Mirrors MemberModel::activeHeadExists - the very test that decides the write-time
     * skip - so the review predicts the write instead of guessing. '' when unusable
     * (a blank name is already reported as REQUIRED; it must not match anything).
     */
    private function identityKey(string $first, string $last, ?string $birthday): string
    {
        $first = $this->normalizeText($first);
        $last  = $this->normalizeText($last);

        if ($first === '' || $last === '') {
            return '';
        }

        return $first . '|' . $last . '|' . trim((string) $birthday);
    }

    /** "First Last" for a stored member row or a built payload. */
    private function personName(array $record): string
    {
        return trim(((string) ($record['firstname'] ?? '')) . ' ' . ((string) ($record['lastname'] ?? '')));
    }

    /**
     * Finds deterministic in-file duplicate rows. The full key deliberately includes the
     * relationship and household fields: a matching name and birthday alone is not proof
     * that two people are copies. Groups never cross a QR number.
     *
     * @param array<int|string, list<array{row: int, data: array<string,string>}>> $groups
     * @return list<array{rows:list<int>,qr:string}>
     */
    private function classifyDuplicateRows(array $groups): array
    {
        $duplicateGroups = [];

        foreach ($groups as $qr => $rows) {
            $byKey = [];

            foreach ($rows as $entry) {
                $data = $entry['data'];
                $first = $this->normalizeText((string) ($data['firstname'] ?? ''));
                $last = $this->normalizeText((string) ($data['lastname'] ?? ''));
                $birthday = $this->normalizeBirthday((string) ($data['birthday'] ?? ''));

                // An incomplete identity cannot prove that rows are copies.
                if ($first === '' || $last === '' || $birthday === null) {
                    continue;
                }

                $key = json_encode([
                    $this->normalizeText((string) ($data['relationship'] ?? '')),
                    $first,
                    $this->normalizeText((string) ($data['middlename'] ?? '')),
                    $last,
                    $this->normalizeText(str_replace('.', '', (string) ($data['suffix'] ?? ''))),
                    $birthday,
                    $this->normalizeText((string) ($data['address'] ?? '')),
                    $this->normalizeText((string) ($data['barangay'] ?? '')),
                ], JSON_THROW_ON_ERROR);

                $byKey[$key][] = (int) $entry['row'];
            }

            foreach ($byKey as $candidateRows) {
                if (count($candidateRows) < 2) {
                    continue;
                }

                $duplicateGroups[] = ['rows' => $candidateRows, 'qr' => (string) $qr];

                foreach ($candidateRows as $row) {
                    $others = array_values(array_filter($candidateRows, static fn (int $other): bool => $other !== $row));
                    $this->addError($row, (string) $qr, 'DUP-ROW', null,
                        'This is an exact duplicate of row(s) ' . implode(', ', $others)
                        . '. Keep one complete row and discard the copies from this import.');
                }
            }
        }

        return $duplicateGroups;
    }

    // -- lookups + helpers -----------------------------------------------------

    /**
     * [UPPER shortcode => sectorID] of active sectors. Only real reference shortcodes
     * are mapped - unknown tokens are rejected rather than filed under Other. Cached
     * for re-validation.
     */
    private function sectorCodeMap(): array
    {
        if ($this->sectorByCode !== null) {
            return $this->sectorByCode;
        }

        $map = [];
        $this->sectorRows = (new SectorModel())->getActive();

        foreach ($this->sectorRows as $sector) {
            $code = strtoupper(trim((string) ($sector['shortcode'] ?? '')));
            $id   = (int) ($sector['sectorID'] ?? 0);

            if ($code !== '' && $id > 0) {
                $map[$code] = $id;
            }
        }

        return $this->sectorByCode = $map;
    }

    /** [UPPER shortcode => serviceID] of active services. Cached for re-validation. */
    private function serviceCodeMap(): array
    {
        if ($this->serviceByCode !== null) {
            return $this->serviceByCode;
        }

        $map = [];
        $this->serviceRows = (new ServiceModel())->getActive();

        foreach ($this->serviceRows as $service) {
            $code = strtoupper(trim((string) ($service['shortcode'] ?? '')));
            $id   = (int) ($service['serviceID'] ?? 0);

            if ($code !== '' && $id > 0) {
                $map[$code] = $id;
            }
        }

        return $this->serviceByCode = $map;
    }

    /** [lowercase bracket label => stored numeric value]. Cached for re-validation. */
    private function incomeLabelMap(): array
    {
        if ($this->incomeByLabel !== null) {
            return $this->incomeByLabel;
        }

        $map = [];

        foreach ((new FamilyFormOptionsModel())->getOptions()['income_ranges'] ?? [] as $range) {
            $value = (string) ($range['value'] ?? '');
            $label = strtolower(trim((string) ($range['label'] ?? '')));

            if ($value !== '' && $label !== '') {
                $map[$label] = $value;
            }
        }

        return $this->incomeByLabel = $map;
    }

    /**
     * Translates a civil-status / education cell to its full stored value. Accepts a
     * bare code, a "CODE - Name" pick, or the full name typed out.
     *
     * The stored values are uppercase, so a sheet that spells the name instead of
     * the code ("Single", "High School") is matched against the map's values as
     * well as its keys and uppercased - otherwise those rows would store a
     * differently-cased copy of a value the dropdowns no longer offer.
     *
     * @param array<string,string> $codeMap code => full value
     */
    private function fullValueFromCode(string $value, array $codeMap): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        $token = str_contains($value, ' - ') ? trim(explode(' - ', $value)[0]) : $value;

        foreach ($codeMap as $code => $full) {
            if (strcasecmp($token, $code) === 0 || strcasecmp($value, $full) === 0) {
                return $full;
            }
        }

        return mb_strtoupper($value, 'UTF-8');
    }

    /** Normalizes a header label to lowercase alphanumerics ("Monthly Income" -> "monthlyincome"). */
    private function normalizeHeader(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $value)));
    }

    /** Lowercased, whitespace-collapsed value for case/spacing-insensitive comparison. */
    private function normalizeText(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    /**
     * [normalized barangay => true] from the `barangay` table, built once per
     * importer. The list used to be a second hardcoded copy in
     * FamilyProfilingFormV2 that had drifted from the table it was meant to
     * mirror, so a sheet could name a barangay the table did not have.
     */
    private function barangayLookup(): array
    {
        if ($this->barangayLookup !== null) {
            return $this->barangayLookup;
        }

        $set = [];

        foreach ((new \App\Models\Lookups\BarangayModel())->activeNames() as $barangay) {
            $set[$this->normalizeBarangay($barangay)] = true;
        }

        return $this->barangayLookup = $set;
    }

    /**
     * Folds a barangay to a comparable form: lowercase, ñ→n, the "(alias)" dropped,
     * "Sto./Sta." expanded to "Santo/Santa", punctuation removed. So "Biñan",
     * "Sto. Tomas" and "Santo Tomas (Calabuso)" all reduce to the same key.
     */
    private function normalizeBarangay(string $value): string
    {
        return MemberFieldNormalizer::barangayKey($value);
    }

    /**
     * Folded barangay name to barangayID, loaded once via App\Models\Lookups\
     * BarangayModel. Backs barangayIdForHead() so member.barangayID gets set from
     * the same fold this class already uses to validate the cell.
     */
    private function barangayIdMap(): array
    {
        return $this->barangayIdMap ??= (new \App\Models\Lookups\BarangayModel())->idByNormalizedName();
    }

    /**
     * Resolves a head's Barangay cell to its barangayID, or null when blank or
     * unrecognised. A blank cell carries an INCOMPLETE warning and an unrecognised one
     * a blocking BRGY error, so the null matches the row's review verdict.
     */
    private function barangayIdForHead(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return $this->barangayIdMap()[$this->normalizeBarangay($value)] ?? null;
    }

    /**
     * Canonicalizes a comma-separated code list without inferring separate codes
     * from whitespace. Strict-code validation handles every resulting token.
     */
    private function canonicalCodeList(string $value): string
    {
        $codes = array_map(
            static fn (string $code): string => mb_strtoupper(
                (string) preg_replace('/\s+/u', '', trim($code)),
                'UTF-8'
            ),
            explode(',', $value)
        );

        return implode(',', array_values(array_filter($codes, static fn (string $code): bool => $code !== '')));
    }

    /** @return list<string> Non-empty, trimmed tokens from a comma-separated cell. */
    private function splitList(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $tokens = array_map('trim', explode(',', $value));

        return array_values(array_filter(
            $tokens,
            static fn (string $t): bool => $t !== '' && ! MemberFieldNormalizer::isNoData($t)
        ));
    }

    /** @param array<string, string> $values */
    private function rowIsEmpty(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /** True when a merge range (e.g. "A3:A6" or "A3") intersects the given column letter. */
    private function rangeTouchesColumn(string $range, string $letter): bool
    {
        [$start, $end] = array_pad(explode(':', $range, 2), 2, $range);

        $startCol = Coordinate::columnIndexFromString(preg_replace('/[0-9]+/', '', $start) ?: 'A');
        $endCol   = Coordinate::columnIndexFromString(preg_replace('/[0-9]+/', '', $end) ?: 'A');
        $target   = Coordinate::columnIndexFromString($letter);

        return $target >= min($startCol, $endCol) && $target <= max($startCol, $endCol);
    }

    /** Highest row number in a merge range (e.g. "A1:B1" -> 1, "A3" -> 3). */
    private function rangeMaxRow(string $range): int
    {
        [$start, $end] = array_pad(explode(':', $range, 2), 2, $range);

        return max(
            (int) preg_replace('/[^0-9]/', '', $start),
            (int) preg_replace('/[^0-9]/', '', $end)
        );
    }

    /** Builds a hard parse-failure bundle carrying one file-level blocking error. */
    private function parseFailure(string $message): array
    {
        return [
            'ok'     => false,
            'rows'   => [],
            'errors' => [$this->makeError(null, '', 'FILE', null, $message)],
        ];
    }

    /** Builds an error record without pushing it onto the instance list. */
    private function makeError(?int $sheetRow, string $familyNo, string $code, ?string $field, string $message, string $severity = 'blocking'): array
    {
        return [
            'sheetRow' => $sheetRow,
            'familyNo' => $familyNo,
            'field'    => $field,
            'code'     => $code,
            'message'  => $message,
            'severity' => $severity,
        ];
    }

    private function addError(?int $sheetRow, string $familyNo, string $code, ?string $field, string $message, string $severity = 'blocking'): void
    {
        $this->errors[] = $this->makeError($sheetRow, $familyNo, $code, $field, $message, $severity);
    }

    /** Counts errors of a given severity. @param list<array> $errors */
    private function tally(array $errors, string $severity): int
    {
        return count(array_filter($errors, static fn (array $e): bool => ($e['severity'] ?? 'blocking') === $severity));
    }
}
