<?php

namespace Tests\Unit;

use App\Libraries\FamilyExcelImporter;
use App\Libraries\ImportLookupCache;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionClass;

/**
 * Unit coverage for the hardened QR validation and the family-coherence checks.
 * The lookup caches (sector/service/income) are primed to empty
 * arrays via reflection so validateAndBuild runs without a database.
 *
 * @internal
 */
final class FamilyExcelImporterTest extends CIUnitTestCase
{
    // -- validateQr: the acceptance table (spec section 7) ---------------------

    public function testValidateQrRejectsMalformedValues(): void
    {
        $importer = new FamilyExcelImporter();

        $rejects = [
            ''               => 'QR-01',
            'ABC123'         => 'QR-FORMAT',
            'QR-6001'        => 'QR-FORMAT',
            'NULL'           => 'QR-FORMAT',
            'TBD'            => 'QR-FORMAT',
            'N/A'            => 'QR-FORMAT',
            '-1'             => 'QR-FORMAT',
            '-57'            => 'QR-FORMAT',
            '0'              => 'QR-05',
            '5880.5'         => 'QR-FORMAT',
            '5880.0'         => 'QR-FORMAT', // integral-float TEXT keeps the dot -> rejected
            '6,001'          => 'QR-FORMAT',
            '1.23457E+11'    => 'QR-FORMAT',
            '#REF!'          => 'QR-08',
            '=A4'            => 'QR-12',
            '3000000000'     => 'QR-07',     // 10 digits, over the 2147483647 ceiling
            '999999999999'   => 'QR-FORMAT', // 12 digits, over the 10-digit format cap
        ];

        foreach ($rejects as $raw => $expectedCode) {
            $result = $importer->validateQr($raw);
            $this->assertFalse($result['ok'], "expected '{$raw}' to be rejected");
            $this->assertSame($expectedCode, $result['code'], "wrong code for '{$raw}'");
        }
    }

    public function testValidateQrNormalisesAndAcceptsGoodValues(): void
    {
        $importer = new FamilyExcelImporter();

        $accepts = [
            '  6001  '        => 6001,   // padded
            '6001'            => 6001,   // text-formatted number
            "6001\u{00A0}"    => 6001,   // trailing non-breaking space
            "\u{200B}6001"    => 6001,   // leading zero-width space
            '006001'          => 6001,   // leading zeros -> accepted (logged)
            '1'               => 1,
            '2147483647'      => 2147483647, // exactly the ceiling
        ];

        foreach ($accepts as $raw => $expected) {
            $result = $importer->validateQr($raw);
            $this->assertTrue($result['ok'], "expected '{$raw}' to be accepted");
            $this->assertSame($expected, $result['qr'], "wrong value for '{$raw}'");
        }
    }

    // -- family coherence ------------------------------------------------------

    public function testCleanSingleHeadFamilyBuildsWithNoBlockingErrors(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['contactnumber' => '09171234567']),
            $this->memberRow(4, '6001'),
        ]);

        $this->assertSame(1, $result['counts']['families']);
        $this->assertSame(1, $result['counts']['members']);
        $this->assertSame(0, $result['counts']['blocking']);
    }

    public function testBlankOptionalProfileFieldsReceiveDefaultsWithoutWarnings(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', [
                'civilstatus' => '', 'education' => '', 'job' => '', 'religion' => '', 'monthlyincome' => '',
                'contactnumber' => '09171234567',
            ]),
            $this->memberRow(4, '6001', [
                'birthday' => '', 'sex' => '', 'civilstatus' => '',
                'education' => '', 'job' => '', 'religion' => '', 'monthlyincome' => '',
            ]),
        ]);

        foreach ([$result['families'][0]['headPayload'], $result['families'][0]['memberPayloads'][0]['payload']] as $payload) {
            $this->assertSame('NOT PROVIDED', $payload['civilstatus']);
            $this->assertSame('NOT PROVIDED', $payload['education']);
            $this->assertSame('NOT PROVIDED', $payload['job']);
            $this->assertSame('NOT PROVIDED', $payload['religion']);
            $this->assertSame(0.0, $payload['salary']);
        }

        $this->assertNotContains('INCOMPLETE', $this->codes($result));
    }

    public function testHeadBlankAddressAndBarangayWarnButStillImport(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['address' => '', 'barangay' => '']),
        ]);

        $this->assertSame(0, $result['counts']['blocking']);
        $incomplete = $this->errorsFor($result, 'INCOMPLETE');
        $this->assertCount(3, $incomplete);
        $this->assertSame(['address', 'barangay', 'contactnumber'], array_column($incomplete, 'field'));
    }

    public function testBlankHeadCardFieldsWarnWhileBlankMemberCardFieldsAreQuiet(): void
    {
        $head = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', [
                'sex' => '', 'birthday' => '', 'address' => '', 'barangay' => '', 'contactnumber' => '',
            ]),
        ]);

        $this->assertSame(['birthday', 'sex', 'address', 'barangay', 'contactnumber'], array_column(
            $this->errorsFor($head, 'INCOMPLETE'),
            'field'
        ));
        $this->assertSame(0, $head['counts']['blocking']);

        $member = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['contactnumber' => '09171234567']),
            $this->memberRow(4, '6001', [
                'sex' => '', 'birthday' => '', 'address' => '', 'barangay' => '', 'contactnumber' => '',
            ]),
        ]);

        $this->assertNotContains('INCOMPLETE', $this->codes($member));
    }

    public function testClearingMalformedCardValuesChangesOnlyHeadToWarning(): void
    {
        $head = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['contactnumber' => '0917-12']),
        ]);
        $this->assertSame('blocking', $this->errorsFor($head, 'CONTACT')[0]['severity']);

        $clearedHead = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['contactnumber' => '']),
        ]);
        $this->assertSame(['contactnumber'], array_column($this->errorsFor($clearedHead, 'INCOMPLETE'), 'field'));

        $clearedMember = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['contactnumber' => '09171234567']),
            $this->memberRow(4, '6001', ['contactnumber' => '']),
        ]);
        $this->assertNotContains('INCOMPLETE', $this->codes($clearedMember));
    }

    public function testBlankRelationshipOnAMemberImportsAsMember(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['contactnumber' => '09171234567']),
            $this->memberRow(4, '6001', ['relationship' => '']),
        ]);

        $this->assertSame(0, $result['counts']['blocking']);
        $this->assertNotContains('INCOMPLETE', $this->codes($result));
        // The stored payload keeps the MEMBER default.
        $this->assertSame('MEMBER', $result['families'][0]['memberPayloads'][0]['payload']['relationship']);
    }

    public function testBlankNamesRemainBlocking(): void
    {
        // Identity fields the database refuses (NOT NULL) stay blocking.
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->memberRow(4, '6001', ['firstname' => '']),
        ]);

        $required = $this->errorsFor($result, 'REQUIRED');
        $this->assertCount(1, $required);
        $this->assertSame('blocking', $required[0]['severity']);
        $this->assertSame(1, $result['counts']['blocking']);
    }

    public function testMemberBlankAddressAndBarangayStayAllowed(): void
    {
        // Address/Barangay remain head-only - a member leaving them blank (inherited) is fine.
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->memberRow(4, '6001', ['address' => '', 'barangay' => '']),
        ]);

        $this->assertSame(0, $result['counts']['blocking']);
    }

    public function testMemberAddressAndBarangayAreIgnored(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['address' => '1 A Street', 'barangay' => 'Canlalay']),
            $this->memberRow(4, '6001', ['address' => '#', 'barangay' => 'Santa Rosa']),
        ]);

        $member = $result['families'][0]['memberPayloads'][0]['payload'];
        $this->assertSame('1 A STREET', $member['address']);
        $this->assertNotContains('FP-ADDR', $this->codes($result));
        $this->assertSame([], array_values(array_filter($result['errors'], static fn (array $error): bool =>
            (int) $error['sheetRow'] === 4
            && in_array($error['field'], ['address', 'barangay'], true)
        )));
    }

    public function testMalformedHeadAddressBlocksInsteadOfBeingInvented(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['address' => '#']),
        ]);

        $this->assertSame('blocking', $this->errorsFor($result, 'ADDRESS')[0]['severity']);
    }

    public function testHeadNoneAndHeadMultiAreFlagged(): void
    {
        $none = $this->importer()->validateAndBuild([
            $this->memberRow(3, '6001'),
            $this->memberRow(4, '6001'),
        ]);
        $this->assertContains('HEAD-NONE', $this->codes($none));

        $multi = $this->importer()->validateAndBuild([
            $this->headRow(3, '6002'),
            $this->headRow(4, '6002'),
        ]);
        $this->assertContains('HEAD-MULTI', $this->codes($multi));
    }

    public function testEveryRowIsValidatedEvenWhenTheFamilyHasNoHead(): void
    {
        // The spec's secondary bug: field validation was skipped for rows whose family
        // already failed a family-level check. Here a headless family also has a member
        // missing a first name - BOTH errors must surface.
        $result = $this->importer()->validateAndBuild([
            $this->memberRow(3, '6001', ['firstname' => '']),
            $this->memberRow(4, '6001'),
        ]);

        $codes = $this->codes($result);
        $this->assertContains('HEAD-NONE', $codes);
        $this->assertContains('REQUIRED', $codes);
    }

    public function testBlankGapBetweenFamilyRowsIsNotAFlag(): void
    {
        // Rows 3 and 5 with nothing at row 4: the blank row was skipped at read
        // time, so the family IS together as far as the file is concerned. The old
        // raw row-number span flagged exactly this, 1,503 times on the real file.
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->memberRow(5, '6001'),
        ]);

        $this->assertNotContains('QR-CONTIG', $this->codes($result));
    }

    public function testAnotherFamilyInterleavedStillWarns(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->headRow(4, '6002'),
            $this->memberRow(5, '6001'),
        ]);

        $contig = array_values(array_filter(
            $result['errors'],
            static fn (array $e): bool => $e['code'] === 'QR-CONTIG'
        ));

        $this->assertCount(1, $contig);
        $this->assertSame('warning', $contig[0]['severity']);
        $this->assertSame('6001', (string) $contig[0]['familyNo']);
    }

    // -- barangay / contact / suffix / duplicate-person ----------------------

    public function testBarangayToleratesSpellingButBlocksNonBarangays(): void
    {
        // "Biñan" and "Sto. Tomas" are legitimate spellings of official barangays.
        $ok = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['barangay' => 'Biñan']),
            $this->headRow(4, '6002', ['barangay' => 'Sto. Tomas']),
        ]);
        $this->assertNotContains('BRGY', $this->codes($ok));

        // "Santa Rosa" is another city and cannot safely be written as a barangay.
        $bad = $this->importer()->validateAndBuild([
            $this->headRow(3, '6003', ['barangay' => 'Santa Rosa']),
        ]);
        $brgy = array_values(array_filter($bad['errors'], static fn (array $e): bool => $e['code'] === 'BRGY'));
        $this->assertCount(1, $brgy);
        $this->assertSame('blocking', $brgy[0]['severity']);
        $this->assertSame(1, $bad['counts']['blocking']);
        $this->assertNull($bad['families'][0]['headPayload']['barangayID']);
    }

    public function testContactNumberStoresCanonicalDigitsAndBlocksMalformedValues(): void
    {
        $good = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['contactnumber' => '0917-123-4567']), // punctuation ok
        ]);
        $this->assertNotContains('CONTACT', $this->codes($good));
        $this->assertSame('09171234567', $good['families'][0]['headPayload']['contactnumber']);

        foreach (['0917', '+639171234567', '09171234567890', '99171234567'] as $bad) {
            $result = $this->importer()->validateAndBuild([
                $this->headRow(3, '6001', ['contactnumber' => $bad]),
            ]);
            $this->assertContains('CONTACT', $this->codes($result), "expected '{$bad}' to block");
            $this->assertSame('blocking', $this->errorsFor($result, 'CONTACT')[0]['severity']);
        }
    }

    public function testMalformedMemberContactAlsoBlocks(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['contactnumber' => '09171234567']),
            $this->memberRow(4, '6001', ['contactnumber' => '0917-12']),
        ]);

        $this->assertSame('blocking', $this->errorsFor($result, 'CONTACT')[0]['severity']);
    }

    public function testCanonicalRowsStageCleanedValues(): void
    {
        $rows = (new FamilyExcelImporter())->normalizeRows([[
            'sheetRow' => 3,
            'data' => [
                'familyno' => '0001', 'relationship' => 'Head',
                'firstname' => 'Maria  Jose', 'middlename' => 'De  La',
                'lastname' => 'Santos', 'suffix' => 'Jr.',
                'address' => 'Purok  1,', 'barangay' => 'Canlalay',
                'sector' => 'sc, iw', 'services' => 'eda 8, eda9',
            ],
        ]]);

        $this->assertSame('MARIA JOSE', $rows[0]['data']['firstname']);
        $this->assertSame('DE LA', $rows[0]['data']['middlename']);
        $this->assertSame('SANTOS', $rows[0]['data']['lastname']);
        $this->assertSame('JR', $rows[0]['data']['suffix']);
        $this->assertSame('PUROK 1,', $rows[0]['data']['address']);
        $this->assertSame('CANLALAY', $rows[0]['data']['barangay']);
        $this->assertSame('SC,IW', $rows[0]['data']['sector']);
        $this->assertSame('EDA8,EDA9', $rows[0]['data']['services']);
    }

    public function testCanonicalRowsPreserveDistinctPunctuationAndUnsplitCodes(): void
    {
        $rows = (new FamilyExcelImporter())->normalizeRows([
            ['sheetRow' => 3, 'data' => ['services' => 'EDA8 EDA9', 'address' => 'Purok 1.']],
            ['sheetRow' => 4, 'data' => ['address' => 'Purok 1']],
        ]);

        $this->assertSame('EDA8EDA9', $rows[0]['data']['services']);
        $this->assertSame('PUROK 1.', $rows[0]['data']['address']);
        $this->assertSame('PUROK 1', $rows[1]['data']['address']);
        $this->assertNotSame($rows[0]['data']['address'], $rows[1]['data']['address']);

        $semicolon = (new FamilyExcelImporter())->normalizeRows([
            ['sheetRow' => 5, 'data' => ['address' => 'Purok 1;']],
            ['sheetRow' => 6, 'data' => ['address' => 'Purok 1']],
        ]);

        $this->assertSame('PUROK 1;', $semicolon[0]['data']['address']);
        $this->assertSame('PUROK 1', $semicolon[1]['data']['address']);
        $this->assertNotSame($semicolon[0]['data']['address'], $semicolon[1]['data']['address']);
    }

    public function testPayloadStoresStagedAddressVerbatim(): void
    {
        // Canonical staging preserves punctuation ("Purok  1;" -> "PUROK 1;"), so the
        // review screen shows "PUROK 1;". The payload the write step stores must be
        // that same staged value verbatim - a second cleaning pass here strips the
        // punctuation the reviewer already saw and approved ("PUROK 1").
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['address' => 'Purok  1;']),
        ]);

        $this->assertSame('PUROK 1;', $result['families'][0]['headPayload']['address']);
    }

    public function testPayloadStoresStagedCommaAddressVerbatim(): void
    {
        // The comma case from the review spec: staged "PUROK 1," must store as-is.
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['address' => 'Purok  1,']),
        ]);

        $this->assertSame('PUROK 1,', $result['families'][0]['headPayload']['address']);
    }

    public function testCanonicalRowsMapSuffixAliasesToEnumValues(): void
    {
        $rows = (new FamilyExcelImporter())->normalizeRows([
            ['sheetRow' => 3, 'data' => ['suffix' => 'Junior']],
            ['sheetRow' => 4, 'data' => ['suffix' => 'Senior']],
        ]);

        $this->assertSame('JR', $rows[0]['data']['suffix']);
        $this->assertSame('SR', $rows[1]['data']['suffix']);
    }

    public function testSuffixNormalisesDotSilently(): void
    {
        // "Jr." is just a trailing dot - accepted silently, stored as "JR".
        $ok = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['suffix' => 'Jr.']),
        ]);
        $this->assertNotContains('SUFFIX', $this->codes($ok));
        $this->assertSame('JR', $ok['families'][0]['headPayload']['suffix']);
    }

    public function testSuffixMapsVariantsToDropdownValue(): void
    {
        // Staging has already coerced aliases to enum-valid dropdown values.
        $map = ['the 3rd' => 'III', 'Junior' => 'JR', '2nd' => 'II'];

        foreach ($map as $typed => $expected) {
            $result = $this->importer()->validateAndBuild([
                $this->headRow(3, '6001', ['suffix' => $typed]),
            ]);
            $this->assertNotContains('SUFFIX', $this->codes($result), "expected '{$typed}' to stage silently");
            $this->assertSame($expected, $result['families'][0]['headPayload']['suffix'], "'{$typed}' should map to {$expected}");
        }
    }

    public function testUnmappableSuffixIsLeftBlank(): void
    {
        // Genuine junk maps to nothing - left blank (enum-safe) with a warning.
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['suffix' => 'Bogus']),
        ]);
        $this->assertContains('SUFFIX', $this->codes($result));
        $this->assertNull($result['families'][0]['headPayload']['suffix']);
    }

    public function testInvalidSexBlocksAndDoesNotInventAValue(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['sex' => 'Xyz']),
        ]);

        $this->assertSame(1, $result['counts']['blocking']);
        $sex = $this->errorsFor($result, 'SEX');
        $this->assertCount(1, $sex);
        $this->assertSame('blocking', $sex[0]['severity']);
        $this->assertStringContainsString('XYZ', $sex[0]['message']);
        $this->assertNull($result['families'][0]['headPayload']['sex']);
    }

    public function testUnreadableIncomeWarnsAndImportsBlank(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['monthlyincome' => 'MINIMUM WAGE']),
        ]);

        $this->assertSame(0, $result['counts']['blocking']);
        $income = $this->errorsFor($result, 'INCOME');
        $this->assertCount(1, $income);
        $this->assertSame('warning', $income[0]['severity']);
        $this->assertStringContainsString('MINIMUM WAGE', $income[0]['message']);
        $this->assertNull($result['families'][0]['headPayload']['salary']);
    }

    public function testIncomeAmountsParseAcrossCurrencyMarkersAndSeparators(): void
    {
        // The variants measured in the real Cluster1 file. A bracket label still
        // wins (matched first, case-insensitively); these are the free-text amounts.
        $amounts = [
            'P3000' => '3000', 'P5,000' => '5000', 'PHP15,000' => '15000',
            'Php 15,000' => '15000', '₱5,000' => '5000', '$1, 500' => '1500',
            '10, 000' => '10000', '3000' => '3000', '14,000' => '14000',
        ];

        foreach ($amounts as $typed => $stored) {
            $result = $this->importer()->validateAndBuild([
                $this->headRow(3, '6001', ['monthlyincome' => $typed]),
            ]);

            $this->assertSame(0, $result['counts']['blocking'], "'{$typed}' must not block");
            $this->assertNotContains('INCOME', $this->codes($result), "'{$typed}' must parse");
            $this->assertSame($stored, (string) $result['families'][0]['headPayload']['salary'],
                "'{$typed}' should store {$stored}");
        }
    }

    public function testIncomeGibberishStillWarns(): void
    {
        foreach (['6K', 'SSS Pension - 14, 0000', 'Allotment 30, 000', 'below PHP 8,0003428'] as $typed) {
            $result = $this->importer()->validateAndBuild([
                $this->headRow(3, '6001', ['monthlyincome' => $typed]),
            ]);

            $this->assertContains('INCOME', $this->codes($result), "'{$typed}' should warn");
            $this->assertSame(0, $result['counts']['blocking']);
        }
    }

    public function testUnparseableBirthdayBlocksAndDoesNotInventAValue(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['birthday' => '03-07']),
        ]);

        $this->assertSame(1, $result['counts']['blocking']);
        $bday = $this->errorsFor($result, 'BDAY');
        $this->assertCount(1, $bday);
        $this->assertSame('blocking', $bday[0]['severity']);
        $this->assertStringContainsString('03-07', $bday[0]['message']);
        $this->assertNull($result['families'][0]['headPayload']['birthday']);
    }

    public function testFutureBirthdayBlocksAndDoesNotInventAValue(): void
    {
        // The original value stays quoted in the blocking message for the spreadsheet fixer.
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['birthday' => '01-01-2050']),
        ]);

        $this->assertSame(1, $result['counts']['blocking']);
        $future = $this->errorsFor($result, 'BDAY-FUTURE');
        $this->assertCount(1, $future);
        $this->assertSame('blocking', $future[0]['severity']);
        $this->assertStringContainsString('01-01-2050', $future[0]['message']);
        $this->assertNull($result['families'][0]['headPayload']['birthday']);
    }

    public function testOver150YearsWarnsButStillImports(): void
    {
        // Over 150 years old is stored fine (the DB accepts it), so it stays a warning.
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['birthday' => '05-14-1870']),
        ]);
        $range = $this->errorsFor($result, 'BDAY-RANGE');
        $this->assertCount(1, $range, 'an over-150-year birthday must warn');
        $this->assertSame('warning', $range[0]['severity']);
        $this->assertSame(1, $result['counts']['families']); // still built/imported

        // Plausible ages - including a centenarian (~100) - raise nothing.
        foreach (['05-14-1980', '05-14-1926'] as $good) {
            $ok = $this->importer()->validateAndBuild([$this->headRow(3, '6002', ['birthday' => $good])]);
            $codes = $this->codes($ok);
            $this->assertNotContains('BDAY-RANGE', $codes, "expected '{$good}' to pass");
            $this->assertNotContains('BDAY-FUTURE', $codes, "expected '{$good}' to pass");
        }
    }

    public function testBirthdayToleratesTheRealFilesFormatVariants(): void
    {
        // Every variant measured in the Cluster1 review: inner space, doubled dash,
        // equals sign, slash separator. Same M-D-Y order the template specifies.
        $variants = [
            '11- 30-2017' => '2017-11-30',
            '10-12--2019' => '2019-10-12',
            '05--06-1958' => '1958-05-06',
            '03-02=2020'  => '2020-03-02',
            '9/23/1989'   => '1989-09-23',
        ];

        foreach ($variants as $typed => $stored) {
            $result = $this->importer()->validateAndBuild([
                $this->headRow(3, '6001', ['birthday' => $typed]),
            ]);

            $this->assertNotContains('BDAY', $this->codes($result), "'{$typed}' must parse");
            $this->assertSame($stored, $result['families'][0]['headPayload']['birthday'],
                "'{$typed}' should store {$stored}");
        }
    }

    public function testBirthdayTruncatedOrYearOnlyBlocks(): void
    {
        // No parser can invent the missing parts, so these block until corrected or cleared.
        foreach (['03-07', '01-11-', '2008', '1/21/20104'] as $typed) {
            $result = $this->importer()->validateAndBuild([
                $this->headRow(3, '6001', ['birthday' => $typed]),
            ]);

            $this->assertContains('BDAY', $this->codes($result), "'{$typed}' should block");
            $this->assertSame('blocking', $this->errorsFor($result, 'BDAY')[0]['severity']);
            $this->assertNull($result['families'][0]['headPayload']['birthday']);
        }
    }

    public function testDuplicateMatchingSeesTolerantBirthdayFormats(): void
    {
        // normalizeBirthday feeds the DUP-DB / DUP-PERSON identity keys, so it must
        // parse "9/23/1989" the same way validateBirthday does, or a re-entered
        // person with the slash format silently fails to match their record.
        $importer = new FamilyExcelImporter();
        $reflection = new ReflectionClass($importer);
        $method = $reflection->getMethod('normalizeBirthday');
        $method->setAccessible(true);

        $this->assertSame('1989-09-23', $method->invoke($importer, '9/23/1989'));
        $this->assertSame('2017-11-30', $method->invoke($importer, '11- 30-2017'));
        $this->assertNull($method->invoke($importer, '2008'));
    }

    public function testOverLongValueIsBlocked(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['firstname' => str_repeat('A', 150)]),
        ]);
        $length = array_values(array_filter($result['errors'], static fn (array $e): bool => $e['code'] === 'LENGTH'));
        $this->assertCount(1, $length);
        $this->assertSame('blocking', $length[0]['severity']);
        $this->assertSame('firstname', $length[0]['field']);
    }

    public function testDuplicateRowsAreBlockingAndReturnedAsAGroup(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->memberRow(4, '6001', ['firstname' => 'JOSE', 'birthday' => '01-10-2012']),
            $this->memberRow(5, '6001', ['firstname' => 'JOSE', 'birthday' => '01-10-2012']),
        ]);

        $duplicates = $this->errorsFor($result, 'DUP-ROW');
        $this->assertCount(2, $duplicates);
        $this->assertSame([4, 5], array_column($duplicates, 'sheetRow'));
        $this->assertSame(['blocking', 'blocking'], array_column($duplicates, 'severity'));
        $this->assertSame([['rows' => [4, 5], 'qr' => '6001']], $result['duplicateGroups']);
    }

    public function testDuplicateRowsTreatPeriodSuffixedJrAsTheSameSuffix(): void
    {
        // The classifier's key must be independently canonical: callers can supply
        // staged rows whose equivalent suffixes differ only by a period.
        $importer = $this->importer();
        $method = (new ReflectionClass($importer))->getMethod('classifyDuplicateRows');
        $method->setAccessible(true);

        $jr = $this->memberRow(4, '6001', ['suffix' => 'JR']);
        $jrWithPeriod = $this->memberRow(5, '6001', ['suffix' => 'JR.']);

        $groups = $method->invoke($importer, ['6001' => [
            ['row' => 4, 'data' => $jr['data']],
            ['row' => 5, 'data' => $jrWithPeriod['data']],
        ]]);

        $this->assertSame([['rows' => [4, 5], 'qr' => '6001']], $groups);
    }

    public function testDuplicateRowsGroupAllThreeCopies(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->memberRow(4, '6001'),
            $this->memberRow(5, '6001'),
            $this->memberRow(6, '6001'),
        ]);

        $this->assertSame([['rows' => [4, 5, 6], 'qr' => '6001']], $result['duplicateGroups']);
        $this->assertSame([4, 5, 6], array_column($this->errorsFor($result, 'DUP-ROW'), 'sheetRow'));
    }

    public function testDuplicateRowsRequireTheSameRelationshipAndCompleteIdentity(): void
    {
        $differentRelationship = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->memberRow(4, '6001'),
            $this->memberRow(5, '6001', ['relationship' => 'SPOUSE']),
        ]);
        $this->assertSame([], $differentRelationship['duplicateGroups']);

        $blankFirstName = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->memberRow(4, '6001'),
            $this->memberRow(5, '6001', ['firstname' => '']),
        ]);
        $this->assertSame([], $blankFirstName['duplicateGroups']);

        $blankLastName = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->memberRow(4, '6001'),
            $this->memberRow(5, '6001', ['lastname' => '']),
        ]);
        $this->assertSame([], $blankLastName['duplicateGroups']);

        $blankBirthday = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->memberRow(4, '6001'),
            $this->memberRow(5, '6001', ['birthday' => '']),
        ]);
        $this->assertSame([], $blankBirthday['duplicateGroups']);
    }

    public function testDuplicateQrFamiliesAreBlockingOnBothSeparateHeadBlocks(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001', ['firstname' => 'JUAN']),
            $this->headRow(4, '6002'),
            $this->headRow(5, '6001', ['firstname' => 'PEDRO']),
        ]);

        $conflicts = $this->errorsFor($result, 'DUP-QR-FAMILY');
        $this->assertCount(2, $conflicts);
        $this->assertSame([3, 5], array_column($conflicts, 'sheetRow'));
        $this->assertSame(['blocking', 'blocking'], array_column($conflicts, 'severity'));
        $this->assertSame([], $result['duplicateGroups']);
        $this->assertNotContains('HEAD-MULTI', $this->codes($result));
        $this->assertNotContains('QR-CONTIG', $this->codes($result));
    }

    public function testContiguityWarningAllowsASeparatedSameHeadContinuation(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->headRow(4, '6002'),
            $this->headRow(5, '6001'),
        ]);

        $this->assertSame(['QR-CONTIG'], array_values(array_filter(
            $this->codes($result),
            static fn (string $code): bool => in_array($code, ['QR-CONTIG', 'DUP-QR-FAMILY', 'HEAD-MULTI'], true),
        )));
        $this->assertSame('warning', $this->errorsFor($result, 'QR-CONTIG')[0]['severity']);
    }

    public function testTwoHeadsInOneContiguousBlockAreOnlyHeadMulti(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->headRow(4, '6001', ['firstname' => 'PEDRO']),
        ]);

        $this->assertSame(['HEAD-MULTI'], array_values(array_filter(
            $this->codes($result),
            static fn (string $code): bool => in_array($code, ['QR-CONTIG', 'DUP-QR-FAMILY', 'HEAD-MULTI'], true),
        )));
    }

    // -- head-less family -------------------------------------------------------

    public function testHeadlessFamilyDoesNotInferAHeadFromMemberAddresses(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->memberRow(3, '6001', ['firstname' => 'Maria', 'address' => '', 'barangay' => '']),
            $this->memberRow(4, '6001', ['firstname' => 'Juan', 'address' => '12 Rizal St', 'barangay' => 'Poblacion']),
            $this->memberRow(5, '6001', ['firstname' => 'Jose', 'address' => '', 'barangay' => '']),
        ]);

        $headNone = array_values(array_filter(
            $result['errors'],
            static fn (array $e): bool => $e['code'] === 'HEAD-NONE'
        ));

        $this->assertCount(1, $headNone);
        $this->assertSame(3, $headNone[0]['sheetRow']);
        $this->assertStringContainsString('Set one person as Head', $headNone[0]['message']);
        $this->assertStringNotContainsString('address', $headNone[0]['message']);
    }

    // -- add-member-to-existing-family (append) --------------------------------

    public function testHeadlessGroupForExistingQrBecomesAddMember(): void
    {
        $result = $this->importer()->validateAndBuild(
            [$this->memberRow(3, '6001', ['firstname' => 'Maria', 'lastname' => 'Dela Cruz'])],
            $this->existingHead(6001, $this->storedHead()), // QR 6001 already on file
        );

        $codes = $this->codes($result);
        $this->assertContains('ADD-MEMBER', $codes);
        $this->assertNotContains('HEAD-NONE', $codes); // the head is in the DB, not missing
        $this->assertCount(1, $result['appends']);
        $this->assertSame(6001, $result['appends'][0]['qr']);
        $this->assertSame(1, $result['counts']['appends']);
    }

    public function testHeadlessGroupForNewQrIsStillHeadNone(): void
    {
        $result = $this->importer()->validateAndBuild(
            [$this->memberRow(3, '6002', ['firstname' => 'Maria'])],
            [], // QR 6002 does not exist
        );

        $this->assertContains('HEAD-NONE', $this->codes($result));
        $this->assertSame([], $result['appends']);
    }

    public function testAddMemberIsAutomaticAndSaysSo(): void
    {
        // No decision to make: the person IS added on import. To skip them the operator
        // deletes the row from the spreadsheet and uploads again.
        $result = $this->importer()->validateAndBuild(
            [$this->memberRow(3, '6001', ['firstname' => 'Maria', 'lastname' => 'Cruz'])],
            $this->existingHead(6001, $this->storedHead()),
        );

        $add = array_values(array_filter(
            $result['errors'],
            static fn (array $e): bool => $e['code'] === 'ADD-MEMBER'
        ));

        $this->assertCount(1, $add);
        $this->assertSame('warning', $add[0]['severity']);   // informational, never blocks
        $this->assertStringContainsString('will be ADDED', $add[0]['message']);
        $this->assertStringContainsString('delete this row', $add[0]['message']);
        $this->assertSame(1, $result['counts']['appends']);
    }

    // -- "already in the system" must be the SAME person, 1:1 -------------------

    public function testExistingQrWithTheSameHeadIsADuplicateFamily(): void
    {
        // Same QR, same head (name + birthday), same details: a genuine re-upload.
        $result = $this->importer()->validateAndBuild(
            [$this->headRow(3, '6001')],
            $this->existingHead(6001, $this->storedHead()),
        );

        $codes = $this->codes($result);
        $this->assertContains('DUP-EXISTS', $codes);
        $this->assertNotContains('QR-TAKEN', $codes);
        $this->assertNotContains('DUP-DIFF', $codes);
        $this->assertSame(1, $result['counts']['existing']);
        $this->assertSame(0, $result['counts']['blocking']);
    }

    public function testExistingQrHeldByADifferentPersonIsBlocked(): void
    {
        // The mistyped-QR case: 6001 is Juan's, but this row is Maria's new family. Left
        // alone the write step neither skips nor inserts - it dies on the qr_control clash.
        $result = $this->importer()->validateAndBuild(
            [$this->headRow(3, '6001', ['firstname' => 'Maria', 'lastname' => 'Santos'])],
            $this->existingHead(6001, $this->storedHead()),
        );

        $taken = $this->errorsFor($result, 'QR-TAKEN');

        $this->assertCount(1, $taken);
        $this->assertSame('blocking', $taken[0]['severity']);
        $this->assertStringContainsString('JUAN DELA CRUZ', $taken[0]['message']);
        $this->assertStringContainsString('MARIA SANTOS', $taken[0]['message']);
        $this->assertNotContains('DUP-EXISTS', $this->codes($result));
        $this->assertSame(0, $result['counts']['existing']);
    }

    public function testExistingQrWithTheSameNameButADifferentBirthdayIsBlocked(): void
    {
        // activeHeadExists matches on birthday too, so this would NOT be skipped - it would
        // be inserted, and then fail on the QR. Block it in review instead.
        $result = $this->importer()->validateAndBuild(
            [$this->headRow(3, '6001', ['birthday' => '06-14-1980'])],
            $this->existingHead(6001, $this->storedHead()),
        );

        $taken = $this->errorsFor($result, 'QR-TAKEN');

        $this->assertCount(1, $taken);
        $this->assertSame('blocking', $taken[0]['severity']);
        $this->assertStringContainsString('1980-05-14', $taken[0]['message']); // what is stored
        $this->assertStringContainsString('1980-06-14', $taken[0]['message']); // what the file says
    }

    public function testDuplicateFamilyWhoseStoredDetailsDifferIsReported(): void
    {
        // Same person, same family - but the file carries a newer contact number. The import
        // SKIPS the family, so that edit would be silently lost. Say so.
        $result = $this->importer()->validateAndBuild(
            [$this->headRow(3, '6001', ['contactnumber' => '09171234567'])],
            $this->existingHead(6001, $this->storedHead(['contactnumber' => '09990000000'])),
        );

        $diff = $this->errorsFor($result, 'DUP-DIFF');

        $this->assertContains('DUP-EXISTS', $this->codes($result)); // still the same family
        $this->assertCount(1, $diff);
        $this->assertSame('warning', $diff[0]['severity']);
        $this->assertStringContainsString('Contact Number', $diff[0]['message']);
        $this->assertStringContainsString('09171234567', $diff[0]['message']);
        $this->assertStringContainsString('09990000000', $diff[0]['message']);
        $this->assertStringContainsString('will NOT be saved', $diff[0]['message']);
    }

    public function testHeadAlreadyInTheSystemUnderAnotherQrIsFlagged(): void
    {
        // The silent-skip case: Juan is already a head under QR 6001, and the batch re-enters
        // him under a brand-new QR 7777. The QR is free, so nothing else catches it - but the
        // write step skips his whole family and says nothing.
        $result = $this->importer()->validateAndBuild(
            [$this->headRow(3, '7777'), $this->memberRow(4, '7777')],
            [],
            $this->existingPerson('Juan', 'Dela Cruz', '1980-05-14', 6001),
        );

        $dup = $this->errorsFor($result, 'DUP-DB');

        $this->assertCount(1, $dup);
        $this->assertSame('warning', $dup[0]['severity']);
        $this->assertSame(3, $dup[0]['sheetRow']);
        $this->assertStringContainsString('family 6001', $dup[0]['message']);
        $this->assertStringContainsString('will NOT be saved', $dup[0]['message']);
    }

    public function testAppendIsNotPromisedForAMemberAlreadyInThatFamily(): void
    {
        // The write step skips a member already under the head, so "will be ADDED" was a lie.
        $result = $this->importer()->validateAndBuild(
            [$this->memberRow(3, '6001', ['firstname' => 'Jose', 'lastname' => 'Dela Cruz', 'birthday' => '01-02-2005'])],
            $this->existingHead(6001, $this->storedHead()),
            $this->existingPerson('Jose', 'Dela Cruz', '2005-01-02', 6001, false),
        );

        $codes = $this->codes($result);

        $this->assertContains('DUP-DB', $codes);
        $this->assertNotContains('ADD-MEMBER', $codes);
        $this->assertSame([], $result['appends']);          // nothing queued to add
        $this->assertSame(0, $result['counts']['appends']);
    }

    public function testAPersonOnFileUnderTheirOwnQrIsNotDoubleReported(): void
    {
        // Juan is head of 6001 and the batch re-uploads 6001. That is DUP-EXISTS, not a
        // "person is under another family" warning.
        $result = $this->importer()->validateAndBuild(
            [$this->headRow(3, '6001')],
            $this->existingHead(6001, $this->storedHead()),
            $this->existingPerson('Juan', 'Dela Cruz', '1980-05-14', 6001),
        );

        $this->assertContains('DUP-EXISTS', $this->codes($result));
        $this->assertNotContains('DUP-DB', $this->codes($result));
    }

    // -- counts: the file total vs what can be built ---------------------------

    public function testRowsAndGroupsCountEveryPersonInTheFileIncludingBrokenOnes(): void
    {
        // 6 people: one buildable family (2), a head-less group (2), and 2 rows whose QR is
        // unusable. families/members only ever describe what could be BUILT, so they see 2
        // of these people - the counts the review shows the operator must see all 6, or the
        // tile quietly hides exactly the rows that need fixing.
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            $this->memberRow(4, '6001'),
            $this->memberRow(5, '6002', ['firstname' => 'Rosa']),   // head-less: builds nothing
            $this->memberRow(6, '6002', ['firstname' => 'Mark']),
            $this->headRow(7, 'ABC', ['firstname' => 'Rico']),      // bad QR: never grouped
            $this->headRow(8, '', ['firstname' => 'Nilo']),         // blank QR: never grouped
        ]);

        $counts = $result['counts'];

        $this->assertSame(6, $counts['rows']);      // everyone in the file
        $this->assertSame(2, $counts['groups']);    // 6001 and 6002 (the bad QRs form none)
        $this->assertSame(1, $counts['families']);  // only 6001 could be built
        $this->assertSame(1, $counts['members']);
        $this->assertSame(2, $counts['people']);    // buildable only - NOT a file total
    }

    public function testBlankRowsAreNotCountedAsPeople(): void
    {
        $result = $this->importer()->validateAndBuild([
            $this->headRow(3, '6001'),
            ['sheetRow' => 4, 'data' => ['familyno' => '', 'relationship' => '', 'firstname' => '', 'lastname' => '']],
        ]);

        $this->assertSame(1, $result['counts']['rows']);
    }

    // -- strict service / sector codes -----------------------------------------

    public function testSpacingAndCommaSeparatedServiceCodesResolveToRealCodes(): void
    {
        // Legitimate non-alias repairs stay: spacing cleanup ("EDA 8" -> "EDA8") and
        // comma-separated reference codes. ED8A/EDAI/SCI are no longer curated to
        // EDA8/SC1 - each unknown token must block (see testFormerServiceAliasTokensNowBlock).
        $cases = [
            'EDA 8' => [80],
            'B2,B3' => [20, 21],
        ];

        foreach ($cases as $typed => $expectedIds) {
            $result = $this->importerWithLookups()->validateAndBuild([
                $this->headRow(3, '6001', ['services' => $typed]),
            ]);

            $this->assertNotContains('SERVICE', $this->codes($result), "'{$typed}' must resolve");
            $this->assertSame($expectedIds, $result['families'][0]['headServiceIds'],
                "'{$typed}' should resolve to " . implode(',', $expectedIds));
        }
    }

    public function testWhitespaceDelimitedServiceCodesMustBeFixed(): void
    {
        $result = $this->importerWithLookups()->validateAndBuild([
            $this->headRow(3, '6001', ['services' => 'EDA8 EDA9']),
        ]);

        $this->assertSame(1, $result['counts']['blocking']);
        $this->assertSame('SERVICE', $this->errorsFor($result, 'SERVICE')[0]['code']);
        $this->assertStringContainsString('EDA8EDA9', $this->errorsFor($result, 'SERVICE')[0]['message']);
        $this->assertSame([], $result['families'][0]['headServiceIds']);
    }

    public function testWhitespaceDelimitedSectorCodesBecomeOneInvalidToken(): void
    {
        $result = $this->importerWithLookups()->validateAndBuild([
            $this->headRow(3, '6001', ['sector' => 'SC1 SC2']),
        ]);

        $this->assertSame(1, $result['counts']['blocking']);
        $this->assertStringContainsString('SC1SC2', $this->errorsFor($result, 'SECTOR')[0]['message']);
        $this->assertSame([], $result['families'][0]['headPayload']['sector_ids']);
    }

    public function testBlankSectorAndServiceListsCreateNoAssignments(): void
    {
        $result = $this->importerWithLookups()->validateAndBuild([
            $this->headRow(3, '6001', ['sector' => '', 'services' => '']),
        ]);

        $this->assertSame([], $result['families'][0]['headPayload']['sector_ids']);
        $this->assertSame([], $result['families'][0]['headServiceIds']);
    }

    public function testUnknownServiceTokenBlocksAndPreventsPartialServiceAssignment(): void
    {
        $result = $this->importerWithLookups()->validateAndBuild([
            $this->headRow(3, '6001', ['services' => 'EDA123,EDA8']),
        ]);

        $this->assertSame(1, $result['counts']['blocking']);
        $service = $this->errorsFor($result, 'SERVICE');
        $this->assertCount(1, $service);
        $this->assertSame('blocking', $service[0]['severity']);
        $this->assertStringContainsString('EDA123', $service[0]['message']);
        $this->assertSame([], $result['families'][0]['headServiceIds']);
    }

    public function testUnknownSectorTokenBlocksAndPreventsFallbackOrPartialAssignment(): void
    {
        $result = $this->importerWithLookups()->validateAndBuild([
            $this->headRow(3, '6001', ['sector' => 'SC, ZZ9']),
        ]);

        $this->assertSame(1, $result['counts']['blocking']);
        $sector = $this->errorsFor($result, 'SECTOR');
        $this->assertCount(1, $sector);
        $this->assertSame('blocking', $sector[0]['severity']);
        $this->assertStringContainsString('ZZ9', $sector[0]['message']);
        $this->assertSame([], $result['families'][0]['headPayload']['sector_ids']);
    }

    public function testDeliberatelyTypedOtherSectorStaysSilent(): void
    {
        // 'OTHER' is a real reference shortcode (see the sector reference), so it stays
        // silent - only the OTHERS alias is gone (see the OTHERSectorToken tests below).
        $result = $this->importerWithLookups()->validateAndBuild([
            $this->headRow(3, '6001', ['sector' => 'OTHER']),
        ]);

        $this->assertNotContains('SECTOR', $this->codes($result));
        $this->assertSame([9], $result['families'][0]['headPayload']['sector_ids']);
    }

    public function testFormerServiceAliasTokensNowBlock(): void
    {
        // Fix 1: the importer must not guess-repair service codes. Each former alias
        // token blocks on its own, names the exact token, and assigns no service IDs.
        foreach (['ED8A', 'EDAI', 'SCI'] as $typed) {
            $result = $this->importerWithLookups()->validateAndBuild([
                $this->headRow(3, '6001', ['services' => $typed]),
            ]);

            $this->assertSame(1, $result['counts']['blocking'], "'{$typed}' must block");
            $service = $this->errorsFor($result, 'SERVICE');
            $this->assertCount(1, $service, "'{$typed}' must raise exactly one SERVICE error");
            $this->assertSame('blocking', $service[0]['severity']);
            $this->assertStringContainsString($typed, $service[0]['message'],
                "'{$typed}' must be named in the error message");
            $this->assertSame([], $result['families'][0]['headServiceIds'],
                "'{$typed}' must leave the row with no service IDs");
        }
    }

    public function testOTHERSectorTokenBlocksAndAssignsNoSectorId(): void
    {
        // Fix 1: OTHERS used to be aliased to the Other sector ID; it is now an unknown
        // token that blocks and assigns no Other-sector ID.
        $result = $this->importerWithLookups()->validateAndBuild([
            $this->headRow(3, '6001', ['sector' => 'OTHERS']),
        ]);

        $this->assertSame(1, $result['counts']['blocking']);
        $sector = $this->errorsFor($result, 'SECTOR');
        $this->assertCount(1, $sector);
        $this->assertSame('blocking', $sector[0]['severity']);
        $this->assertStringContainsString('OTHERS', $sector[0]['message']);
        $this->assertSame([], $result['families'][0]['headPayload']['sector_ids']);
    }

    public function testOTHERSectorTokenPreventsPartialSectorIdAssignment(): void
    {
        // A valid SC token sitting next to OTHERS must not survive as a partial assignment.
        $result = $this->importerWithLookups()->validateAndBuild([
            $this->headRow(3, '6001', ['sector' => 'SC,OTHERS']),
        ]);

        $this->assertSame(1, $result['counts']['blocking']);
        $this->assertStringContainsString('OTHERS', $this->errorsFor($result, 'SECTOR')[0]['message']);
        $this->assertSame([], $result['families'][0]['headPayload']['sector_ids']);
    }

    // -- helpers ---------------------------------------------------------------

    /** Importer with lookup caches primed empty so validateAndBuild needs no DB. */
    private function importer(): FamilyExcelImporter
    {
        $importer = new FamilyExcelImporter();
        $reflection = new ReflectionClass($importer);

        foreach (['sectorByCode', 'serviceByCode', 'incomeByLabel'] as $property) {
            $prop = $reflection->getProperty($property);
            $prop->setAccessible(true);
            $prop->setValue($importer, []);
        }

        // The barangay list comes from the `barangay` table now, so the cache is
        // primed here the same way the other lookups are - keyed by the fold
        // FamilyExcelImporter::normalizeBarangay() applies.
        $barangays = $reflection->getProperty('barangayLookup');
        $barangays->setAccessible(true);
        $barangays->setValue($importer, array_fill_keys(
            ['binan', 'bungahan', 'santo tomas', 'canlalay', 'casile', 'de la paz', 'ganado',
                'langkiwa', 'loma', 'malaban', 'malamig', 'mamplasan', 'platero', 'poblacion',
                'san antonio', 'san francisco', 'san jose', 'san vicente', 'santo domingo',
                'santo nino', 'soro soro', 'timbao', 'tubigan', 'zapote'],
            true
        ));

        return $importer;
    }

    /** Importer with the real-shaped sector/service lookup maps, so service
     * spacing cleanup and the strict sector reference codes have codes to hit. */
    private function importerWithLookups(): FamilyExcelImporter
    {
        $importer  = $this->importer();
        $reflection = new ReflectionClass($importer);

        $services = $reflection->getProperty('serviceByCode');
        $services->setAccessible(true);
        $services->setValue($importer, ['EDA8' => 80, 'EDA1' => 81, 'B2' => 20, 'B3' => 21, 'SC1' => 10]);

        $sectors = $reflection->getProperty('sectorByCode');
        $sectors->setAccessible(true);
        $sectors->setValue($importer, ['SC' => 1, 'OTHER' => 9]);

        return $importer;
    }

    /** @return list<string> */
    private function codes(array $result): array
    {
        return array_map(static fn (array $e): string => $e['code'], $result['errors']);
    }

    /** @return list<array> the result's errors carrying $code. */
    private function errorsFor(array $result, string $code): array
    {
        return array_values(array_filter(
            $result['errors'],
            static fn (array $e): bool => $e['code'] === $code,
        ));
    }

    /**
     * The stored DB record for the default head - taken from what the importer itself would
     * write, so the fixture can't drift from the real normalisation (name cleaning, birthday
     * to Y-m-d, address+barangay combined).
     *
     * @param array<string, string|null> $overrides
     */
    private function storedHead(array $overrides = []): array
    {
        $built = $this->importer()->validateAndBuild([$this->headRow(2, '6001')]);

        return array_merge($built['families'][0]['headPayload'], $overrides);
    }

    /**
     * [qr => stored head] - the shape existingHeadsForRows() returns.
     *
     * @param array<string, string|null> $record
     */
    private function existingHead(int $qr, array $record): array
    {
        return [$qr => [
            'headID' => 42,
            'name'   => trim(((string) ($record['firstname'] ?? '')) . ' ' . ((string) ($record['lastname'] ?? ''))),
            'record' => $record,
        ]];
    }

    /** [identity => person on file] - the shape existingPeopleForRows() returns. */
    private function existingPerson(string $first, string $last, string $birthday, int $qr, bool $isHead = true): array
    {
        return [mb_strtolower($first) . '|' . mb_strtolower($last) . '|' . $birthday => [
            'name'   => $first . ' ' . $last,
            'qr'     => $qr,
            'headID' => 42,
            'isHead' => $isHead,
        ]];
    }

    /** @param array<string,string> $overrides */
    private function headRow(int $sheetRow, string $qr, array $overrides = []): array
    {
        return ['sheetRow' => $sheetRow, 'data' => array_merge([
            'familyno' => $qr, 'relationship' => 'Head', 'firstname' => 'Juan', 'lastname' => 'Dela Cruz',
            'middlename' => '', 'suffix' => '', 'birthday' => '05-14-1980', 'sex' => 'Male',
            'civilstatus' => 'M', 'contactnumber' => '', 'religion' => '', 'education' => 'CG',
            'job' => 'Driver', 'monthlyincome' => '5000', 'address' => '123 Rizal St',
            'barangay' => 'Poblacion', 'sector' => '', 'services' => '',
        ], $overrides)];
    }

    /**
     * A complete member row by default; blank optional profile values receive importer defaults.
     *
     * @param array<string,string> $overrides
     */
    private function memberRow(int $sheetRow, string $qr, array $overrides = []): array
    {
        return ['sheetRow' => $sheetRow, 'data' => array_merge([
            'familyno' => $qr, 'relationship' => 'Child', 'firstname' => 'Jose', 'lastname' => 'Dela Cruz',
            'middlename' => '', 'suffix' => '', 'birthday' => '01-10-2012', 'sex' => 'Male',
            'civilstatus' => 'S', 'contactnumber' => '', 'religion' => '', 'education' => 'E',
            'job' => 'Student', 'monthlyincome' => '0', 'address' => '', 'barangay' => '',
            'sector' => '', 'services' => '',
        ], $overrides)];
    }

    public function testLookupKeysDeriveOnlyFromQrAndLastname(): void
    {
        // ImportLookupCache reuses the existing-record lookups across a review session
        // and rebuilds them only when familyno or lastname changes. That is safe only
        // while those two are the whole input. This walks every other importer field,
        // changes it, and demands the lookup keys do not move. A failure here means the
        // cache can serve stale lookups, and a stale lookup lets the review pass a file
        // the write step then skips.
        $importer = new FamilyExcelImporter();

        $base = [['sheetRow' => 3, 'data' => [
            'familyno' => '6001', 'relationship' => 'Head', 'lastname' => 'Cruz',
            'firstname' => 'Juan', 'middlename' => 'P', 'suffix' => 'Jr',
            'birthday' => '03-03-1980', 'sex' => 'Male', 'civilstatus' => 'Single',
            'contactnumber' => '09171234567', 'religion' => 'Catholic',
            'education' => 'College', 'job' => 'Driver', 'monthlyincome' => '5000',
            'address' => '1 Street', 'barangay' => 'Canlalay', 'sector' => 'SC',
            'services' => 'FA2',
        ]]];

        $qrKeys = $importer->qrKeysForRows($base);
        $nameKeys = $importer->lastnameKeysForRows($base);

        foreach (array_keys($base[0]['data']) as $field) {
            if (in_array($field, ImportLookupCache::INVALIDATING_FIELDS, true)) {
                continue;
            }

            $mutated = $base;
            $mutated[0]['data'][$field] = 'CHANGED-' . $field;

            $this->assertSame($qrKeys, $importer->qrKeysForRows($mutated),
                'Changing ' . $field . ' moved the QR lookup keys, so the cache is unsound.');
            $this->assertSame($nameKeys, $importer->lastnameKeysForRows($mutated),
                'Changing ' . $field . ' moved the lastname lookup keys, so the cache is unsound.');
        }
    }

    public function testTheInvalidatingFieldsAreExactlyTheOnesThatMoveTheKeys(): void
    {
        // The other half: each declared invalidating field must actually change a key.
        // A field listed there that changes nothing would cost a needless rebuild on
        // every edit and quietly undo the optimization.
        $importer = new FamilyExcelImporter();

        // Both halves of "exactly": nothing extra is declared, and each declared
        // field is shown below to move a key.
        $this->assertSame(
            ['familyno', 'lastname'],
            \App\Libraries\ImportLookupCache::INVALIDATING_FIELDS
        );

        $base = [['sheetRow' => 3, 'data' => ['familyno' => '6001', 'lastname' => 'Cruz']]];

        $qrChanged = $base;
        $qrChanged[0]['data']['familyno'] = '6002';
        $this->assertNotSame(
            $importer->qrKeysForRows($base),
            $importer->qrKeysForRows($qrChanged)
        );

        $nameChanged = $base;
        $nameChanged[0]['data']['lastname'] = 'Santos';
        $this->assertNotSame(
            $importer->lastnameKeysForRows($base),
            $importer->lastnameKeysForRows($nameChanged)
        );
    }

    // -- barangayID resolution (Task 9) -----------------------------------------

    public function testTemplateOffersBarangayColumn(): void
    {
        $headers = \App\Libraries\FamilyExcelTemplate::COLUMNS;
        $this->assertContains('BARANGAY', array_map('strtoupper', $headers));
    }

    /**
     * MemberFieldNormalizer::barangayKey() is what both the importer's Barangay
     * validation and the barangayID resolution fold through - it has to treat "Santo
     * Niño" (the DB spelling) and "Santo Nino" (the sheet dropdown's ASCII
     * spelling) as the same barangay, and a case/hyphen difference like
     * "Soro-Soro" vs "Soro-soro" as the same barangay too.
     */
    public function testBarangayKeyFoldsSpecialCharactersAndHyphenation(): void
    {
        $this->assertSame(
            \App\Support\MemberFieldNormalizer::barangayKey('Santo Niño'),
            \App\Support\MemberFieldNormalizer::barangayKey('Santo Nino')
        );

        $this->assertSame(
            \App\Support\MemberFieldNormalizer::barangayKey('Soro-soro'),
            \App\Support\MemberFieldNormalizer::barangayKey('Soro-Soro')
        );

        // Trims and is case-insensitive on an otherwise plain name.
        $this->assertSame(
            \App\Support\MemberFieldNormalizer::barangayKey('Biñan'),
            \App\Support\MemberFieldNormalizer::barangayKey('  BIÑAN  ')
        );
    }

    /**
     * Resolves a head's Barangay cell to the matching barangayID (case-insensitive,
     * ñ-tolerant), and leaves it null on a blank or unrecognised value rather than
     * failing the row - validateBarangay() already raises the review error for that case.
     */
    public function testBarangayIdForHeadResolvesOrReturnsNull(): void
    {
        $importer = new FamilyExcelImporter();
        $reflection = new ReflectionClass($importer);

        $map = $reflection->getProperty('barangayIdMap');
        $map->setAccessible(true);
        $map->setValue($importer, [
            \App\Support\MemberFieldNormalizer::barangayKey('Santo Niño') => 19,
            \App\Support\MemberFieldNormalizer::barangayKey('Soro-soro') => 21,
        ]);

        $resolve = $reflection->getMethod('barangayIdForHead');
        $resolve->setAccessible(true);

        $this->assertSame(19, $resolve->invoke($importer, 'Santo Nino'));
        $this->assertSame(21, $resolve->invoke($importer, '  soro-soro  '));
        $this->assertNull($resolve->invoke($importer, 'Not A Real Barangay'));
        $this->assertNull($resolve->invoke($importer, ''));
    }
}
