<?php

namespace App\Jobs;

use App\Libraries\FamilyExcelImporter;
use App\Libraries\FamilyRecordWriteException;
use App\Libraries\FamilyRecordWriter;
use App\Libraries\ImportReviewResolution;
use App\Models\Audit\AuditTrailsModel;
use App\Models\Families\MemberModel;
use App\Models\Families\MemberServiceModel;
use App\Models\Lookups\ServiceModel;
use App\Models\Scanner\QrControlModel;
use Config\Database;
use Throwable;

/**
 * Background handler for the 'family_import' job type. Runs in TWO phases, chosen by
 * payload['phase']:
 *
 *   review  (default): parse + validate the uploaded .xlsx and STAGE the full row set +
 *           every error into the job's result_json. Writes nothing. The reviewer fixes
 *           values in the browser, then confirms.
 *   write:  persist the reviewed row set. Families are one-per-transaction, checkpointing
 *           every $batch families so the import stays off the web request's limits, allows
 *           partial success, and resumes from its checkpoint after a mid-job crash.
 *
 * A write job re-runs validateAndBuild over the reviewed rows (loaded from the review
 * job's staging file, referenced by payload['stageJobId']) and refuses to write a batch
 * that still has any blocking error.
 */
class FamilyImportJob implements JobHandlerInterface
{
    /** Families per checkpoint flush. */
    private int $batch = 50;

    public function handle(array $payload, array $job, JobReporter $reporter): JobOutcome
    {
        $phase = (string) ($payload['phase'] ?? 'review');

        return $phase === 'write'
            ? $this->handleWrite($payload, $job, $reporter)
            : $this->handleReview($payload, $job);
    }

    /**
     * Review phase. Parses and validates the uploaded file, then hands the whole
     * row set plus every error to ImportStagingStore, so the browser can show the
     * reviewer what needs fixing. A file that cannot be parsed at all fails here
     * and is never staged. Touches no family table.
     */
    private function handleReview(array $payload, array $job): JobOutcome
    {
        $path = (string) ($payload['storedPath'] ?? '');

        if ($path === '' || ! is_file($path)) {
            return JobOutcome::failed('The uploaded file is no longer available on the server.');
        }

        if (! (new MemberModel())->hasRequiredFamilyTables()) {
            $this->cleanup($path);

            return JobOutcome::failed('The database is missing required tables from accesscardV16.sql.');
        }

        try {
            $staged = (new FamilyExcelImporter())->stage($path);
        } catch (Throwable $e) {
            $this->cleanup($path);

            return JobOutcome::failed('The file could not be read as an .xlsx saved from the template.');
        }

        // Everything now lives in JSON; the uploaded file is no longer needed.
        $this->cleanup($path);

        $counts   = $staged['counts'];
        $families = (int) ($counts['families'] ?? 0);
        $blocking = (int) ($counts['blocking'] ?? 0);
        $fileName = (string) ($payload['originalName'] ?? 'import.xlsx');

        // TINY summary for job_queue.result_json. The staged rows themselves can be many
        // MB (a 10k-member import) and MUST NOT go in the DB column - that blob exceeds
        // MySQL's max_allowed_packet, the UPDATE fails (error 1153/2006), and the worker
        // crashes after parsing but before marking the job done (endless re-claim loop).
        $summary = [
            'phase'    => 'review',
            'file'     => $fileName,
            'counts'   => $counts,
            'imported' => 0,
            'failed'   => 0,
            'skipped'  => 0,
            'members'  => (int) ($counts['members'] ?? 0),
        ];

        // A hard parse failure (unreadable / wrong sheet / missing columns) has nothing
        // to review - surface it as a failed job with the single reason.
        if (! $staged['ok']) {
            return JobOutcome::failed((string) ($staged['errors'][0]['message'] ?? 'The file could not be imported.'), $summary);
        }

        // Stage the full row set + errors to a file under writable/; the review screen and
        // the eventual write job read it from there.
        $jobId = (int) ($job['jobID'] ?? 0);
        service('importStaging')->save($jobId, [
            'phase'      => 'review',
            'file'       => $fileName,
            'rows'       => $staged['rows'],
            'errors'     => $staged['errors'],
            'fileErrors' => $staged['fileErrors'] ?? [],
            // [field => Excel column letter] so the review can name the exact cell to fix.
            'columns'    => $staged['columns'] ?? [],
            'counts'          => $counts,
            'discarded'       => [],
            'duplicateGroups' => $staged['duplicateGroups'] ?? [],
            'changes'         => [],
        ]);

        $message = $blocking > 0
            ? 'Ready to review - ' . $families . ' family group(s), ' . $blocking . ' issue(s) to fix before importing.'
            : 'Ready to review - ' . $families . ' family group(s), no issues found. Confirm to import.';

        return JobOutcome::done($message, $summary);
    }

    /**
     * Write phase. Loads the reviewed rows from the review job's staging file and
     * re-validates them against the live tables rather than trusting the staged
     * snapshot, since another operator may have added one of these families in the
     * meantime. Refuses the whole batch while any blocking error remains. Each
     * family is its own transaction, so one bad family cannot take the rest with it.
     */
    private function handleWrite(array $payload, array $job, JobReporter $reporter): JobOutcome
    {
        $memberModel = new MemberModel();

        if (! $memberModel->hasRequiredFamilyTables()) {
            return JobOutcome::failed('The database is missing required tables from accesscardV16.sql.');
        }

        // Rows come from the review job's staging file (kept small in the DB, big on disk).
        $stageJobId = (int) ($payload['stageJobId'] ?? 0);
        $store      = service('importStaging');
        $bundle     = $store->load($stageJobId);

        if ($bundle === null) {
            return JobOutcome::failed('The reviewed import data is no longer available. Please upload the file again.');
        }

        $rows      = is_array($bundle['rows'] ?? null) ? $bundle['rows'] : [];
        $discarded = is_array($bundle['discarded'] ?? null) ? $bundle['discarded'] : [];
        $rows      = ImportReviewResolution::activeRows($rows, $discarded);
        $importer  = new FamilyExcelImporter();

        // Re-read the DB, don't trust the staged snapshot: another operator may have added
        // one of these families since the review, which turns a clean batch into a QR clash.
        $existingHeads  = $importer->existingHeadsForRows($rows);
        $existingPeople = $importer->existingPeopleForRows($rows);
        $built          = $importer->validateAndBuild($rows, $existingHeads, $existingPeople);
        $blocking       = (int) ($built['counts']['blocking'] ?? 0);

        // Defense in depth: never write a batch that still has blocking errors.
        if ($blocking > 0) {
            $store->delete($stageJobId);

            return JobOutcome::failed(
                'The import still has ' . $blocking . ' issue(s) to fix. Correct them in the spreadsheet and upload it again.',
                ['imported' => 0, 'failed' => 0, 'skipped' => 0, 'members' => 0, 'errors' => array_slice(array_values($built['errors']), 0, 300)],
            );
        }

        $families = $built['families'];
        $total    = count($families);
        $reporter->setTotal($total);

        // Resume support: continue from the checkpoint, re-loading the counters + error
        // list a crashed run already stored on the job.
        $start   = max(0, (int) ($job['checkpoint'] ?? 0));
        $prior   = $this->decode($job['result_json'] ?? null);
        $done    = (int) ($prior['imported'] ?? 0);
        $failed  = (int) ($prior['failed'] ?? 0);
        $skipped = (int) ($prior['skipped'] ?? 0);
        $members  = (int) ($prior['members'] ?? 0);
        $appended = (int) ($prior['appended'] ?? 0);
        $errors   = (isset($prior['errors']) && is_array($prior['errors'])) ? $prior['errors'] : [];

        $db     = Database::connect();
        $userId = (int) ($job['userID'] ?? 0);
        $ip     = $job['ip_address'] ?? null;
        $ua     = $job['user_agent'] ?? null;

        $writer         = new FamilyRecordWriter($memberModel, new MemberServiceModel(), new ServiceModel(), new AuditTrailsModel());
        $qrControlModel = new QrControlModel();

        // By-reference capture so each checkpoint reads the LIVE counters/errors.
        // Cap stored errors so result_json stays well under max_allowed_packet even when
        // thousands of families are skipped/failed (each checkpoint writes this snapshot).
        $snapshot = function () use (&$done, &$failed, &$skipped, &$members, &$appended, &$errors): array {
            return [
                'phase'    => 'write',
                'imported' => $done,
                'failed'   => $failed,
                'skipped'  => $skipped,
                'members'  => $members,
                'appended' => $appended,
                'errors'   => array_slice(array_values($errors), 0, 300),
            ];
        };

        $reporter->checkpoint($done + $failed + $skipped, $start, $snapshot());

        for ($i = $start; $i < $total; $i++) {
            $family = $families[$i];
            $head   = $family['headPayload'] ?? [];

            // Skip families that already exist rather than inserting a duplicate.
            if ($memberModel->activeHeadExists(
                (string) ($head['firstname'] ?? ''),
                (string) ($head['lastname'] ?? ''),
                $head['birthday'] ?? null,
            )) {
                $skipped++;
                $headName = trim(((string) ($head['firstname'] ?? '')) . ' ' . ((string) ($head['lastname'] ?? '')));
                $errors[] = [
                    'sheetRow' => null,
                    'familyNo' => (string) ($family['familyNo'] ?? ''),
                    'message'  => 'Skipped - a family for ' . ($headName !== '' ? $headName : 'this head') . ' already exists.',
                ];

                if ((($i - $start + 1) % $this->batch) === 0) {
                    $reporter->checkpoint($done + $failed + $skipped, $i + 1, $snapshot());
                    $reporter->pause();
                }

                continue;
            }

            // A QR number already mapped to another family fails fast with a clear
            // message; the qr_control primary key stays as the race-safe backstop.
            $controlNo = (int) ($family['familyNo'] ?? 0);

            if ($controlNo > 0 && $qrControlModel->takenByOtherHead($controlNo, 0)) {
                $failed++;
                $errors[] = [
                    'sheetRow' => null,
                    'familyNo' => (string) ($family['familyNo'] ?? ''),
                    'message'  => 'QR Number ' . $controlNo . ' is already assigned to another family.',
                ];

                if ((($i - $start + 1) % $this->batch) === 0) {
                    $reporter->checkpoint($done + $failed + $skipped, $i + 1, $snapshot());
                    $reporter->pause();
                }

                continue;
            }

            // One family = one transaction: a single bad family is isolated.
            $db->transBegin();

            try {
                $writer->persistFamily(
                    $family['headPayload'],
                    $family['memberPayloads'],
                    $family['headServiceIds'],
                    $userId,
                    $ip,
                    $ua,
                    ' via Excel import (queued)',
                    $controlNo,
                );

                if ($db->transStatus() === false) {
                    throw new FamilyRecordWriteException('The database rejected the family.');
                }

                $db->transCommit();
                $done++;
                $members += count($family['memberPayloads']);
            } catch (Throwable $e) {
                $db->transRollback();
                $failed++;
                $errors[] = [
                    'sheetRow' => null,
                    'familyNo' => (string) ($family['familyNo'] ?? ''),
                    'message'  => 'Could not save family ' . ($family['familyNo'] ?? '') . ': ' . $e->getMessage(),
                ];
            }

            if ((($i - $start + 1) % $this->batch) === 0) {
                $reporter->checkpoint($done + $failed + $skipped, $i + 1, $snapshot());
                $reporter->pause();
            }
        }

        $reporter->checkpoint($done + $failed + $skipped, $total, $snapshot());

        // Add the members whose QR already belongs to a family (the forgotten-member case).
        // These are listed in the review; to skip one, the operator deletes the row from
        // the spreadsheet and uploads again.
        foreach ($built['appends'] as $append) {
            $qr      = (int) ($append['qr'] ?? 0);
            $payload = is_array($append['payload'] ?? null) ? $append['payload'] : [];
            $headId  = $qr > 0 ? $qrControlModel->headForControl($qr) : null;

            if ($headId === null || $headId <= 0) {
                $failed++;
                $errors[] = ['sheetRow' => $append['sheetRow'] ?? null, 'familyNo' => (string) $qr, 'message' => 'Could not add a member - family ' . $qr . ' no longer exists.'];
                continue;
            }

            if ($memberModel->memberExistsUnderHead($headId, (string) ($payload['firstname'] ?? ''), (string) ($payload['lastname'] ?? ''), $payload['birthday'] ?? null)) {
                $skipped++;
                $errors[] = ['sheetRow' => $append['sheetRow'] ?? null, 'familyNo' => (string) $qr, 'message' => 'Skipped - that member is already in family ' . $qr . '.'];
                continue;
            }

            $db->transBegin();

            try {
                $writer->appendMember($headId, $payload, $append['serviceIds'] ?? [], $userId, $ip, $ua, ' via Excel import (queued)');

                if ($db->transStatus() === false) {
                    throw new FamilyRecordWriteException('The database rejected the added member.');
                }

                $db->transCommit();
                $appended++;
            } catch (Throwable $e) {
                $db->transRollback();
                $failed++;
                $errors[] = ['sheetRow' => $append['sheetRow'] ?? null, 'familyNo' => (string) $qr, 'message' => 'Could not add a member to family ' . $qr . ': ' . $e->getMessage()];
            }
        }

        $reporter->checkpoint($done + $failed + $skipped, $total, $snapshot());

        // The batch is fully processed - the staging file is no longer needed.
        $store->delete($stageJobId);

        $skipNote   = $skipped > 0 ? ' Skipped ' . $skipped . ' already on file.' : '';
        $appendNote = $appended > 0 ? ' Added ' . $appended . ' member(s) to existing families.' : '';

        if ($failed === 0) {
            return JobOutcome::done(
                'Imported ' . $done . ' family record(s) and ' . $members . ' additional member(s).' . $appendNote . $skipNote,
                $snapshot(),
            );
        }

        $message = 'Imported ' . $done . ' of ' . $total . ' family record(s); ' . $failed . ' could not be saved.' . $appendNote . $skipNote;

        return ($done > 0 || $appended > 0)
            ? JobOutcome::partial($message, $snapshot())
            : JobOutcome::failed($message, $snapshot());
    }

    /** @return array<string, mixed> */
    private function decode(?string $json): array
    {
        if ($json === null || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function cleanup(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }
}
