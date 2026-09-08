<?php

namespace Config;

use App\Jobs\FamilyImportJob;
use App\Jobs\FamilyMediaReconcileJob;
use CodeIgniter\Config\BaseConfig;

/**
 * Registry for the background job queue. Maps a job `type` (stored on each
 * job_queue row) to the App\Jobs\JobHandlerInterface that processes it.
 *
 * To add a new background job:
 *   1. Write a handler implementing App\Jobs\JobHandlerInterface.
 *   2. Register it here under a unique type string.
 *   3. Enqueue work with JobQueueModel::enqueue('<type>', $payload, ...).
 * The `queue:work` worker (fired every minute) dispatches by type automatically.
 */
class Queue extends BaseConfig
{
    /** @var array<string, class-string<\App\Jobs\JobHandlerInterface>> */
    public array $handlers = [
        'family_import' => FamilyImportJob::class,
        'media_reconcile' => FamilyMediaReconcileJob::class,
    ];
}
