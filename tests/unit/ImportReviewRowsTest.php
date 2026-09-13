<?php

namespace Tests\Unit;

use App\Libraries\ImportLookupCache;
use App\Libraries\ImportStagingStore;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;
use Tests\Support\Database\DumpSchema;

/**
 * Feature coverage for the review rows and apply endpoints through the real route,
 * filters and controller.
 *
 * The rows endpoint is what makes a 10,000-row import reviewable: the page asks for one
 * slice at a time instead of carrying every person in its HTML. Its failure modes matter
 * as much as its happy path, because the staging file can vanish mid-review (a sweep
 * after the 24h TTL, or the job committing in another tab) and an operator who is told
 * nothing would keep typing fixes into a review that can no longer be saved.
 *
 * Schema comes from the dump (Tests\Support\Database\DumpSchema), so the `job_queue`,
 * `users` and `qr_control` tables this needs carry the column set production runs on.
 *
 * @internal
 */
final class ImportReviewRowsTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    private string $stagingDir;

    /** Job IDs staged this test, so their ImportLookupCache file can be forgotten after. */
    private array $stagedJobIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->stagingDir = WRITEPATH . 'import-staging-test-' . uniqid('', true);
        mkdir($this->stagingDir, 0775, true);

        \CodeIgniter\Config\Services::injectMock('importStaging', new ImportStagingStore($this->stagingDir));

        DumpSchema::create(db_connect());
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->stagingDir . '/*') as $file) {
            @unlink((string) $file);
        }

        @rmdir($this->stagingDir);

        // ImportLookupCache (unlike ImportStagingStore) has no service seam to point at the
        // temp dir above, so the apply endpoint writes its cache to the real writable/
        // import-staging under the job ID the insert just handed out. Emptying the table
        // between tests restarts that auto-increment, so without this a later test can
        // read an earlier test's stale cache under the same ID.
        foreach ($this->stagedJobIds as $jobId) {
            (new ImportLookupCache())->forget($jobId);
        }

        \CodeIgniter\Config\Services::reset();
        DumpSchema::drop(db_connect());
        parent::tearDown();
    }

    /**
     * Stages an exact duplicate pair, a clean head, and a member with a blocking SEX error.
     * Returns the job ID.
     */
    private function stageJob(int $userId): int
    {
        $db = db_connect();

        $db->table('job_queue')->insert([
            'type'        => 'family_import',
            'status'      => 'done',
            'result_json' => json_encode(['phase' => 'review', 'counts' => ['rows' => 2]]),
            'userID'      => $userId,
            'dt_created'  => date('Y-m-d H:i:s'),
        ]);

        $jobId = (int) $db->insertID();

        service('importStaging')->save($jobId, [
            'phase'      => 'review',
            'file'       => 'import.xlsx',
            'columns'    => ['sex' => 'H', 'lastname' => 'C'],
            'fileErrors' => [],
            'changes'    => [],
            'rows'       => [
                ['sheetRow' => 3, 'data' => [
                    'familyno' => '6001', 'relationship' => 'Child', 'lastname' => 'Cruz',
                    'firstname' => 'Ana', 'birthday' => '02-02-2010', 'sex' => 'Female',
                    'address' => '1 Street', 'barangay' => 'Canlalay',
                    'civilstatus' => 'Single', 'education' => 'Elementary', 'job' => 'Student',
                    'monthlyincome' => '0',
                ]],
                ['sheetRow' => 4, 'data' => [
                    'familyno' => '6001', 'relationship' => 'Child', 'lastname' => 'Cruz',
                    'firstname' => 'Ana', 'birthday' => '02-02-2010', 'sex' => 'Female',
                    'address' => '1 Street', 'barangay' => 'Canlalay',
                    'civilstatus' => 'Single', 'education' => 'Elementary', 'job' => 'Student',
                    'monthlyincome' => '0',
                ]],
                ['sheetRow' => 5, 'data' => [
                    'familyno' => '6001', 'relationship' => 'Head', 'lastname' => 'Cruz',
                    'firstname' => 'Juan', 'birthday' => '03-03-1980', 'sex' => 'Male',
                    'address' => '1 Street', 'barangay' => 'Canlalay',
                    'civilstatus' => 'Single', 'education' => 'College', 'job' => 'Driver',
                    'monthlyincome' => '5000',
                ]],
                ['sheetRow' => 6, 'data' => [
                    'familyno' => '6001', 'relationship' => 'Child', 'lastname' => 'Cruz',
                    'firstname' => 'Lia', 'birthday' => '02-02-2012', 'sex' => 'Mail',
                    'address' => '1 Street', 'barangay' => 'Canlalay',
                    'civilstatus' => 'Single', 'education' => 'Elementary', 'job' => 'Student',
                    'monthlyincome' => '0',
                ]],
            ],
            'discarded' => [],
            'duplicateGroups' => [['rows' => [3, 4], 'qr' => '6001']],
            'errors' => [
                ['sheetRow' => 3, 'familyNo' => '6001', 'code' => 'DUP-ROW', 'field' => null,
                    'message' => 'This is an exact duplicate of row 4.', 'severity' => 'blocking'],
                ['sheetRow' => 4, 'familyNo' => '6001', 'code' => 'DUP-ROW', 'field' => null,
                    'message' => 'This is an exact duplicate of row 3.', 'severity' => 'blocking'],
                ['sheetRow' => 6, 'familyNo' => '6001', 'code' => 'SEX', 'field' => 'sex',
                    'message' => 'Sex must be Male or Female.', 'severity' => 'blocking'],
            ],
            'counts' => ['rows' => 4, 'blocking' => 3, 'warnings' => 0],
        ]);

        $this->stagedJobIds[] = $jobId;

        return $jobId;
    }

    /**
     * An Encoder account. username and password are the two columns the dump
     * requires of every account; nothing here reads them.
     */
    private function encoder(): int
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username'      => uniqid('encoder-', true),
            'password'      => 'x',
            'account_level' => 'encoder',
        ]);

        return (int) $db->insertID();
    }

    /** @return array{is_logged_in: bool, role: string, user_id: int} */
    private function session(int $userId, string $role = 'encoder'): array
    {
        return ['is_logged_in' => true, 'role' => $role, 'user_id' => $userId];
    }

    /**
     * Replaces the staging service with a store that fails one persistence operation.
     * The errors failure occurs after rows have been written, exercising review rollback.
     */
    private function failReviewPersistence(string $failure): void
    {
        $store = new class($this->stagingDir, $failure) extends ImportStagingStore {
            public function __construct(string $dir, private string $failure)
            {
                parent::__construct($dir);
            }

            public function saveRows(int $jobId, array $rows): bool
            {
                if ($this->failure === 'throw') {
                    throw new \RuntimeException('Rows staging failed.');
                }

                if ($this->failure === 'rows') {
                    return false;
                }

                return parent::saveRows($jobId, $rows);
            }

            public function saveErrors(
                int $jobId,
                array $errors,
                array $counts,
                array $discarded,
                array $duplicateGroups,
                array $changes,
            ): bool {
                if ($this->failure === 'errors') {
                    return false;
                }

                return parent::saveErrors($jobId, $errors, $counts, $discarded, $duplicateGroups, $changes);
            }
        };

        \CodeIgniter\Config\Services::injectMock('importStaging', $store);
    }

    public function testItReturnsTheFirstPageOfRows(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        $result = $this->withSession($this->session($userId))
            ->get('records/import/review/' . $jobId . '/rows?page=1&per=25');

        $result->assertStatus(200);
        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertCount(4, $json['rows']);
        $this->assertSame(4, $json['total']);
        $this->assertSame(4, $json['filtered']);
        $this->assertSame(1, $json['page']);
        $this->assertSame(25, $json['per']);
    }

    public function testItHonoursTheSeverityFilter(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        $result = $this->withSession($this->session($userId))
            ->get('records/import/review/' . $jobId . '/rows?severity=blocking');

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(3, $json['filtered']);
        $this->assertSame(3, $json['rows'][0]['sheetRow']);
        $this->assertSame('blocking', $json['rows'][0]['severity']);
    }

    public function testItReturns404WhenTheStagingFileIsGone(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        service('importStaging')->delete($jobId);

        $result = $this->withSession($this->session($userId))
            ->get('records/import/review/' . $jobId . '/rows');

        $result->assertStatus(404);
        $this->assertStringContainsString('no longer available', (string) $result->response()->getBody());
    }

    /**
     * A role the records-import manifest entry does not list never reaches the
     * controller's own 403 guard: RoleNavFilter (app/Filters/RoleNavFilter.php)
     * rejects it first with a bare 404, by design (its docblock: a role without
     * a manifest entry gets a 404 rather than a redirect, so the response does
     * not confirm a page it may not use even exists).
     */
    public function testItReturns404ForARoleWithoutImportAccess(): void
    {
        $db = db_connect();
        $db->table('users')->insert([
            'username' => 'viewer-fixture', 'password' => 'x', 'account_level' => 'viewer',
        ]);
        $userId = (int) $db->insertID();
        $jobId  = $this->stageJob($userId);

        $result = $this->withSession($this->session($userId, 'viewer'))
            ->get('records/import/review/' . $jobId . '/rows');

        $result->assertStatus(404);
    }

    public function testApplyPatchesTheRowAndClearsItsFlag(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 6,
                'fields'     => ['sex' => 'Female'],
            ]);

        $result->assertStatus(200);
        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame('', $json['row']['severity']);
        $this->assertSame([], $json['row']['fields']);
        $this->assertSame(2, $json['counts']['blocking']);

        $staged = service('importStaging')->load($jobId);
        $this->assertSame('FEMALE', $staged['rows'][3]['data']['sex']);
    }

    public function testMalformedHeadContactBlocksAndClearingItLeavesAReadinessWarning(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);
        $staged = service('importStaging')->load($jobId);
        $staged['rows'][2]['data']['contactnumber'] = '123';
        $staged['columns']['contactnumber'] = 'J';
        $staged['errors'][] = [
            'sheetRow' => 5, 'familyNo' => '6001', 'code' => 'CONTACT', 'field' => 'contactnumber',
            'message' => 'Contact number "123" is not valid.', 'severity' => 'blocking',
        ];
        $staged['counts']['blocking']++;
        service('importStaging')->save($jobId, $staged);

        $before = $this->withSession($this->session($userId))
            ->get('records/import/review/' . $jobId . '/rows?severity=blocking');
        $beforeJson = json_decode((string) $before->response()->getBody(), true);
        $contact = array_values(array_filter($beforeJson['rows'], static fn (array $row): bool => (int) $row['sheetRow'] === 5))[0];

        $this->assertSame('blocking', $contact['fields'][0]['severity']);

        $after = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 5,
                'fields' => ['contactnumber' => ''],
            ]);
        $after->assertStatus(200);
        $afterJson = json_decode((string) $after->response()->getBody(), true);

        $this->assertSame('warning', $afterJson['row']['fields'][0]['severity']);
        $this->assertStringContainsString('not ready for an access card', $afterJson['row']['fields'][0]['message']);
    }

    public function testApplyNormalizesPostedValuesBeforeStaging(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 6,
                'fields'     => ['firstname' => 'ana  maria'],
            ]);

        $result->assertStatus(200);
        $this->assertSame('ANA MARIA', service('importStaging')->load($jobId)['rows'][3]['data']['firstname']);
    }

    public function testApplyPersistenceExceptionDoesNotWriteAnAuditRow(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);
        $this->failReviewPersistence('throw');

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 6,
                'fields'     => ['sex' => 'Female'],
            ]);

        $result->assertStatus(500);
        $this->assertSame(0, db_connect()->table('audit_trails')->where('user_action', 'SYSTEM_ERROR')->countAllResults());
    }

    /** @dataProvider failedReviewPersistence */
    public function testApplyReportsAndPreservesTheStageOnPersistenceFailure(string $failure): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);
        $before = service('importStaging')->load($jobId);
        $this->failReviewPersistence($failure);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 6,
                'fields'     => ['sex' => 'Female'],
            ]);

        $result->assertStatus(500);
        $this->assertSame($before, service('importStaging')->load($jobId));
    }

    /** @return array<string, array{string}> */
    public static function failedReviewPersistence(): array
    {
        return [
            'rows write fails'   => ['rows'],
            'errors write fails' => ['errors'],
        ];
    }

    public function testResolveDuplicateDiscardsTheOtherCandidateAndLogsIt(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/resolve-duplicate', ['keep_row' => 3]);

        $result->assertStatus(200);
        $staged = service('importStaging')->load($jobId);

        $this->assertSame(['keptRow' => 3, 'reason' => 'duplicate'], $staged['discarded'][4]);
        // The duplicate is resolved, while row 6's independent SEX error remains.
        $this->assertSame(1, $staged['counts']['blocking']);
        $this->assertSame([], array_values(array_filter($staged['errors'], static fn (array $error): bool => (int) $error['sheetRow'] === 4)));
        $this->assertSame('Discarded', $staged['changes'][0]['action']);
    }

    public function testRestoreMakesTheDiscardedDuplicateAnActiveBlockerAndLogsIt(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/resolve-duplicate', ['keep_row' => 3])
            ->assertStatus(200);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/restore', ['import_row' => 4]);

        $result->assertStatus(200);
        $staged = service('importStaging')->load($jobId);

        $this->assertArrayNotHasKey(4, $staged['discarded']);
        // Restoring the duplicate restores its two row-level blockers alongside SEX.
        $this->assertSame(3, $staged['counts']['blocking']);
        $this->assertNotSame([], array_values(array_filter($staged['errors'], static fn (array $error): bool => (int) $error['sheetRow'] === 4 && $error['code'] === 'DUP-ROW')));
        $this->assertSame('Restored', $staged['changes'][1]['action']);
    }

    public function testDuplicateEndpointsRefuseAnotherUsersJobWithoutMutatingIt(): void
    {
        $owner  = $this->encoder();
        $other  = $this->encoder();
        $jobId  = $this->stageJob($owner);
        $before = service('importStaging')->load($jobId);

        foreach ([
            ['resolve-duplicate', ['keep_row' => 3]],
            ['restore', ['import_row' => 4]],
        ] as [$endpoint, $post]) {
            $result = $this->withSession($this->session($other))
                ->post('records/import/review/' . $jobId . '/' . $endpoint, $post);

            $result->assertStatus(404);
        }

        $this->assertSame($before, service('importStaging')->load($jobId));
    }

    public function testResolveDuplicateRejectsANonCandidateWithoutMutatingIt(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);
        $before = service('importStaging')->load($jobId);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/resolve-duplicate', ['keep_row' => 6]);

        $result->assertStatus(422);
        $this->assertSame($before, service('importStaging')->load($jobId));
    }

    public function testApplyRejectsAFieldThatIsNotAnImporterField(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        $before = service('importStaging')->load($jobId);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 6,
                'fields'     => ['password' => 'x'],
            ]);

        $result->assertStatus(422);

        // Refusing outright, not skipping the bad key: nothing may be written.
        $this->assertSame($before['rows'], service('importStaging')->load($jobId)['rows']);
    }

    public function testApplyRejectsAnUnknownSheetRow(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 99999,
                'fields'     => ['sex' => 'Female'],
            ]);

        $result->assertStatus(422);
    }

    public function testApplyAsksForARefreshWhenItTouchesACrossRowField(): void
    {
        // familyno / relationship / address / barangay drive rules that reach other
        // rows, so the client must refetch instead of splicing the one row back in.
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 6,
                'fields'     => ['relationship' => 'Head'],
            ]);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertTrue($json['refresh']);
    }

    public function testApplyDoesNotAskForARefreshOnAnOrdinaryField(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 6,
                'fields'     => ['sex' => 'Female'],
            ]);

        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertFalse($json['refresh']);
    }

    public function testApplyReturns404WhenTheStagingFileIsGone(): void
    {
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        service('importStaging')->delete($jobId);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 6,
                'fields'     => ['sex' => 'Female'],
            ]);

        $result->assertStatus(404);
    }

    /**
     * A job staged by one user must be invisible to another, on every review endpoint -
     * the review bundle carries full family PII (names, birthdays, addresses). The HTML
     * page redirects to records with the same flash error a missing job gets; the JSON
     * endpoints (tested above) answer 404 rather than 403, so neither response can be
     * used to confirm another operator's job ID exists.
     */
    public function testReviewPageRefusesAJobStagedByAnotherUser(): void
    {
        $owner  = $this->encoder();
        $other  = $this->encoder();
        $jobId  = $this->stageJob($owner);

        $result = $this->withSession($this->session($other))
            ->get('records/import/review/' . $jobId);

        $result->assertRedirectTo(site_url('records'));
        $this->assertSame('That import is no longer available to review.', session('error'));
    }

    public function testReviewPageStillWorksForTheOwner(): void
    {
        $owner = $this->encoder();
        $jobId = $this->stageJob($owner);

        $result = $this->withSession($this->session($owner))
            ->get('records/import/review/' . $jobId);

        $result->assertStatus(200);
    }

    public function testRowsRefusesAJobStagedByAnotherUser(): void
    {
        $owner = $this->encoder();
        $other = $this->encoder();
        $jobId = $this->stageJob($owner);

        $result = $this->withSession($this->session($other))
            ->get('records/import/review/' . $jobId . '/rows');

        $result->assertStatus(404);
        $this->assertStringContainsString('no longer available', (string) $result->response()->getBody());
    }

    public function testApplyRefusesAJobStagedByAnotherUser(): void
    {
        $owner = $this->encoder();
        $other = $this->encoder();
        $jobId = $this->stageJob($owner);

        $result = $this->withSession($this->session($other))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 6,
                'fields'     => ['sex' => 'Female'],
            ]);

        $result->assertStatus(404);

        // The other user's request must not have touched the owner's staged rows.
        $staged = service('importStaging')->load($jobId);
        $this->assertSame('Mail', $staged['rows'][3]['data']['sex']);
    }

    public function testCommitRefusesAJobStagedByAnotherUser(): void
    {
        $owner = $this->encoder();
        $other = $this->encoder();
        $jobId = $this->stageJob($owner);

        $result = $this->withSession($this->session($other))
            ->post('records/import/review/' . $jobId . '/commit');

        $result->assertStatus(404);

        // Refused before it could enqueue a write job or flip the review job's phase.
        $job = db_connect()->table('job_queue')->where('jobID', $jobId)->get()->getRowArray();
        $this->assertSame('done', $job['status']);
    }

    public function testCancelRefusesAJobStagedByAnotherUser(): void
    {
        $owner = $this->encoder();
        $other = $this->encoder();
        $jobId = $this->stageJob($owner);

        $result = $this->withSession($this->session($other))
            ->post('records/import/review/' . $jobId . '/cancel');

        $result->assertStatus(200);

        // Silently no-ops rather than confirming the job exists: the staging file and
        // the owner's job status are both left untouched.
        $this->assertNotNull(service('importStaging')->load($jobId));
        $job = db_connect()->table('job_queue')->where('jobID', $jobId)->get()->getRowArray();
        $this->assertSame('done', $job['status']);
    }

    public function testApplyStillValidatesAfterANonInvalidatingEditReusesTheCache(): void
    {
        // A smoke check that the cached path produces a usable report end to end. The
        // claim the cache actually rests on - that nothing but familyno and lastname can
        // stale the lookups - is pinned without a database in
        // FamilyExcelImporterTest::testLookupKeysDeriveOnlyFromQrAndLastname().
        $userId = $this->encoder();
        $jobId  = $this->stageJob($userId);

        $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 6, 'fields' => ['sex' => 'Female'],
            ]);

        $result = $this->withSession($this->session($userId))
            ->post('records/import/review/' . $jobId . '/apply', [
                'import_row' => 6, 'fields' => ['birthday' => '02-02-2011'],
            ]);

        $result->assertStatus(200);
        $json = json_decode((string) $result->response()->getBody(), true);

        $this->assertSame(2, $json['counts']['blocking']);
    }
}
