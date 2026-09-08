<?php

/**
 * Generates family-import-DEMO-validations.xlsx — a small file that trips EVERY validation
 * the family importer has, one or two families per code. Built on the real
 * FamilyExcelTemplate, so the headers, dropdowns and Reference sheet are genuine.
 *
 *   php tools/make-import-demo.php
 *
 * The DB-aware codes (QR-TAKEN, DUP-EXISTS, DUP-DIFF, DUP-DB, ADD-MEMBER) are keyed to
 * families that must EXIST in the database — QR 1-5 in the current accesscard dump:
 *   QR 1 = Antonio Ang Santos,        1994-01-16, 844 P. Burgos St., Casile
 *   QR 2 = Ronald Sy Andrada,         1983-09-28, 675 Narra St., Loma
 *   QR 3 = Rafael M. Ramos,           1959-04-28, 59 Magsaysay St., Malamig
 *   QR 4 = Ramon Tan Espino,          1999-03-20, 713 Aguinaldo Ave., Canlalay
 *   QR 5 = Michelle P. Buenaventura,  1954-09-22, 949 Magsaysay St., Canlalay
 * If those change, update the rows below and re-run. New QRs use 90000xx (the highest QR
 * on file is 5740, so they are free).
 *
 * See docs/12-import.md for what every code means and which row demonstrates it.
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

// A=QR B=Relationship C=LastName D=FirstName E=MiddleName F=Suffix G=Birthday H=Sex
// I=CivilStatus J=ContactNumber K=Religion L=Education M=Job N=MonthlyIncome
// O=Address P=Barangay Q=Sector R=Services
$rows = [
    // -- WARNING: already in the system ---------------------------------------
    // DUP-EXISTS on its own: an EXACT re-upload of QR 1. Deliberately raises no DUP-DIFF —
    // proof that a clean re-upload does not cry wolf.
    ['1', 'Head', 'Santos', 'Antonio', 'Ang', '', '01-16-1994', 'Male', 'N', '09732227425', 'Roman Catholic', 'UG', 'Private Employee', '65000', '844 P. Burgos St.', 'Casile', '', ''],

    // DUP-EXISTS + DUP-DIFF: same head on QR 4, but the file carries a NEW phone number.
    // The family is skipped, so that edit would be silently lost.
    ['4', 'Head', 'Espino', 'Ramon', 'Tan', '', '03-20-1999', 'Male', 'M', '09171112222', 'Roman Catholic', 'UG', 'Self-employed', '40000', '713 Aguinaldo Ave.', 'Canlalay', '', ''],

    // -- BLOCKING: the mistyped QR ---------------------------------------------
    // QR-TAKEN: QR 2 is Ronald Andrada's, but this row is a different person.
    ['2', 'Head', 'Reyes', 'Carmela', 'Lim', '', '03-09-1990', 'Female', 'S', '09171234567', 'Roman Catholic', 'HS', 'Vendor', '9000', '12 Mabini St.', 'Poblacion', '', ''],

    // QR-TAKEN: QR 3 is Rafael Ramos's — right name, WRONG birthday (1960 vs 1959).
    ['3', 'Head', 'Ramos', 'Rafael', 'Malabanan', '', '04-28-1960', 'Male', 'W', '09129375782', 'Roman Catholic', 'HS', 'Retired', '8000', '59 Magsaysay St.', 'Malamig', '', ''],

    // -- WARNING: ADD-MEMBER + INCOMPLETE ---------------------------------------
    // No Head row, and QR 5 already belongs to Michelle Buenaventura: the forgotten-member
    // case. This person is ADDED to family 5 on import. Because the child's civil status,
    // education, job and income are blank, the row also raises INCOMPLETE warnings.
    ['5', 'Child', 'Buenaventura', 'Nico', 'Panganiban', '', '07-11-2008', 'Male', '', '', '', '', '', '', '', '', '', ''],

    // -- WARNING: DUP-DB (the silent skip) --------------------------------------
    // Ronald Andrada is already the head of QR 2. Re-entered here under a BRAND-NEW QR, so
    // nothing about the QR looks wrong — but the import would skip this whole family.
    ['9000001', 'Head', 'Andrada', 'Ronald', 'Sy', '', '09-28-1983', 'Male', 'M', '09979950257', 'Iglesia ni Cristo', 'UG', 'Construction Worker', '18000', '675 Narra St.', 'Loma', '', ''],
    ['9000001', 'Child', 'Andrada', 'Liza', 'Sy', '', '02-14-2012', 'Female', '', '', '', '', '', '', '', '', '', ''],

    // -- BLOCKING: family structure ---------------------------------------------
    // HEAD-NONE: new QR, members only, nobody marked Head. The reviewer names the person
    // who most likely IS the head — the one carrying the address.
    ['9000002', 'Spouse', 'Dela Cruz', 'Rosa', 'Cruz', '', '06-02-1978', 'Female', 'M', '', '', '', '', '', '88 Rizal Ave.', 'Poblacion', '', ''],
    ['9000002', 'Child', 'Dela Cruz', 'Mark', 'Cruz', '', '01-30-2010', 'Male', '', '', '', '', '', '', '', '', '', ''],

    // HEAD-MULTI: two Head rows under one QR.
    ['9000003', 'Head', 'Torres', 'Ben', 'Uy', '', '11-05-1970', 'Male', 'M', '09171230000', 'Roman Catholic', 'HS', 'Driver', '15000', '5 Bonifacio St.', 'Malaban', '', ''],
    ['9000003', 'Head', 'Torres', 'Cora', 'Uy', '', '12-08-1972', 'Female', 'M', '', 'Roman Catholic', 'HS', 'Vendor', '15000', '5 Bonifacio St.', 'Malaban', '', ''],

    // FP-ADDR: one QR, but the rows carry two different households (different address AND
    // barangay) — the copied-block / shifted-row signature. Only ONE Head, so this shows
    // FP-ADDR on its own rather than tangled up with HEAD-MULTI.
    ['9000004', 'Head', 'Aquino', 'Pedro', 'Roque', '', '04-04-1965', 'Male', 'M', '09181234567', 'Roman Catholic', 'E', 'Farmer', '7000', '1 Acacia St.', 'Timbao', '', ''],
    ['9000004', 'Spouse', 'Bautista', 'Elena', 'Diaz', '', '09-09-1969', 'Female', 'W', '09182223333', 'Roman Catholic', 'E', 'Vendor', '7000', '99 Ipil St.', 'De La Paz', '', ''],

    // -- BLOCKING + WARNING: field-level errors ----------------------------------
    // Five separate field warnings on ONE row: INCOMPLETE (blank Job on a Head), BDAY (an
    // impossible date), SEX ("Malee"), INCOME ("plenty"), SERVICE (code not on the sheet).
    // Under the relaxed contract blank member fields warn and import NULL; only first and
    // last names are blocking REQUIRED.
    ['9000005', 'Head', 'Villanueva', 'Andres', 'Lopez', '', '31-31-2000', 'Malee', 'M', '09171234000', 'Roman Catholic', 'HS', '', 'plenty', '77 Sampaguita St.', 'Zapote', '', 'ZZZ'],

    // REQUIRED: a blank last name is the only remaining field-level blocker.
    ['9000012', 'Head', '', 'NoLastname', '', '', '01-01-1990', 'Male', 'S', '', 'Roman Catholic', 'HS', 'Driver', '12000', '100 Test St.', 'Poblacion', '', ''],

    // LENGTH: a first name past the 100-character column limit.
    ['9000006', 'Head', 'Mercado', 'Juanitolongfirstnamethatgoesonandonandonandonandonandonandonandonandonandonandonandonandonandonandonandon', '', '', '02-02-1980', 'Male', 'S', '09171235555', 'Roman Catholic', 'HS', 'Janitor', '12000', '3 Ilang-Ilang St.', 'Sto. Domingo', '', ''],

    // -- BLOCKING: bad QR cells ---------------------------------------------------
    ['',           'Head', 'Blanco',  'Nilo', '', '', '05-05-1985', 'Male',   'S', '', 'Roman Catholic', 'HS', 'Driver', '12000', '4 Narra St.', 'Malaban', '', ''],  // QR-01 blank
    ['ABC123',     'Head', 'Letras',  'Rico', '', '', '05-06-1985', 'Male',   'S', '', 'Roman Catholic', 'HS', 'Driver', '12000', '5 Narra St.', 'Malaban', '', ''],  // QR-FORMAT
    ['0',          'Head', 'Zero',    'Zeno', '', '', '05-07-1985', 'Male',   'S', '', 'Roman Catholic', 'HS', 'Driver', '12000', '6 Narra St.', 'Malaban', '', ''],  // QR-05
    // QR-07 needs a well-formed number ABOVE the 2,147,483,647 ceiling. It must be <= 10
    // digits, or the format regex rejects it as QR-FORMAT first.
    ['9999999999', 'Head', 'Grande',  'Maxi', '', '', '05-08-1985', 'Male',   'S', '', 'Roman Catholic', 'HS', 'Driver', '12000', '7 Narra St.', 'Malaban', '', ''],  // QR-07
    ['#REF!',      'Head', 'Erroro',  'Rene', '', '', '05-09-1985', 'Male',   'S', '', 'Roman Catholic', 'HS', 'Driver', '12000', '8 Narra St.', 'Malaban', '', ''],  // QR-08
    ['=A4',        'Head', 'Formula', 'Fely', '', '', '05-10-1985', 'Female', 'S', '', 'Roman Catholic', 'HS', 'Vendor', '12000', '9 Narra St.', 'Malaban', '', ''],  // QR-12

    // -- WARNINGS: field-level -----------------------------------------------------
    // Four warnings on ONE row: BRGY ("Barangay Wakanda"), CONTACT (a 5-digit number),
    // SUFFIX ("Junior" is remapped to "Jr"), BDAY-RANGE (born 1850 = over 150 years old).
    ['9000007', 'Head', 'Ocampo', 'Ignacio', 'Reyes', 'Junior', '01-01-1850', 'Male', 'W', '12345', 'Roman Catholic', 'E', 'Retired', '5000', '10 Kalachuchi St.', 'Barangay Wakanda', '', ''],

    // -- WARNING: DUP-PERSON --------------------------------------------------------
    // The same child typed twice in one family: same name + birthday, same household.
    ['9000008', 'Head',  'Navarro', 'Luis', 'Cruz', '', '03-03-1975', 'Male',   'M', '09171236666', 'Roman Catholic', 'HS', 'Driver', '15000', '21 Molave St.', 'Langkiwa', '', ''],
    ['9000008', 'Child', 'Navarro', 'Anna', 'Cruz', '', '08-08-2011', 'Female', '',  '', '', '', '', '', '', '', '', ''],
    ['9000008', 'Child', 'Navarro', 'Anna', 'Cruz', '', '08-08-2011', 'Female', '',  '', '', '', '', '', '', '', '', ''],

    // -- WARNING: QR-CONTIG ----------------------------------------------------------
    // Family 9000009's rows are split apart by family 9000010's row.
    ['9000009', 'Head',  'Salazar', 'Efren', 'Go', '', '07-07-1968', 'Male',   'M', '09171237777', 'Roman Catholic', 'HS', 'Mechanic', '18000', '30 Mahogany St.', 'Soro-Soro', '', ''],
    ['9000010', 'Head',  'Padilla', 'Grace', 'Yu', '', '10-10-1982', 'Female', 'S', '09171238888', 'Roman Catholic', 'CG', 'Teacher', '25000', '40 Kamagong St.', 'Tubigan', '', ''],
    ['9000009', 'Child', 'Salazar', 'Toni',  'Go', '', '09-09-2009', 'Female', '',  '', '', '', '', '', '', '', '', ''],

    // -- BLOCKING: QR-11 (merged QR cells) ---------------------------------------------
    // The QR cells of these two rows are MERGED after writing (see below) — exactly the
    // mistake the check exists for. Row 2 then has no QR of its own (QR-01), which is why.
    ['9000011', 'Head',  'Gutierrez', 'Noel', 'Sy', '', '06-06-1971', 'Male', 'M', '09171239999', 'Roman Catholic', 'HS', 'Welder', '16000', '50 Balete St.', 'Malamig', '', ''],
    ['9000011', 'Child', 'Gutierrez', 'Rey',  'Sy', '', '04-04-2013', 'Male', '',  '', '', '', '', '', '', '', '', ''],
];

$spreadsheet = (new FamilyExcelTemplate())->build();
$sheet       = $spreadsheet->getSheetByName('Families') ?? $spreadsheet->getSheet(0);

// Required headers carry a " *" marker, so match on the prefix.
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
        $sheet->setCellValueExplicit(
            Coordinate::stringFromColumnIndex($i + 1) . $r,
            (string) $value,
            DataType::TYPE_STRING,
        );
    }

    $r++;
}

// The QR-11 demo: merge the QR cells of the last family (the two rows just written).
$sheet->mergeCells('A' . ($r - 2) . ':A' . ($r - 1));

$outDir = $root . DIRECTORY_SEPARATOR . 'excel';
if (! is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$out = $outDir . DIRECTORY_SEPARATOR . 'family-import-DEMO-validations.xlsx';
(new Xlsx($spreadsheet))->save($out);

echo 'Wrote ' . $out . "\n";
echo 'Data rows: ' . count($rows) . ' (sheet rows ' . ($headerRow + 1) . '-' . ($r - 1) . ")\n";
