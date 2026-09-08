<?php

namespace App\Models\Jobs;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Persistence for the generic background job queue (`job_queue`).
 *
 * Any long/heavy task can be enqueued here instead of running inside a web
 * request: the request writes a `pending` row (a `type` + a JSON `payload`) and
 * returns immediately; a scheduled worker (`php spark queue:work`, fired every
 * minute by scripts/queue-worker.ps1) claims it and dispatches to the handler
 * registered for that type in Config\Queue. The family Excel import is the first
 * such job type (App\Jobs\FamilyImportJob); add more by registering a handler.
 *
 * Status lifecycle: pending → processing → done | partial | failed.
 *
 * No CI4 migrations exist in this project - the `job_queue` table ships in the
 * newest accesscardV*.sql schema dump; sql/job_queue.sql holds the standalone
 * DDL for reference. Callers guard on hasTable() rather than creating it at
 * runtime.
 */
class JobQueueModel
{
    private BaseConnection $db;

    /** A `processing` row untouched this long is treated as a crashed run and is
     *  re-claimed (handlers resume from their checkpoint). */
    public const STALE_MINUTES = 15;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function hasTable(): bool
    {
        return $this->db->tableExists('job_queue');
    }

    /**
     * Enqueues a job and returns its jobID.
     *
     * @param string               $type       handler key registered in Config\Queue::$handlers
     * @param array<string, mixed> $payload    handler-specific input (JSON-encoded)
     */
    public function enqueue(string $type, array $payload, int $userId = 0, ?string $ip = null, ?string $userAgent = null, int $maxAttempts = 1): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->table('job_queue')->insert([
            'type'         => mb_substr($type, 0, 64),
            'payload'      => json_encode($payload),
            'status'       => 'pending',
            'userID'       => $userId > 0 ? $userId : null,
            'ip_address'   => $ip,
            'user_agent'   => $userAgent !== null ? mb_substr($userAgent, 0, 255) : null,
            'max_attempts' => max(1, $maxAttempts),
            'available_at' => $now,
            'dt_created'   => $now,
        ]);

        return (int) $this->db->insertID();
    }

    public function find(int $jobId): ?array
    {
        $row = $this->db->table('job_queue')->where('jobID', $jobId)->get()->getRowArray();

        return $row ?: null;
    }

    /**
     * Atomically claims the oldest runnable job (a due `pending`, or a stale
     * `processing` to resume after a crash) and returns it, or null when nothing is
     * claimable. The claim is a single UPDATE stamped with a unique token, so it is
     * safe for several drainers to run at once - only one can flip a given row. Each
     * claim is short (a single family transaction), keeping row locks off other users.
     *
     * @param string $worker label of the claiming drainer (for locked_by/debugging)
     */
    public function claimNext(string $worker = 'worker'): ?array
    {
        $now         = date('Y-m-d H:i:s');
        $staleBefore = date('Y-m-d H:i:s', time() - self::STALE_MINUTES * 60);
        $token       = $worker . '-' . bin2hex(random_bytes(6)) . '-' . getmypid();

        // Single-statement claim. The inner SELECT is wrapped in a derived table so
        // MySQL allows referencing job_queue in the UPDATE's own subquery.
        $this->db->query(
            'UPDATE `job_queue`
                SET `status` = ?, `locked_at` = ?, `locked_by` = ?, `attempts` = `attempts` + 1,
                    `dt_started` = COALESCE(`dt_started`, ?)
              WHERE `jobID` = (
                  SELECT `jobID` FROM (
                      SELECT `jobID` FROM `job_queue`
                       WHERE (`status` = ? AND (`available_at` IS NULL OR `available_at` <= ?))
                          OR (`status` = ? AND `locked_at` < ?)
                       ORDER BY `jobID` ASC
                       LIMIT 1
                  ) AS pick
              )',
            ['processing', $now, $token, $now, 'pending', $now, 'processing', $staleBefore]
        );

        if ($this->db->affectedRows() < 1) {
            return null;
        }

        $row = $this->db->table('job_queue')
            ->where('locked_by', $token)
            ->where('status', 'processing')
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    /** Handler-side: declare the unit total so the UI has a denominator. */
    public function setTotal(int $jobId, int $total): void
    {
        $this->db->table('job_queue')->where('jobID', $jobId)->update([
            'progress_total' => $total,
            'locked_at'      => date('Y-m-d H:i:s'),
        ]);
    }

    /** Handler-side: persist mid-job progress + checkpoint (and optional result JSON). */
    public function progress(int $jobId, int $done, int $checkpoint, ?string $resultJson = null): void
    {
        $data = [
            'progress_done' => $done,
            'checkpoint'    => $checkpoint,
            'locked_at'     => date('Y-m-d H:i:s'),
        ];

        if ($resultJson !== null) {
            $data['result_json'] = $resultJson;
        }

        $this->db->table('job_queue')->where('jobID', $jobId)->update($data);
    }

    /** Marks a job terminal (done|partial|failed) with a summary + optional result. */
    public function finish(int $jobId, string $status, string $message, ?string $resultJson = null): void
    {
        $data = [
            'status'      => $status,
            'message'     => mb_substr($message, 0, 500),
            'locked_at'   => null,
            'dt_finished' => date('Y-m-d H:i:s'),
        ];

        if ($resultJson !== null) {
            $data['result_json'] = $resultJson;
        }

        $this->db->table('job_queue')->where('jobID', $jobId)->update($data);
    }

    /** Requeues a job for a later retry after a recoverable failure. */
    public function retry(int $jobId, int $delaySeconds, string $message): void
    {
        $this->db->table('job_queue')->where('jobID', $jobId)->update([
            'status'       => 'pending',
            'message'      => mb_substr($message, 0, 500),
            'locked_at'    => null,
            'available_at' => date('Y-m-d H:i:s', time() + max(0, $delaySeconds)),
        ]);
    }

    /** True when a job of this type is still pending or being processed. */
    public function hasActiveType(string $type): bool
    {
        if ($type === '') {
            return false;
        }

        return $this->db->table('job_queue')
            ->where('type', $type)
            ->whereIn('status', ['pending', 'processing'])
            ->countAllResults() > 0;
    }

    /**
     * Enqueues a job only when no job of this type is pending or processing.
     * Returns the new jobID, or null when an active job already exists. Used by
     * the media reconcile producer so an every-minute scheduler fire never stacks
     * another reconciliation run on top of a slow one.
     *
     * @param string               $type
     * @param array<string, mixed> $payload
     */
    public function enqueueIfNoActive(string $type, array $payload, int $maxAttempts = 1): ?int
    {
        if ($this->hasActiveType($type)) {
            return null;
        }

        return $this->enqueue($type, $payload, 0, null, null, $maxAttempts);
    }

    /**
     * IDs of a user's family imports still parked on the review screen - staged, never
     * committed or cancelled. Uploading a new file retires these (see
     * FamilyImportController::retirePreviousReviews), so an abandoned error report does
     * not leave its staging file on disk for good.
     *
     * @return list<int>
     */
    public function stagedReviewIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $rows = $this->db->table('job_queue')
            ->select('jobID, result_json')
            ->where('type', 'family_import')
            ->where('userID', $userId)
            ->where('status', 'done')
            ->like('result_json', '"phase":"review"')
            ->get()
            ->getResultArray();

        $ids = [];

        foreach ($rows as $row) {
            // The LIKE narrows the scan; the decode is what actually decides.
            $summary = json_decode((string) ($row['result_json'] ?? ''), true);

            if (is_array($summary) && ($summary['phase'] ?? '') === 'review') {
                $ids[] = (int) $row['jobID'];
            }
        }

        return $ids;
    }

    /**
     * Staging IDs an unfinished import still needs: every queued/running family_import
     * job, plus the review job a queued WRITE job reads its rows from (payload.stageJobId).
     * The staging janitor must never sweep these - a write job can sit `pending` for hours
     * when the worker is not running, and deleting its rows underneath it kills the import.
     *
     * @return list<int>
     */
    public function activeStagingIds(): array
    {
        $rows = $this->db->table('job_queue')
            ->select('jobID, payload')
            ->where('type', 'family_import')
            ->whereIn('status', ['pending', 'processing'])
            ->get()
            ->getResultArray();

        $ids = [];

        foreach ($rows as $row) {
            $ids[] = (int) $row['jobID'];

            $payload    = json_decode((string) ($row['payload'] ?? ''), true);
            $stageJobId = is_array($payload) ? (int) ($payload['stageJobId'] ?? 0) : 0;

            if ($stageJobId > 0) {
                $ids[] = $stageJobId;
            }
        }

        return array_values(array_unique($ids));
    }
}
