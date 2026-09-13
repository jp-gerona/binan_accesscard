<?php

namespace App\Controllers\Families;

use App\Controllers\BaseController;
use App\Libraries\DashboardPageBuilder;
use App\Libraries\FamilyExcelImporter;
use App\Libraries\FamilyExcelTemplate;
use App\Libraries\ImportLookupCache;
use App\Libraries\ImportReviewChangeLog;
use App\Libraries\ImportReviewPresenter;
use App\Libraries\ImportReviewQuery;
use App\Libraries\ImportReviewResolution;
use App\Libraries\RoleAccess;
use App\Models\Families\MemberModel;
use App\Models\Families\FamilyFormOptionsModel;
use App\Models\Jobs\JobQueueModel;
use CodeIgniter\HTTP\RedirectResponse;
use Config\IdleTimeout;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * Excel import side of Manage Family: template download, import form,
 * import submission (queued as a `family_import` job), and status polling.
 * Split out of FamilyController; the importStatus() JSON payload shape is a
 * frontend contract (family-import.js polling) and must not change.
 */
class FamilyImportController extends BaseController
{
    use FamilyRequestContext;

    /**
     * GET `records/template`: streams the blank, fillable
     * .xlsx template (App\Libraries\FamilyExcelTemplate) workers use to collect
     * family records offline. Same entry-access guard as the Add form.
     */
    public function downloadTemplate()
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $spreadsheet = (new FamilyExcelTemplate())->build();

        ob_start();
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);
        $writer->save('php://output');
        $content = (string) ob_get_clean();

        return $this->response
            ->setHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->setHeader('Content-Disposition', 'attachment; filename="family-import-template-v4.xlsx"')
            ->setHeader('Cache-Control', 'max-age=0')
            ->setBody($content);
    }

    /**
     * GET `records/import`: step 1 of the import wizard, the upload page. The upload
     * still posts over AJAX (family-import.js), so the page reports queueing and
     * staging progress without navigating away.
     */
    public function importForm(): string|RedirectResponse
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        return view('layout', DashboardPageBuilder::shellAccountData() + [
            'activePage' => 'records-import',
            'role'       => RoleAccess::normalizeRole((string) session()->get('role')),
            'bodyView'   => 'Family/import-upload',
            'bodyData'   => [
                'action'      => site_url('records/import'),
                'templateUrl' => site_url('records/template'),
            ],
        ]);
    }

    /**
     * POST `records/import`: QUEUES a filled .xlsx of
     * families for background import. The file is only validated as an upload here
     * (a valid .xlsx), moved to writable/uploads, and recorded as a `pending`
     * job_queue row (type 'family_import'). A scheduled worker (php spark queue:work) parses,
     * validates, and writes it batched + resumably, so very large files never hit the
     * web request's timeout or memory limit. The modal polls importStatus() for the
     * row errors and final summary. Always responds as JSON (the modal posts over AJAX).
     */
    public function import()
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $this->jsonError('You do not have permission to import family records.', 403);
        }

        $memberModel = new MemberModel();

        if (! $memberModel->hasRequiredFamilyTables()) {
            return $this->jsonError('The accesscard database is missing required tables. Import the newest accesscardV*.sql from the project root.', 422);
        }

        $file = $this->request->getFile('import_file');

        if ($file === null || ! $file->isValid()) {
            return $this->jsonError('Please choose a valid .xlsx file to import.', 422);
        }

        // guessExtension() derives the extension server-side from the file's MIME
        // type, so a renamed .exe/.php can't pass as .xlsx (getClientExtension is
        // attacker-controlled). xlsx is a zip container, so allow the zip guess too.
        $guessedExtension = strtolower((string) $file->guessExtension());

        if (! in_array($guessedExtension, ['xlsx', 'zip'], true)) {
            return $this->jsonError('The file must be an .xlsx workbook saved from the template.', 422);
        }

        // Capture the original name before move() (which renames the file on disk).
        $originalName = (string) ($file->getClientName() ?: 'import.xlsx');

        // Durable name (not a temp the request deletes): the worker owns its lifecycle
        // and unlinks it once the job is done/failed.
        $storedName = 'family-import-' . bin2hex(random_bytes(8)) . '.xlsx';

        try {
            $file->move(WRITEPATH . 'uploads', $storedName, true);
        } catch (Throwable $exception) {
            return $this->jsonError('The uploaded file could not be saved for processing. Please try again.', 500);
        }

        $storedPath = WRITEPATH . 'uploads' . DIRECTORY_SEPARATOR . $storedName;

        $jobs = new JobQueueModel();

        if (! $jobs->hasTable()) {
            @unlink($storedPath);

            return $this->jsonError('The background job queue is unavailable (missing job_queue table, import the newest accesscardV*.sql).', 422);
        }

        try {
            $jobId = $jobs->enqueue(
                'family_import',
                ['phase' => 'review', 'storedPath' => $storedPath, 'originalName' => $originalName],
                (int) session()->get('user_id'),
                $this->request->getIPAddress(),
                $this->request->getUserAgent()->getAgentString()
            );
        } catch (Throwable $exception) {
            @unlink($storedPath);
            $this->auditSystemError('queueing a family Excel import', $exception);

            return $this->jsonError('The import could not be queued. Please try again.', 500);
        }

        // One operator, one open review: uploading a file retires any review they left
        // staged. Typical flow is upload -> read the errors -> fix the .xlsx -> upload
        // again; without this the first upload's staging file (family PII, up to several
        // MB) is orphaned on disk with nothing to ever delete it. Runs after the enqueue
        // so a failed enqueue leaves the previous review intact.
        $this->retirePreviousReviews($jobs, (int) session()->get('user_id'), $jobId);

        return $this->response->setJSON([
            'status'    => 'queued',
            'jobID'     => $jobId,
            'statusUrl' => site_url($this->currentRouteBase() . '/import/status/' . $jobId),
            'message'   => 'Your file is queued and being checked for problems.',
            'csrf'      => csrf_hash(),
        ]);
    }

    /**
     * Discards the staging files of this user's earlier, never-committed reviews and marks
     * those jobs terminal, so they can no longer be re-opened or committed.
     *
     * $keepJobId is the upload that just queued - it is still `pending`, so it is never
     * returned as a staged review, but it is excluded explicitly all the same.
     */
    private function retirePreviousReviews(JobQueueModel $jobs, int $userId, int $keepJobId): void
    {
        $store = service('importStaging');

        foreach ($jobs->stagedReviewIds($userId) as $priorId) {
            if ($priorId === $keepJobId) {
                continue;
            }

            $store->delete($priorId);
            $jobs->finish($priorId, 'failed', 'Replaced by a newer upload.', (string) json_encode(['phase' => 'superseded']));
        }
    }

    /**
     * GET `records/import/status/(:num)`: JSON progress for a
     * queued import job, polled by family-import.js. Returns the job's status, a human
     * message, progress counters, and (once finished) any validation/per-family write
     * errors so the modal can render them exactly as the old synchronous flow did.
     */
    public function importStatus(int $jobId)
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $this->jsonError('You do not have permission to view import status.', 403);
        }

        $jobs = new JobQueueModel();

        if (! $jobs->hasTable()) {
            return $this->jsonError('No import is in progress.', 404);
        }

        $job = $jobs->find($jobId);

        if ($job === null || ($job['type'] ?? '') !== 'family_import') {
            return $this->jsonError('Import job not found.', 404);
        }

        $status = (string) $job['status'];
        $total  = (int) $job['progress_total'];
        $done   = (int) $job['progress_done'];

        $result = [];

        if (! empty($job['result_json'])) {
            $decoded = json_decode((string) $job['result_json'], true);

            if (is_array($decoded)) {
                $result = $decoded;
            }
        }

        $errors   = (isset($result['errors']) && is_array($result['errors'])) ? $result['errors'] : [];
        $imported = (int) ($result['imported'] ?? 0);
        $failed   = (int) ($result['failed'] ?? 0);
        $skipped  = (int) ($result['skipped'] ?? 0);
        $members  = (int) ($result['members'] ?? 0);

        // Review-phase jobs stage for the operator to inspect; write-phase jobs persist.
        $phase    = (string) ($result['phase'] ?? 'write');
        $finished = in_array($status, ['done', 'partial', 'failed'], true);
        $reviewUrl = ($phase === 'review' && $finished && $status !== 'failed')
            ? site_url($this->currentRouteBase() . '/import/review/' . $jobId)
            : null;

        return $this->response->setJSON([
            'status'    => $status,
            'phase'     => $phase,
            'finished'  => $finished,
            'reviewUrl' => $reviewUrl,
            'message'   => (string) ($job['message'] ?? ''),
            'progress' => [
                'total'     => $total,
                'processed' => $done,
                'imported'  => $imported,
                'failed'    => $failed,
                'skipped'   => $skipped,
                'members'   => $members,
                'percent'   => $total > 0 ? (int) floor($done * 100 / $total) : 0,
            ],
            // The review page is the uncapped surface; this list only feeds the toast.
            'errors'   => array_slice($errors, 0, 500),
            'summary'  => [
                'families' => $imported,
                'members'  => $members,
            ],
            'csrf'     => csrf_hash(),
        ]);
    }

    /**
     * GET `records/import/review/(:num)`: step 2 of the import wizard, the review table
     * for a staged job - one row per person, flagged values fixed inline before the
     * import is confirmed.
     */
    public function reviewPage(int $jobId): string|RedirectResponse
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $guard;
        }

        $jobs = new JobQueueModel();
        $loaded = $jobs->hasTable() ? $this->loadReviewJob($jobs, $jobId) : null;

        if ($loaded === null) {
            return redirect()->to($this->recordsUrl())
                ->with('error', 'That import is no longer available to review.');
        }

        return view('layout', DashboardPageBuilder::shellAccountData() + [
            'activePage' => 'records-import',
            'role'       => RoleAccess::normalizeRole((string) session()->get('role')),
            'bodyView'   => 'Family/import-review',
            'bodyData'   => [
                'jobId'   => $jobId,
                'summary' => (new ImportReviewPresenter())->build($loaded['result']),
                'fieldOptions' => $this->reviewFieldOptions(),
            ],
            'idleTimeoutSeconds' => (new IdleTimeout())->seconds,
        ]);
    }

    /**
     * Review-only controls use the active reference rows and the manual form's option
     * sources. The importer still revalidates every posted value before it is staged.
     */
    private function reviewFieldOptions(): array
    {
        $formOptions = new FamilyFormOptionsModel();
        $options     = $formOptions->getOptions();
        $static      = $formOptions->staticOptionLists();
        $references  = static function (array $rows): array {
            $out = [];

            foreach ($rows as $row) {
                $code = trim((string) ($row['shortcode'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));

                if ($code === '') {
                    continue;
                }

                $out[] = [
                    'value' => $code,
                    'label' => $name === '' ? $code : $code . ' - ' . $name,
                ];
            }

            return $out;
        };

        return [
            'relationship'  => array_merge(['HEAD'], (array) ($static['relationshipOptions'] ?? [])),
            'suffix'        => (array) ($static['suffixOptions'] ?? []),
            'sex'           => (array) ($static['sexOptions'] ?? []),
            'civilstatus'   => (array) ($static['civilOptions'] ?? []),
            'religion'      => (array) ($static['religionOptions'] ?? []),
            'education'     => (array) ($static['educationOptions'] ?? []),
            'job'           => (array) ($static['jobOptions'] ?? []),
            'monthlyincome' => (array) ($static['incomeOptions'] ?? []),
            'barangay'      => (array) ($static['barangayOptions'] ?? []),
            'sector'        => $references((array) ($options['sectors'] ?? [])),
            'services'      => $references((array) ($options['services'] ?? [])),
        ];
    }

    /**
     * GET `records/import/review/(:num)/rows`: one page of the review table as JSON.
     *
     * The review screen asks for a slice at a time rather than carrying every staged
     * person in its HTML, so a 10,000-row import renders as fast as a 10-row one.
     *
     * GET: page, per (25/50/100), severity (all/problems/blocking/warning), code, q.
     */
    public function reviewRows(int $jobId)
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $this->jsonError('You do not have permission to review family imports.', 403);
        }

        $jobs   = new JobQueueModel();
        $loaded = $jobs->hasTable() ? $this->loadReviewJob($jobs, $jobId) : null;

        if ($loaded === null) {
            return $this->jsonError('That import is no longer available to review.', 404);
        }

        $query = ImportReviewQuery::fromArray($this->request->getGet());
        $page  = (new ImportReviewPresenter())->page($loaded['result'], $query);

        return $this->response->setJSON(['status' => 'success'] + $page);
    }

    /**
     * POST `records/import/review/(:num)/commit`: re-validates the
     * staged batch and, only when no blocking issues remain, queues the write job that
     * persists the families. Returns that job's status URL for the progress toast.
     *
     * Nothing is edited in the browser - the spreadsheet is the source of truth - so this
     * only ever confirms or refuses what was uploaded.
     */
    public function reviewCommit(int $jobId)
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $this->jsonError('You do not have permission to import family records.', 403);
        }

        $jobs = new JobQueueModel();
        $loaded = $jobs->hasTable() ? $this->loadReviewJob($jobs, $jobId) : null;

        if ($loaded === null) {
            return $this->jsonError('That import is no longer available to review.', 404);
        }

        $rows   = is_array($loaded['result']['rows'] ?? null) ? $loaded['result']['rows'] : [];
        $result = $this->revalidateStaged($jobId, $loaded['result'], $rows, []);

        $blocking = (int) ($result['counts']['blocking'] ?? 0);

        if ($blocking > 0) {
            return $this->response->setStatusCode(422)->setJSON([
                'status'  => 'blocked',
                'message' => 'There ' . ($blocking === 1 ? 'is 1 issue' : 'are ' . $blocking . ' issues')
                    . ' to fix. Correct them in the spreadsheet and upload it again.',
                'review'  => (new ImportReviewPresenter())->build($result),
                'csrf'    => csrf_hash(),
            ]);
        }

        try {
            $writeJobId = $jobs->enqueue(
                'family_import',
                ['phase' => 'write', 'stageJobId' => $jobId, 'originalName' => (string) ($result['file'] ?? 'import.xlsx')],
                (int) session()->get('user_id'),
                $this->request->getIPAddress(),
                $this->request->getUserAgent()->getAgentString()
            );
        } catch (Throwable $exception) {
            $this->auditSystemError('queueing a reviewed family import', $exception);

            return $this->jsonError('The import could not be queued. Please try again.', 500);
        }

        // Flip the review job's phase so it can't be re-opened or committed twice
        // (loadReviewJob only accepts phase 'review'). The staging file stays until the
        // write job consumes it.
        $jobs->finish($jobId, 'done', 'Reviewed and imported.', (string) json_encode([
            'phase'  => 'committed',
            'file'   => (string) ($result['file'] ?? 'import.xlsx'),
            'counts' => $result['counts'] ?? [],
        ]));

        return $this->response->setJSON([
            'status'     => 'queued',
            'jobID'      => $writeJobId,
            'statusUrl'  => site_url($this->currentRouteBase() . '/import/status/' . $writeJobId),
            'redirect'   => $this->recordsUrl(),
            'message'    => 'Import started for ' . (int) ($result['counts']['families'] ?? 0) . ' family group(s).',
            'csrf'       => csrf_hash(),
        ]);
    }

    /**
     * POST `records/import/review/(:num)/cancel`: discards a
     * staged import without writing anything.
     */
    public function reviewCancel(int $jobId)
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $this->jsonError('You do not have permission to cancel this import.', 403);
        }

        $jobs = new JobQueueModel();
        $loaded = $jobs->hasTable() ? $this->loadReviewJob($jobs, $jobId) : null;

        if ($loaded !== null) {
            service('importStaging')->delete($jobId);
            $jobs->finish($jobId, 'failed', 'Import cancelled during review.');
        }

        return $this->response->setJSON([
            'status'   => 'ok',
            'redirect' => $this->recordsUrl(),
            'csrf'     => csrf_hash(),
        ]);
    }

    /** Staged fields whose value can change duplicate detection or validation of OTHER rows. */
    private const CROSS_ROW_FIELDS = [
        'familyno', 'relationship', 'firstname', 'middlename', 'lastname', 'suffix',
        'birthday', 'address', 'barangay',
    ];

    /**
     * POST `records/import/review/(:num)/apply`: applies one person's corrections.
     *
     * Nothing is written until the operator presses Apply, so a mistyped fix can be
     * discarded without ever reaching staging. The response carries only the row that
     * changed and the fresh totals: returning the whole report is what made a fix on a
     * 10,000-row file slow.
     *
     * POST: import_row (sheet row, int), fields[<field>] => value for any subset of
     * ImportReviewPresenter::FIELD_LABELS.
     */
    public function reviewRowApply(int $jobId)
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $this->jsonError('You do not have permission to edit import records.', 403);
        }

        $jobs   = new JobQueueModel();
        $loaded = $jobs->hasTable() ? $this->loadReviewJob($jobs, $jobId) : null;

        if ($loaded === null) {
            return $this->jsonError('That import is no longer available to review.', 404);
        }

        $sheetRow = (int) ($this->request->getPost('import_row') ?? 0);
        $posted   = $this->request->getPost('fields');
        $posted   = is_array($posted) ? $posted : [];

        if ($sheetRow <= 0 || $posted === []) {
            return $this->jsonError('There is nothing to apply.', 422);
        }

        // Only the importer's known fields may be patched. An unknown name is refused
        // outright rather than skipped, so a typo cannot silently apply half an edit.
        foreach (array_keys($posted) as $field) {
            if (! isset(ImportReviewPresenter::FIELD_LABELS[(string) $field])) {
                return $this->jsonError('That field cannot be edited.', 422);
            }
        }

        $bundle   = $loaded['result'];
        $previous = $bundle;
        $rows     = is_array($bundle['rows'] ?? null) ? $bundle['rows'] : [];

        $index = null;

        foreach ($rows as $key => $row) {
            if ((int) ($row['sheetRow'] ?? -1) === $sheetRow) {
                $index = $key;
                break;
            }
        }

        if ($index === null) {
            return $this->jsonError('That row is not part of this import.', 422);
        }

        $oldRow = $rows[$index];
        $data   = is_array($oldRow['data'] ?? null) ? $oldRow['data'] : [];

        foreach ($posted as $field => $value) {
            $data[(string) $field] = trim((string) $value);
        }

        // Apply uses the importer's canonical representation just like workbook staging,
        // so duplicate comparisons and what the operator sees never disagree on casing or
        // redundant whitespace.
        $normalized = (new FamilyExcelImporter())->normalizeRows([[
            'sheetRow' => $sheetRow,
            'data'     => $data,
        ]]);
        $data = $normalized[0]['data'];

        $rows[$index]['data'] = $data;
        $newRow               = $rows[$index];

        $fields = array_map('strval', array_keys($posted));
        $result = $this->revalidateStaged($jobId, $bundle, $rows, $fields);

        $result['changes'] = $this->appendChanges($bundle, ImportReviewChangeLog::edited([$oldRow], [$newRow]));

        try {
            $this->restageReview($jobId, $result, $previous);
        } catch (Throwable) {
            // Review edits are not committed records and must never add audit rows.
            return $this->jsonError('The correction could not be saved. Please try again.', 500);
        }

        $presenter = new ImportReviewPresenter();
        $summary   = $presenter->build($result);

        return $this->response->setJSON([
            'status'  => 'success',
            'message' => 'Applied.',
            'row'     => $presenter->row($result, $sheetRow),
            'counts'  => $summary['counts'],
            'codes'   => $summary['codes'],
            // These four drive rules that reach across rows, so the table cannot be
            // trusted to be current after them and the client refetches the page.
            'refresh' => array_intersect($fields, self::CROSS_ROW_FIELDS) !== [],
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * POST `records/import/review/(:num)/resolve-duplicate`: keeps the chosen duplicate
     * candidate active and discards its copies from this staged import.
     */
    public function reviewResolveDuplicate(int $jobId)
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $this->jsonError('You do not have permission to edit import records.', 403);
        }

        $jobs   = new JobQueueModel();
        $loaded = $jobs->hasTable() ? $this->loadReviewJob($jobs, $jobId) : null;

        if ($loaded === null) {
            return $this->jsonError('That import is no longer available to review.', 404);
        }

        $keepRow  = (int) ($this->request->getPost('keep_row') ?? 0);
        $bundle   = $loaded['result'];
        $previous = $bundle;
        $rows     = is_array($bundle['rows'] ?? null) ? $bundle['rows'] : [];
        $old     = is_array($bundle['discarded'] ?? null) ? $bundle['discarded'] : [];
        $groups  = is_array($bundle['duplicateGroups'] ?? null) ? $bundle['duplicateGroups'] : [];

        try {
            $discarded = ImportReviewResolution::discardGroup($old, $groups, $keepRow);
        } catch (\InvalidArgumentException) {
            return $this->jsonError('The selected row is not a duplicate candidate.', 422);
        }

        $changedRows = array_values(array_filter($rows, static function (array $row) use ($old, $discarded, $keepRow): bool {
            $sheetRow = (int) ($row['sheetRow'] ?? 0);

            return $sheetRow === $keepRow || (! isset($old[$sheetRow]) && isset($discarded[$sheetRow]));
        }));
        $bundle['discarded'] = $discarded;
        $result = $this->revalidateStaged($jobId, $bundle, $rows, []);
        $result['changes'] = $this->appendChanges($bundle, ImportReviewChangeLog::discarded($changedRows, $keepRow));

        try {
            $this->restageReview($jobId, $result, $previous);
        } catch (Throwable) {
            return $this->jsonError('The duplicate decision could not be saved. Please try again.', 500);
        }

        return $this->reviewResolutionResponse($result, 'Duplicate copies discarded.');
    }

    /** POST `records/import/review/(:num)/restore`: returns a duplicate copy to review. */
    public function reviewRestore(int $jobId)
    {
        $guard = $this->requireFamilyEntryAccess();

        if ($guard instanceof RedirectResponse) {
            return $this->jsonError('You do not have permission to edit import records.', 403);
        }

        $jobs   = new JobQueueModel();
        $loaded = $jobs->hasTable() ? $this->loadReviewJob($jobs, $jobId) : null;

        if ($loaded === null) {
            return $this->jsonError('That import is no longer available to review.', 404);
        }

        $sheetRow = (int) ($this->request->getPost('import_row') ?? 0);
        $bundle   = $loaded['result'];
        $previous = $bundle;
        $rows     = is_array($bundle['rows'] ?? null) ? $bundle['rows'] : [];
        $old      = is_array($bundle['discarded'] ?? null) ? $bundle['discarded'] : [];
        $discarded = ImportReviewResolution::restore($old, $sheetRow);

        $restoredRows = array_values(array_filter($rows, static function (array $row) use ($old, $discarded): bool {
            $rowNumber = (int) ($row['sheetRow'] ?? 0);

            return isset($old[$rowNumber]) && ! isset($discarded[$rowNumber]);
        }));
        $bundle['discarded'] = $discarded;
        $result = $this->revalidateStaged($jobId, $bundle, $rows, []);
        $entry  = $restoredRows === [] ? null : ImportReviewChangeLog::restored($restoredRows, $sheetRow);
        $result['changes'] = $this->appendChanges($bundle, $entry);

        try {
            $this->restageReview($jobId, $result, $previous);
        } catch (Throwable) {
            return $this->jsonError('The duplicate decision could not be saved. Please try again.', 500);
        }

        return $this->reviewResolutionResponse($result, 'Duplicate row restored.');
    }

    /** @return \CodeIgniter\HTTP\ResponseInterface */
    private function reviewResolutionResponse(array $result, string $message)
    {
        $summary = (new ImportReviewPresenter())->build($result);

        return $this->response->setJSON([
            'status'  => 'success',
            'message' => $message,
            'counts'  => $summary['counts'],
            'codes'   => $summary['codes'],
            'refresh' => true,
            'csrf'    => csrf_hash(),
        ]);
    }

    /**
     * Appends one change entry (skipping a null no-op) to the bundle's running change log,
     * newest last. Capped so a long review session can't grow the staging file unbounded.
     *
     * @return list<array>
     */
    private function appendChanges(array $bundle, ?array $entry): array
    {
        $changes = is_array($bundle['changes'] ?? null) ? $bundle['changes'] : [];

        if ($entry !== null) {
            $changes[] = $entry;
        }

        return array_slice($changes, -100);
    }

    /**
     * Persists a re-validated review result back to the job's staging file.
     *
     * Rewrites only the rows and errors/counts/changes files, not the whole ~7 MB
     * bundle (ImportStagingStore::save()): a one-field Apply must not re-encode
     * every staged row just to flip one flag.
     */
    private function restageReview(int $jobId, array $result, array $previous): void
    {
        $store = service('importStaging');
        $rows  = is_array($result['rows'] ?? null) ? $result['rows'] : [];

        if (! $store->saveRows($jobId, $rows)) {
            throw new \RuntimeException('The reviewed rows could not be saved.');
        }

        if ($store->saveErrors(
            $jobId,
            is_array($result['errors'] ?? null) ? $result['errors'] : [],
            is_array($result['counts'] ?? null) ? $result['counts'] : [],
            is_array($result['discarded'] ?? null) ? $result['discarded'] : [],
            is_array($result['duplicateGroups'] ?? null) ? $result['duplicateGroups'] : [],
            is_array($result['changes'] ?? null) ? $result['changes'] : [],
        )) {
            return;
        }

        // Rows and validation metadata occupy separate atomic files. A failed metadata
        // write can follow a successful rows write, so restore the prior bundle before
        // exposing the persistence error to the operator whenever the store permits it.
        $store->saveErrors(
            $jobId,
            is_array($previous['errors'] ?? null) ? $previous['errors'] : [],
            is_array($previous['counts'] ?? null) ? $previous['counts'] : [],
            is_array($previous['discarded'] ?? null) ? $previous['discarded'] : [],
            is_array($previous['duplicateGroups'] ?? null) ? $previous['duplicateGroups'] : [],
            is_array($previous['changes'] ?? null) ? $previous['changes'] : [],
        );
        $store->saveRows($jobId, is_array($previous['rows'] ?? null) ? $previous['rows'] : []);

        throw new \RuntimeException('The reviewed validation result could not be saved.');
    }

    /**
     * Loads a staged review job by ID, or null when it is missing, the wrong type, staged
     * by a different user, or no longer in the review phase (already committed /
     * cancelled).
     *
     * Ownership is the job_queue row's userID (the same column stagedReviewIds() already
     * keys on), not anything in the staging bundle. A mismatch returns null - the same
     * "not found" outcome as a missing job - so an operator probing another user's job ID
     * cannot tell it exists at all. Every review action shares this check; nobody gets a
     * supervisor override, because none of the surrounding code (or docs) relies on one and
     * a staged import holds full family PII.
     *
     * @return array{job: array, result: array}|null
     */
    private function loadReviewJob(JobQueueModel $jobs, int $jobId): ?array
    {
        $job = $jobs->find($jobId);

        if ($job === null || ($job['type'] ?? '') !== 'family_import') {
            return null;
        }

        if ((int) ($job['userID'] ?? 0) !== (int) session()->get('user_id')) {
            return null;
        }

        $summary = json_decode((string) ($job['result_json'] ?? ''), true);

        if (! is_array($summary) || ($summary['phase'] ?? '') !== 'review') {
            return null;
        }

        // The rows + errors live in the staging file, not the DB (they are too big).
        $bundle = service('importStaging')->load($jobId);

        if ($bundle === null) {
            return null;
        }

        return ['job' => $job, 'result' => $bundle];
    }


    /**
     * Re-runs validation over the staged rows and refreshes the counts.
     *
     * The two existing-record lookups are memoized per job (ImportLookupCache): they
     * are derived only from the file's QRs and lastnames, so an edit to any other
     * field reuses them. Without this, every correction on a 10,000-row file repeats
     * the heaviest two queries in the import path.
     *
     * @param list<string> $editedFields the fields this edit changed, [] to rebuild
     */
    private function revalidateStaged(int $jobId, array $result, array $rows, array $editedFields): array
    {
        $importer  = new FamilyExcelImporter();
        $cache     = new ImportLookupCache();
        $discarded = is_array($result['discarded'] ?? null) ? $result['discarded'] : [];
        $activeRows = ImportReviewResolution::activeRows($rows, $discarded);
        $rebuild   = $editedFields === [] || ImportLookupCache::invalidatedBy($editedFields);
        $lookups   = $cache->lookupsFor($jobId, $activeRows, $importer, $rebuild);

        $built      = $importer->validateAndBuild($activeRows, $lookups['heads'], $lookups['people']);
        $fileErrors = is_array($result['fileErrors'] ?? null) ? $result['fileErrors'] : [];
        $errors     = array_merge($fileErrors, $built['errors']);
        $counts     = $importer->summarize($built['families'], $errors, $built['appends']);

        $result['rows']            = $rows;
        $result['discarded']       = $discarded;
        $result['duplicateGroups'] = $built['duplicateGroups'];
        $result['errors']          = $errors;
        $result['counts']  = $counts;
        $result['members'] = (int) ($counts['members'] ?? 0);
        $result['phase']   = 'review';

        return $result;
    }
}
