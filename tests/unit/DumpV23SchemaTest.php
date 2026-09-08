<?php

namespace Tests\Unit;

use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Database\DumpSchema;

/**
 * Pins the V21 batch schedule columns and the V22 normalization, carried into V24, to the dump.
 *
 * The dump is the schema source of truth and there are no migrations, so a
 * column that never made it into the dump would still pass every test that
 * only touches the model. This reads the dump text directly.
 */
final class DumpV23SchemaTest extends CIUnitTestCase
{
    private function dumpText(): string
    {
        $path = DumpSchema::dumpPath();
        $this->assertNotNull($path, 'no accesscardV*.sql in the project root');

        return (string) file_get_contents($path);
    }

    /**
     * The CREATE TABLE body for distribution_batch alone. Column names like
     * `color` and `started_at` appear in other tables too, so a search across
     * the whole dump would pass on a column that never reached this one.
     */
    private function batchTable(): string
    {
        $matched = preg_match(
            '/CREATE TABLE `distribution_batch` \((.*?)\n\)/s',
            $this->dumpText(),
            $m
        );

        $this->assertSame(1, $matched, 'no CREATE TABLE `distribution_batch` in the dump');

        return $m[1];
    }

    public function testScheduleColumnsExist(): void
    {
        $table = $this->batchTable();

        foreach (['venue', 'scheduled_start', 'scheduled_end', 'daily_start_time', 'daily_end_time', 'color'] as $column) {
            $this->assertStringContainsString('`' . $column . '`', $table, $column . ' missing from distribution_batch');
        }
    }

    public function testStartedAtIsNullable(): void
    {
        $this->assertMatchesRegularExpression(
            '/`started_at` timestamp NULL DEFAULT NULL/',
            $this->batchTable(),
            'started_at must be nullable so a plotted batch can report that it has not started'
        );
    }

    public function testDumpIsV24(): void
    {
        $this->assertStringEndsWith('accesscardV24.sql', (string) DumpSchema::dumpPath());
    }

    /** The CREATE TABLE body for one table, so a column name shared with another cannot pass for it. */
    private function table(string $name): string
    {
        $matched = preg_match('/CREATE TABLE `' . $name . '` \((.*?)\n\)/s', $this->dumpText(), $m);

        $this->assertSame(1, $matched, 'no CREATE TABLE `' . $name . '` in the dump');

        return $m[1];
    }

    /** Sectors are a relation, not a JSON list packed into a member column. */
    public function testMemberSectorsReplacedTheJsonColumn(): void
    {
        $this->assertStringNotContainsString('`sectorID`', $this->table('member'));

        $junction = $this->table('member_sectors');
        $this->assertStringContainsString('PRIMARY KEY (`memberID`,`sectorID`)', $junction);
        $this->assertStringContainsString('FOREIGN KEY (`sectorID`)', $junction);
        // Both sides, or a member row can be deleted out from under its sectors.
        $this->assertStringContainsString('FOREIGN KEY (`memberID`)', $junction);
    }

    /** A service is grouped by a category or by a sector, never by loose text. */
    public function testServicesCarryKeysNotACategoryName(): void
    {
        $services = $this->table('services');

        $this->assertStringNotContainsString('`category` text', $services);
        $this->assertStringContainsString('`categoryID`', $services);
        $this->assertStringContainsString('`sectorID`', $services);
        // Exactly one of the two keys: `is null <> is null` rejects both-null and
        // both-set, which a bare CHECK assertion would let drift.
        $this->assertStringContainsString(
            'CHECK (`categoryID` is null <> (`sectorID` is null))',
            $services
        );
    }

    /** Uppercase has to be storable, which the Title Case enums made impossible. */
    public function testNameEnumsAreUppercase(): void
    {
        $member = $this->table('member');

        $this->assertStringContainsString("enum('JR','SR','I','II','III','IV','V')", $member);
        $this->assertStringContainsString("enum('MALE','FEMALE')", $member);
    }

    /** The barangay is a key on member, and the address column no longer repeats it. */
    public function testBarangayIsAForeignKey(): void
    {
        $this->assertStringContainsString('FOREIGN KEY (`barangayID`)', $this->table('member'));
    }

    /** Card issuance is recorded when a card is generated, not when a number is typed. */
    public function testQrControlRecordsGeneration(): void
    {
        $this->assertStringContainsString('`card_generated_at`', $this->table('qr_control'));
    }

    /** Money is not a float, and the one capitalized column is gone. */
    public function testSalaryIsDecimalAndLowercase(): void
    {
        $member = $this->table('member');

        $this->assertStringContainsString('`salary` decimal(12,2)', $member);
        $this->assertStringNotContainsString('`Salary`', $member);
    }
}
