<?php

namespace App\Commands;

use App\Models\Jobs\JobQueueModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Producer for the media reconciliation job.
 *
 * Scheduled worker wrappers invoke this immediately before `queue:work` so each
 * fire enqueues at most one media_reconcile job. A non-blocking file lock plus
 * the model's active-type query prevents overlapping scheduler fires from
 * stacking reconciliation jobs, and a second fire while one is already queued
 * exits successfully without enqueuing anything.
 *
 * Usage:
 *   php spark media:queue-reconcile
 */
class QueueMediaReconcile extends BaseCommand
{
    protected $group       = 'Jobs';
    protected $name        = 'media:queue-reconcile';
    protected $description = 'Enqueue one media reconciliation job when none is already active.';
    protected $usage       = 'media:queue-reconcile';

    public function run(array $params)
    {
        $lockPath = WRITEPATH . 'media-reconcile-enqueue.lock';
        $lockHandle = @fopen($lockPath, 'c');

        if ($lockHandle === false) {
            CLI::error('Could not open media reconcile enqueue lock: ' . $lockPath);

            return EXIT_ERROR;
        }

        if (! @flock($lockHandle, LOCK_EX | LOCK_NB)) {
            CLI::write('Another media reconcile producer is already running. Exiting.', 'yellow');
            fclose($lockHandle);

            return EXIT_SUCCESS;
        }

        try {
            $model = new JobQueueModel();

            if (! $model->hasTable()) {
                CLI::write('The job_queue table is missing (import the newest accesscardV*.sql). Exiting.', 'red');

                return EXIT_ERROR;
            }

            $jobId = $model->enqueueIfNoActive('media_reconcile', []);

            if ($jobId === null) {
                CLI::write('A media reconcile job is already queued or running.', 'yellow');
            } else {
                CLI::write('Queued media reconcile job #' . $jobId . '.', 'green');
            }

            return EXIT_SUCCESS;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }
}