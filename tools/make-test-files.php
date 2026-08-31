<?php

/**
 * Generates three family-import test workbooks on the REAL FamilyExcelTemplate, using the
 * sheet's actual DROPDOWN option strings for every dropdown column (Relationship, Suffix,
 * Sex, CivilStatus as "CODE - Name", Religion, Education as "CODE - Name", Job, MonthlyIncome
 * as bracket labels, Barangay) — so a tester can pick any cell and see the value already
 * matches its dropdown.
 *
 *   family-import-100A.xlsx      — 100 people, clean valid families (import first).
 *   family-import-100B.xlsx      — 100 people, clean valid families, no overlap with A.
 *   family-import-ALL-ERRORS.xlsx — every remaining blocking code plus warning coverage,
 *                                   including incomplete profile fields.
 *   family-import-C-10k-clean.xlsx  — 10,000 people, all warning-free (bulk load test).
 *   family-import-D-10k-errors.xlsx — 10,000 people, ~1,000 with a seeded field-level issue,
 *                                     the rest warning-free (bulk load + error-handling test).
 *
 *   php tools/make-test-files.php
 *
 * IMPORTANT ordering: member/qr_control were truncated, so QR numbers only exist in the DB
 * after you import them. The DB-aware error codes (DUP-EXISTS, DUP-DIFF, QR-TAKEN, DUP-DB,
 * ADD-MEMBER) reference the REFERENCE FAMILIES file A creates (QR 1-5). Import 100A
 * first, THEN ALL-ERRORS. The non-DB codes fire on their own.
 *
 * Expected ALL-ERRORS seed coverage:
 *   blocking: DUP-EXISTS x2, DUP-DIFF x1, ADD-MEMBER x1, DUP-DB x1, QR-TAKEN x1,
 *     HEAD-MULTI x1, FP-ADDR x1, HEAD-NONE x1, REQUIRED x2, LENGTH x1,
 *     QR-01 x1, QR-FORMAT x1, QR-05 x1, QR-07 x1, QR-08 x1, QR-12 x1.
 *   warnings: INCOMPLETE x6, SERVICE x1, BRGY x1, SEX x1, BDAY x1, INCOME x1,
 *     CONTACT x1, SUFFIX x1, BDAY-RANGE x1, SECTOR x1, DUP-PERSON x1,
 *     QR-CONTIG x1, QR-11 x1.
 *   row counts: 100A=100, 100B=100, ALL-ERRORS=40, C=10000, D=10000.
 */

use CodeIgniter\Boot;
use Config\Paths;

$root = dirname(__DIR__);

define('FCPATH', $root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR);
chdir(FCPATH);

require $root . '/app/Config/Paths.php';
$paths = new Paths();
require $paths->systemDirectory . '/Boot.php';
Boot::bootWorker($paths);

use App\Libraries\FamilyExcelTemplate;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Column order: A=QR B=Relationship C=LastName D=FirstName E=MiddleName F=Suffix
// G=Birthday H=Sex I=CivilStatus J=ContactNumber K=Religion L=Education M=Job
// N=MonthlyIncome O=Address P=Barangay Q=Sector R=Services

// -- exact dropdown option strings the template offers -------------------------
$OFFICIAL_BRGY = [
    'Binan', 'Bungahan', 'Santo Tomas (Calabuso)', 'Canlalay', 'Casile', 'De La Paz',
    'Ganado', 'San Francisco (Halang)', 'Langkiwa', 'Loma', 'Malaban', 'Malamig',
    'Mamplasan', 'Platero', 'Poblacion', 'Santo Nino', 'San Antonio', 'San Jose',
    'San Vicente', 'Soro-Soro', 'Santo Domingo', 'Timbao', 'Tubigan', 'Zapote',
];
$CIVIL_OPT  = ['S - Single', 'M - Married', 'W - Widow / Widower', 'H - Separated', 'N - Live-in / Not Married'];
$EDU_OPT    = ['E - Elementary', 'HS - High School', 'UG - Undergraduate', 'Voc - Vocational', 'CG - College Graduate', 'PG - Post Graduate'];
$INCOME_OPT = ['No regular income', 'Below PHP 8,000', 'PHP 8,000 - 13,000', 'PHP 13,001 - 18,000', 'PHP 18,001 - 25,000', 'PHP 25,001 - 40,000', 'PHP 40,001 - 65,000', 'PHP 65,001 - 100,000', 'PHP 100,001 - 150,000', 'PHP 150,001 - 250,000', 'Above PHP 250,000'];
$JOB_OPT    = ['Unemployed', 'Student', 'Homemaker', 'Self-employed', 'Vendor', 'Driver', 'Construction Worker', 'Factory Worker', 'Office Staff', 'Teacher', 'Healthcare Worker', 'Government Employee', 'Private Employee', 'OFW', 'Retired'];
$RELIG_OPT  = ['Roman Catholic', 'Iglesia ni Cristo', 'Islam', 'Born Again Christian', 'Protestant', 'Seventh-day Adventist', 'Bible Baptist', 'No Religion'];
$SUFFIX_OPT = ['Jr', 'Sr', 'II', 'III'];
$REL_OPT    = ['Spouse', 'Child', 'Parent', 'Sibling'];

$LAST   = ['Reyes', 'Santos', 'Cruz', 'Garcia', 'Torres', 'Flores', 'Ramos', 'Mendoza', 'Aquino', 'Castro', 'Rivera', 'Gonzales', 'Bautista', 'Villanueva', 'Navarro', 'Salazar', 'Padilla', 'Gutierrez', 'Domingo', 'Fernandez'];
$FIRST  = ['Juan', 'Maria', 'Pedro', 'Ana', 'Jose', 'Rosa', 'Antonio', 'Elena', 'Ramon', 'Grace', 'Carlos', 'Cora', 'Miguel', 'Liza', 'Andres', 'Anna', 'Rafael', 'Luz', 'Noel', 'Fely'];
$MID    = ['Santos', 'Cruz', 'Lim', 'Diaz', 'Reyes', 'Sy', 'Uy', 'Go', 'Tan', 'Roque', 'Panganiban', 'Malabanan', 'Lopez'];
$STREET = ['Rizal', 'Mabini', 'Bonifacio', 'Aguinaldo', 'Narra', 'Acacia', 'Molave', 'Ipil', 'Balete', 'Mahogany'];

/** One ordered A..R cell array. */
function mkRow(string $qr, string $rel, string $last, string $first, string $mid, string $suffix, string $bday, string $sex, string $civil, string $contact, string $relig, string $edu, string $job, string $income, string $addr, string $brgy, string $sector = '', string $services = ''): array
{
    return [$qr, $rel, $last, $first, $mid, $suffix, $bday, $sex, $civil, $contact, $relig, $edu, $job, $income, $addr, $brgy, $sector, $services];
}

/**
 * Realistic member profile fields by relationship (members now require the same personal set
 * as the head). Returns [civilstatus, education, job, monthlyincome] as dropdown option strings.
 */
function memberDefaults(string $rel): array
{
    switch (strtolower(trim($rel))) {
        case 'child':   return ['S - Single', 'E - Elementary', 'Student', 'No regular income'];
        case 'spouse':  return ['M - Married', 'HS - High School', 'Homemaker', 'No regular income'];
        case 'parent':  return ['W - Widow / Widower', 'E - Elementary', 'Retired', 'No regular income'];
        default:        return ['S - Single', 'HS - High School', 'Unemployed', 'No regular income'];
    }
}

/**
 * Populates the OPTIONAL columns that would otherwise be blank — Sector, Services, Contact
 * number, Religion — so the test data reads as fully filled. Sector/Services are aligned VALID
 * pairs (children get Bata services), never unknown codes, so the clean files stay error-free.
 * Cells already set (e.g. an intentional 'ZZZ' service or '12345' contact in the error file)
 * are left untouched, so seeded errors survive.
 *
 * @param list<array> $rows
 * @return list<array>
 */
function fillOptional(array $rows): array
{
    global $RELIG_OPT;

    // Same index => aligned sector/service pair, so a sector's services actually match it.
    $sectorPool  = ['SC', 'PWD', 'SP', 'B', 'OFW', 'IP', 'IW'];
    $servicePool = ['SC1, SC2', 'PWD1, PWD3', 'SP1', 'B2, B3', 'FA6, EDA5', 'SWPS4, 4PS', 'FA2'];

    $i = 0;
    foreach ($rows as &$r) {
        $isChild = strcasecmp(trim((string) ($r[1] ?? '')), 'Child') === 0;

        if (trim((string) ($r[16] ?? '')) === '') {          // Sector (col Q)
            $r[16] = $isChild ? 'B' : $sectorPool[$i % count($sectorPool)];
        }
        if (trim((string) ($r[17] ?? '')) === '') {          // Services (col R)
            $r[17] = $isChild ? 'B2, B3, 4PS' : $servicePool[$i % count($servicePool)];
        }
        if (trim((string) ($r[9] ?? '')) === '') {           // Contact number (col J)
            $r[9] = '09' . str_pad((string) ((($i * 37) + 11) % 1000000000), 9, '0', STR_PAD_LEFT);
        }
        if (trim((string) ($r[10] ?? '')) === '') {          // Religion (col K)
            $r[10] = $RELIG_OPT[$i % count($RELIG_OPT)];
        }
        $i++;
    }
    unset($r);

    return $rows;
}

/**
 * The 5 fixed reference families file A creates and the error file references. Values use the
 * exact dropdown option strings. Kept explicit so DB-aware error rows match the stored head
 * (identity = first + last + birthday; DUP-DIFF also compares civil/contact/religion/address).
 */
function referenceFamilies(): array
{
    return [
        // qr, last,           first,      mid,          bday,         sex,      civil,                contact,       religion,            edu,                       job,                  income,                 address,             barangay
        ['1', 'Reyes',        'Juan',     'Santos',     '05-14-1980', 'Male',   'M - Married',        '09171000001', 'Roman Catholic',    'CG - College Graduate',   'Teacher',            'PHP 18,001 - 25,000',  '12 Rizal St.',      'Poblacion', [['Spouse', 'Reyes', 'Maria', 'Cruz', '06-02-1982', 'Female'], ['Child', 'Reyes', 'Jose', 'Cruz', '01-10-2012', 'Male']]],
        ['2', 'Santos',       'Pedro',    'Lim',        '07-07-1975', 'Male',   'M - Married',        '09171000002', 'Roman Catholic',    'HS - High School',        'Driver',             'PHP 13,001 - 18,000',  '5 Mabini St.',      'Malaban', [['Spouse', 'Santos', 'Ana', 'Diaz', '08-08-1978', 'Female']]],
        ['3', 'Cruz',         'Rafael',   'Malabanan',  '04-28-1969', 'Male',   'W - Widow / Widower', '09171000003', 'Roman Catholic',    'UG - Undergraduate',      'Retired',            'PHP 8,000 - 13,000',   '59 Magsaysay St.',  'Malamig', [['Child', 'Cruz', 'Toni', 'Reyes', '09-09-2010', 'Female']]],
        ['4', 'Andrada',      'Ronald',   'Sy',         '09-28-1983', 'Male',   'M - Married',        '09171000004', 'Iglesia ni Cristo', 'UG - Undergraduate',      'Construction Worker', 'PHP 13,001 - 18,000',  '675 Narra St.',     'Loma', [['Child', 'Andrada', 'Liza', 'Sy', '02-14-2012', 'Female']]],
        ['5', 'Buenaventura', 'Michelle', 'Panganiban', '09-22-1984', 'Female', 'S - Single',         '09171000005', 'Roman Catholic',    'CG - College Graduate',   'Healthcare Worker',   'PHP 25,001 - 40,000',  '949 Magsaysay St.', 'Canlalay', [['Child', 'Buenaventura', 'Nico', 'Panganiban', '07-11-2008', 'Male']]],
    ];
}

/** Reference families as sheet rows (head + members). */
function referenceRows(): array
{
    $rows = [];

    foreach (referenceFamilies() as $f) {
        [$qr, $last, $first, $mid, $bday, $sex, $civil, $contact, $relig, $edu, $job, $income, $addr, $brgy, $members] = $f;
        $rows[] = mkRow($qr, 'Head', $last, $first, $mid, '', $bday, $sex, $civil, $contact, $relig, $edu, $job, $income, $addr, $brgy);

        foreach ($members as [$rel, $mLast, $mFirst, $mMid, $mBday, $mSex]) {
            [$mCivil, $mEdu, $mJob, $mIncome] = memberDefaults($rel);
            $rows[] = mkRow($qr, $rel, $mLast, $mFirst, $mMid, '', $mBday, $mSex, $mCivil, '', '', $mEdu, $mJob, $mIncome, '', '');
        }
    }

    return $rows;
}

/**
 * Deterministically builds clean, valid families until $target people exist, using dropdown
 * option strings. Birthdays are unique per person so no two people collide on name+birthday
 * (which would trip DUP-PERSON). Members inherit the head's address (left blank).
 */
function generateClean(int $startQr, int $target, int $baseYear, int $nameSkew): array
{
    global $OFFICIAL_BRGY, $LAST, $FIRST, $MID, $STREET, $CIVIL_OPT, $EDU_OPT, $INCOME_OPT, $JOB_OPT, $RELIG_OPT, $SUFFIX_OPT, $REL_OPT;

    $rows    = [];
    $persons = 0;
    $qr      = $startQr;
    $fam     = 0;
    $p       = 0;
    $base    = new DateTimeImmutable(sprintf('%04d-01-01', $baseYear));

    while ($persons < $target) {
        $hi      = $p + $nameSkew;
        $bday    = $base->modify('+' . $p . ' days')->format('m-d-Y');
        $contact = '09' . str_pad((string) (($p * 7 + $nameSkew) % 1000000000), 9, '0', STR_PAD_LEFT);
        $suffix  = ($fam % 5 === 0) ? $SUFFIX_OPT[$fam % count($SUFFIX_OPT)] : '';  // exercise the Suffix dropdown

        $rows[] = mkRow(
            (string) $qr,
            'Head',
            $LAST[$fam % count($LAST)],
            $FIRST[$hi % count($FIRST)],
            $MID[$hi % count($MID)],
            $suffix,
            $bday,
            ($p % 2 === 0) ? 'Male' : 'Female',
            $CIVIL_OPT[$fam % count($CIVIL_OPT)],
            $contact,
            $RELIG_OPT[$fam % count($RELIG_OPT)],
            $EDU_OPT[$hi % count($EDU_OPT)],
            $JOB_OPT[$hi % count($JOB_OPT)],
            $INCOME_OPT[$fam % count($INCOME_OPT)],
            (($fam * 3 + 1) % 900 + 1) . ' ' . $STREET[$fam % count($STREET)] . ' St.',
            $OFFICIAL_BRGY[$fam % count($OFFICIAL_BRGY)],
        );
        $persons++;
        $p++;

        $memberCap = $fam % 4; // 0..3 members
        for ($m = 0; $m < $memberCap && $persons < $target; $m++) {
            $mi  = $p + $nameSkew;
            $rel = $REL_OPT[$m % count($REL_OPT)];
            [$mCivil, $mEdu, $mJob, $mIncome] = memberDefaults($rel);
            $rows[] = mkRow(
                (string) $qr,
                $rel,
                $LAST[$fam % count($LAST)],
                $FIRST[$mi % count($FIRST)],
                $MID[$mi % count($MID)],
                '',
                $base->modify('+' . $p . ' days')->format('m-d-Y'),
                ($p % 2 === 0) ? 'Male' : 'Female',
                $mCivil, '', '', $mEdu, $mJob, $mIncome, '', '',
            );
            $persons++;
            $p++;
        }

        $qr++;
        $fam++;
    }

    return ['rows' => $rows, 'nextQr' => $qr];
}

/** All-errors rows. Identity and structure blockers are explicit; warning seeds may be blank. */
function errorRows(): array
{
    $byQr = [];
    foreach (referenceFamilies() as $f) {
        $byQr[$f[0]] = $f;
    }
    $r1001 = $byQr['1'];
    $r1002 = $byQr['2'];
    $r1004 = $byQr['4'];

    $longName = str_repeat('Juanito', 16); // 112 chars > 100 -> LENGTH

    return [
        // ===== YELLOW: already in the system (need file A imported first) ==========
        // DUP-EXISTS: exact re-upload of reference family QR 1 -> skipped.
        mkRow('1', 'Head', $r1001[1], $r1001[2], $r1001[3], '', $r1001[4], $r1001[5], $r1001[6], $r1001[7], $r1001[8], $r1001[9], $r1001[10], $r1001[11], $r1001[12], $r1001[13]),

        // DUP-EXISTS + DUP-DIFF: reference family QR 2 head, but a NEW phone number.
        mkRow('2', 'Head', $r1002[1], $r1002[2], $r1002[3], '', $r1002[4], $r1002[5], $r1002[6], '09990000002', $r1002[7], $r1002[8], $r1002[9], $r1002[10], $r1002[11], $r1002[12]),

        // ADD-MEMBER: no Head, QR 5 already exists -> this person is ADDED to that family.
        mkRow('5', 'Child', 'Buenaventura', 'Rico', 'Panganiban', '', '03-15-2011', 'Male', 'S - Single', '', '', 'E - Elementary', 'Student', 'No regular income', '', ''),

        // DUP-DB (yellow, silent skip): reference head QR 4 re-entered under a BRAND-NEW QR.
        mkRow('9100017', 'Head', $r1004[1], $r1004[2], $r1004[3], '', $r1004[4], $r1004[5], $r1004[6], $r1004[7], $r1004[8], $r1004[9], $r1004[10], $r1004[11], $r1004[12], $r1004[13]),
        mkRow('9100017', 'Child', 'Andrada', 'Ben', 'Sy', '', '05-20-2014', 'Male', 'S - Single', '', '', 'E - Elementary', 'Student', 'No regular income', '', ''),

        // ===== RED: mistyped QR onto an existing family ============================
        // QR-TAKEN: QR 3 belongs to Rafael Cruz, but this is a DIFFERENT person.
        mkRow('3', 'Head', 'Villar', 'Cristina', 'Mora', '', '02-11-1988', 'Female', 'S - Single', '09171230000', 'Roman Catholic', 'CG - College Graduate', 'Office Staff', 'PHP 25,001 - 40,000', '77 Acacia St.', 'Tubigan'),

        // ===== RED: family structure ==============================================
        // HEAD-MULTI: two complete Head rows under one QR.
        mkRow('9100001', 'Head', 'Torres', 'Ben', 'Uy', '', '11-05-1970', 'Male', 'M - Married', '09171230001', 'Roman Catholic', 'HS - High School', 'Driver', 'PHP 13,001 - 18,000', '5 Bonifacio St.', 'Malaban'),
        mkRow('9100001', 'Head', 'Torres', 'Cora', 'Uy', '', '12-08-1972', 'Female', 'M - Married', '09171230002', 'Roman Catholic', 'HS - High School', 'Vendor', 'PHP 13,001 - 18,000', '5 Bonifacio St.', 'Malaban'),

        // FP-ADDR: one QR, one Head, two households (address AND barangay differ).
        mkRow('9100002', 'Head', 'Aquino', 'Pedro', 'Roque', '', '04-04-1965', 'Male', 'M - Married', '09171230003', 'Roman Catholic', 'E - Elementary', 'Vendor', 'Below PHP 8,000', '1 Acacia St.', 'Timbao'),
        mkRow('9100002', 'Spouse', 'Bautista', 'Elena', 'Diaz', '', '09-09-1969', 'Female', 'W - Widow / Widower', '09171230004', 'Roman Catholic', 'E - Elementary', 'Vendor', 'Below PHP 8,000', '99 Ipil St.', 'De La Paz'),

        // HEAD-NONE: new QR, no Head row. The address-carrier (complete data) is the likely head.
        mkRow('9100003', 'Spouse', 'Dela Rosa', 'Rosa', 'Cruz', '', '06-02-1978', 'Female', 'M - Married', '09171230005', 'Roman Catholic', 'HS - High School', 'Homemaker', 'No regular income', '88 Molave St.', 'Poblacion'),
        mkRow('9100003', 'Child', 'Dela Rosa', 'Mark', 'Cruz', '', '01-30-2010', 'Male', 'S - Single', '', '', 'E - Elementary', 'Student', 'No regular income', '', ''),

        // ===== YELLOW: field-level warnings (cell filled but invalid) ==============
        // SEX invalid (all other dropdowns valid).
        mkRow('9100004', 'Head', 'Lopez', 'Andres', 'Vega', '', '02-02-1980', 'Malee', 'M - Married', '09171230006', 'Roman Catholic', 'HS - High School', 'Factory Worker', 'PHP 8,000 - 13,000', '77 Sampaguita St.', 'Zapote'),
        // BDAY invalid date.
        mkRow('9100005', 'Head', 'Ramos', 'Nilo', 'Cruz', '', '31-31-2000', 'Male', 'S - Single', '09171230007', 'Roman Catholic', 'HS - High School', 'Driver', 'PHP 8,000 - 13,000', '4 Narra St.', 'Malaban'),
        // INCOME not a bracket/number (warning; currency-prefixed values such as P3000 pass).
        mkRow('9100006', 'Head', 'Flores', 'Rene', 'Lim', '', '03-03-1979', 'Male', 'M - Married', '09171230008', 'Roman Catholic', 'HS - High School', 'Vendor', 'plenty', '9 Ilang St.', 'Ganado'),
        // SERVICE unknown code (typo aliases are accepted, unknown tokens warn and skip).
        mkRow('9100007', 'Head', 'Castro', 'Fely', 'Go', '', '04-04-1982', 'Female', 'S - Single', '09171230009', 'Roman Catholic', 'CG - College Graduate', 'Teacher', 'PHP 18,001 - 25,000', '3 Ipil St.', 'Platero', '', 'ZZZ'),
        // ===== RED: over-long value ================================================
        // LENGTH: first name over 100 chars.
        mkRow('9100008', 'Head', 'Mercado', $longName, '', '', '05-05-1981', 'Male', 'M - Married', '09171230010', 'Roman Catholic', 'HS - High School', 'Office Staff', 'PHP 8,000 - 13,000', '3 Ilang St.', 'Santo Domingo'),

        // REQUIRED: blank names remain blocking (one blank last name and one blank first name).
        mkRow('9100020', 'Head', '', 'Nena', 'Uy', '', '05-06-1981', 'Female', 'S - Single', '09171230020', 'Roman Catholic', 'HS - High School', 'Office Staff', 'PHP 8,000 - 13,000', '3 Ilang St.', 'Santo Domingo'),
        mkRow('9100021', 'Head', 'Mercado', '', 'Uy', '', '05-07-1981', 'Male', 'S - Single', '09171230021', 'Roman Catholic', 'HS - High School', 'Office Staff', 'PHP 8,000 - 13,000', '3 Ilang St.', 'Santo Domingo'),

        // ===== RED: bad QR cells (rest of the row is complete) =====================
        mkRow('',           'Head', 'Blanco',  'Nilo', 'Uy', '', '05-05-1985', 'Male',   'S - Single', '09171230011', 'Roman Catholic', 'HS - High School', 'Driver', 'PHP 8,000 - 13,000', '4 Narra St.', 'Malaban'),  // QR-01
        mkRow('ABC123',     'Head', 'Letras',  'Rico', 'Go', '', '05-06-1985', 'Male',   'S - Single', '09171230012', 'Roman Catholic', 'HS - High School', 'Driver', 'PHP 8,000 - 13,000', '5 Narra St.', 'Malaban'),  // QR-FORMAT
        mkRow('0',          'Head', 'Zeno',    'Zeny', 'Sy', '', '05-07-1985', 'Female', 'S - Single', '09171230013', 'Roman Catholic', 'HS - High School', 'Vendor', 'PHP 8,000 - 13,000', '6 Narra St.', 'Malaban'),  // QR-05
        mkRow('9999999999', 'Head', 'Grande',  'Maxi', 'Tan', '', '05-08-1985', 'Male',  'S - Single', '09171230014', 'Roman Catholic', 'HS - High School', 'Driver', 'PHP 8,000 - 13,000', '7 Narra St.', 'Malaban'), // QR-07
        mkRow('#REF!',      'Head', 'Erroro',  'Rene', 'Uy', '', '05-09-1985', 'Male',   'S - Single', '09171230015', 'Roman Catholic', 'HS - High School', 'Driver', 'PHP 8,000 - 13,000', '8 Narra St.', 'Malaban'),  // QR-08
        mkRow('=A4',        'Head', 'Formula', 'Fely', 'Go', '', '05-10-1985', 'Female', 'S - Single', '09171230016', 'Roman Catholic', 'HS - High School', 'Vendor', 'PHP 8,000 - 13,000', '9 Narra St.', 'Malaban'),  // QR-12

        // ===== YELLOW: field-level warnings (complete data) =======================
        // Five warnings on one row: BRGY (unofficial), CONTACT (short), SUFFIX
        // ("Junior" -> "Jr"), BDAY-RANGE (born 1850), and SECTOR (OTHER fallback).
        mkRow('9100009', 'Head', 'Ocampo', 'Ignacio', 'Reyes', 'Junior', '01-01-1850', 'Male', 'W - Widow / Widower', '12345', 'Roman Catholic', 'E - Elementary', 'Retired', 'Below PHP 8,000', '10 Kalachuchi St.', 'Barangay Wakanda', 'OTHER'),

        // ===== YELLOW: incomplete profile fields ================================
        // Each row has one blank profile field and otherwise valid head data.
        mkRow('9100022', 'Head', 'Ocampo', 'Nina', 'Reyes', '', '01-02-1980', 'Female', 'S - Single', '09171230022', 'Roman Catholic', 'HS - High School', 'Teacher', '', '10 Kalachuchi St.', 'Poblacion'),
        mkRow('9100023', 'Head', 'Ocampo', 'Nina', 'Cruz', '', '01-03-1980', 'Female', 'S - Single', '09171230023', 'Roman Catholic', 'HS - High School', '', 'PHP 18,001 - 25,000', '10 Kalachuchi St.', 'Poblacion'),
        mkRow('9100024', 'Head', 'Ocampo', 'Nina', 'Lim', '', '01-04-1980', 'Female', 'S - Single', '09171230024', 'Roman Catholic', '', 'Teacher', 'PHP 18,001 - 25,000', '10 Kalachuchi St.', 'Poblacion'),
        mkRow('9100025', 'Head', 'Ocampo', 'Nina', 'Diaz', '', '01-05-1980', 'Female', '', '09171230025', 'Roman Catholic', 'HS - High School', 'Teacher', 'PHP 18,001 - 25,000', '10 Kalachuchi St.', 'Poblacion'),
        mkRow('9100026', 'Head', 'Ocampo', 'Nina', 'Go', '', '01-06-1980', '', 'S - Single', '09171230026', 'Roman Catholic', 'HS - High School', 'Teacher', 'PHP 18,001 - 25,000', '10 Kalachuchi St.', 'Poblacion'),
        mkRow('9100027', 'Head', 'Ocampo', 'Nina', 'Sy', '', '', 'Female', 'S - Single', '09171230027', 'Roman Catholic', 'HS - High School', 'Teacher', 'PHP 18,001 - 25,000', '10 Kalachuchi St.', 'Poblacion'),

        // DUP-PERSON: the same child typed twice in one family (head is complete).
        mkRow('9100010', 'Head',  'Navarro', 'Luis', 'Cruz', '', '03-03-1975', 'Male',   'M - Married', '09171230017', 'Roman Catholic', 'HS - High School', 'Driver', 'PHP 13,001 - 18,000', '21 Molave St.', 'Langkiwa'),
        mkRow('9100010', 'Child', 'Navarro', 'Anna', 'Cruz', '', '08-08-2011', 'Female', 'S - Single', '', '', 'E - Elementary', 'Student', 'No regular income', '', ''),
        mkRow('9100010', 'Child', 'Navarro', 'Anna', 'Cruz', '', '08-08-2011', 'Female', 'S - Single', '', '', 'E - Elementary', 'Student', 'No regular income', '', ''),

        // QR-CONTIG: family 9100011's rows are split apart by family 9100012's row.
        mkRow('9100011', 'Head',  'Salazar', 'Efren', 'Go', '', '07-07-1968', 'Male',   'M - Married', '09171230018', 'Roman Catholic', 'HS - High School', 'Government Employee', 'PHP 13,001 - 18,000', '30 Mahogany St.', 'Soro-Soro'),
        mkRow('9100012', 'Head',  'Padilla', 'Grace', 'Yu', '', '10-10-1982', 'Female', 'S - Single', '09171230019', 'Roman Catholic', 'CG - College Graduate', 'Teacher', 'PHP 18,001 - 25,000', '40 Balete St.', 'Tubigan'),
        mkRow('9100011', 'Child', 'Salazar', 'Toni', 'Go', '', '09-09-2009', 'Female', 'S - Single', '', '', 'E - Elementary', 'Student', 'No regular income', '', ''),

        // QR-11 (merged QR cells): the two rows below get their A cells merged after writing.
        mkRow('9100013', 'Head',  'Gutierrez', 'Noel', 'Sy', '', '06-06-1971', 'Male', 'M - Married', '09171230020', 'Roman Catholic', 'HS - High School', 'Construction Worker', 'PHP 13,001 - 18,000', '50 Balete St.', 'Malamig'),
        mkRow('9100013', 'Child', 'Gutierrez', 'Rey', 'Sy', '', '04-04-2013', 'Male', 'S - Single', '', '', 'E - Elementary', 'Student', 'No regular income', '', ''),
    ];
}

/**
 * Seeds field-level errors into $count of an otherwise CLEAN row set, evenly spaced, rotating
 * through a fixed list of self-contained (non-DB) failures. So the file stays MOSTLY valid with a
 * known slice of errors — the "10k with ~1000 errors" case. QR (col A) and Relationship (col B)
 * are never touched, so family grouping stays intact and each seeded row trips exactly one code.
 * Call this AFTER fillOptional so the corrupt values are not overwritten.
 *
 * @param list<array> $rows
 * @return array{rows: list<array>, seeded: int}
 */
function corruptRows(array $rows, int $count): array
{
    $longName = str_repeat('Juanito', 16); // 112 chars > 100 -> LENGTH

    // [column index, bad value] — one cell each. Cols: 3=FirstName 5=Suffix 6=Birthday 7=Sex
    // 9=Contact 13=MonthlyIncome 15=Barangay 17=Services.
    $corruptions = [
        [7, 'Malee'],              // SEX invalid warning
        [6, '31-31-2000'],         // BDAY invalid date warning
        [6, '01-01-1850'],         // BDAY-RANGE (implausibly old) warning
        [13, 'plenty'],            // INCOME invalid warning
        [9, '12345'],              // CONTACT too short warning
        [15, 'Barangay Wakanda'],  // BRGY not an official barangay warning
        [5, 'Junior'],             // SUFFIX not a dropdown code warning
        [3, $longName],            // LENGTH (first name > 100 chars) blocker
        [17, 'ZZZ'],               // SERVICE unknown code warning
        [13, ''],                  // INCOMPLETE (blank profile field) warning
        [3, ''],                   // REQUIRED (blank required first name) blocker
    ];

    $total  = count($rows);
    $step   = max(1, intdiv($total, max(1, $count)));
    $seeded = 0;

    for ($i = 0; $i < $count; $i++) {
        $idx = $i * $step;
        if ($idx >= $total) {
            break;
        }
        [$col, $val]      = $corruptions[$i % count($corruptions)];
        $rows[$idx][$col] = $val;
        $seeded++;
    }

    return ['rows' => $rows, 'seeded' => $seeded];
}

/** Writes a row set onto a fresh template and saves it. Optionally merges the last two A cells. */
function writeWorkbook(array $rows, string $path, bool $mergeLastPairForQr11 = false): array
{
    $spreadsheet = (new FamilyExcelTemplate())->build();
    $sheet       = $spreadsheet->getSheetByName('Families') ?? $spreadsheet->getSheet(0);

    $headerRow = null;
    for ($r = 1; $r <= 12; $r++) {
        if (stripos(trim((string) $sheet->getCell('A' . $r)->getValue()), 'QR Number') === 0) {
            $headerRow = $r;
            break;
        }
    }
    if ($headerRow === null) {
        fwrite(STDERR, "Could not find the 'QR Number' header in the template.\n");
        exit(1);
    }

    $r = $headerRow + 1;
    foreach ($rows as $row) {
        foreach ($row as $i => $value) {
            $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1) . $r, (string) $value, DataType::TYPE_STRING);
        }
        $r++;
    }

    if ($mergeLastPairForQr11) {
        $sheet->mergeCells('A' . ($r - 2) . ':A' . ($r - 1));
    }

    (new Xlsx($spreadsheet))->save($path);

    return ['rows' => count($rows), 'first' => $headerRow + 1, 'last' => $r - 1];
}

// -- build ---------------------------------------------------------------------
// Which files to build. No arg = all. 'small' = A/B/E only. 'C' = 10k clean only.
// 'D' = 10k with seeded errors only. The 10k files are slow to write, so building one
// at a time keeps a quick edit/test loop.
$only = strtolower(trim($argv[1] ?? ''));
$want = static fn (string $name): bool => $only === '' || $only === $name;

$outDir = $root . DIRECTORY_SEPARATOR . 'excel';
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}

if ($want('small')) {
    $genA  = generateClean(6, 89, 1950, 0);                             // ref families are QR 1-5, so gen starts at 6
    $fileA = fillOptional(array_merge(referenceRows(), $genA['rows'])); // 11 + 89 = 100 people, QR from 1
    $genB  = generateClean($genA['nextQr'], 100, 1965, 9);             // continues after A (no QR collision)
    $fileB = fillOptional($genB['rows']);
    $fileE = fillOptional(errorRows());

    $outA = $outDir . DIRECTORY_SEPARATOR . 'family-import-100A.xlsx';
    $outB = $outDir . DIRECTORY_SEPARATOR . 'family-import-100B.xlsx';
    $outE = $outDir . DIRECTORY_SEPARATOR . 'family-import-ALL-ERRORS.xlsx';

    $a = writeWorkbook($fileA, $outA);
    $b = writeWorkbook($fileB, $outB);
    $e = writeWorkbook($fileE, $outE, true);

    echo 'Wrote ' . $outA . ' — ' . $a['rows'] . " people (rows {$a['first']}-{$a['last']})\n";
    echo 'Wrote ' . $outB . ' — ' . $b['rows'] . " people (rows {$b['first']}-{$b['last']})\n";
    echo 'Wrote ' . $outE . ' — ' . $e['rows'] . " rows (rows {$e['first']}-{$e['last']})\n";
    echo "Import 100A first (creates reference families QR 1-5), then ALL-ERRORS.\n";
}

// Bulk load-test files. C: 10,000 clean, valid people, QR continuing at 5741 (right after the
// 20k members file, whose QRs top out at 5740) so the two import as one contiguous sequence.
// D: same size but ~800 rows carry a seeded field-level error (rest valid). QR continues at 9741,
// right after C's 4000 families end at 9740, so 20k -> C -> D import as one contiguous sequence.
if ($want('c')) {
    $genC  = generateClean(5741, 10000, 1970, 3);
    $fileC = fillOptional($genC['rows']);
    $outC  = $outDir . DIRECTORY_SEPARATOR . 'family-import-C-10k-clean.xlsx';
    $c     = writeWorkbook($fileC, $outC);
    echo 'Wrote ' . $outC . ' — ' . $c['rows'] . " people, all clean (rows {$c['first']}-{$c['last']})\n";
}

if ($want('d')) {
    $dErrorCount = 800;
    $genD  = generateClean(9741, 10000, 1975, 7);
    $corrD = corruptRows(fillOptional($genD['rows']), $dErrorCount);
    $outD  = $outDir . DIRECTORY_SEPARATOR . 'family-import-D-10k-errors.xlsx';
    $d     = writeWorkbook($corrD['rows'], $outD);
    echo 'Wrote ' . $outD . ' — ' . $d['rows'] . ' people, ' . $corrD['seeded'] . " with a seeded error (rows {$d['first']}-{$d['last']})\n";
}
