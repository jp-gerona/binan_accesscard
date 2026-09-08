<?php

namespace App\Jobs;

use App\Libraries\FamilyMediaReconciler;

/**
 * Background handler for the 'media_reconcile' job type.
 *
 * Runs the reconciler once over the managed folder and reports a compact count
 * summary only. Filenames and image data never reach job_queue.result_json; the
 * generic worker stores the counts and the human-readable message.
 */
class FamilyMediaReconcileJob implements JobHandlerInterface
{
    private FamilyMediaReconciler $reconciler;

    public function __construct(?FamilyMediaReconciler $reconciler = null)
    {
        $this->reconciler = $reconciler ?? new FamilyMediaReconciler();
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $job
     */
    public function handle(array $payload, array $job, JobReporter $reporter): JobOutcome
    {
        $counts = $this->reconciler->run($reporter);
        $message = $this->message($counts);

        // Invalid files leave the job partial only when no valid work succeeded.
        $validWork = ($counts['seen'] + $counts['linked'] + $counts['pending']
            + $counts['replaced'] + $counts['missing']) > 0;

        if ($counts['invalid'] > 0 && ! $validWork) {
            return JobOutcome::partial($message, $counts);
        }

        return JobOutcome::done($message, $counts);
    }

    /** @param array<string, mixed> $counts */
    private function message(array $counts): string
    {
        return 'Reconciled media: ' . $counts['seen'] . ' seen, '
            . $counts['linked'] . ' linked, ' . $counts['pending'] . ' pending, '
            . $counts['replaced'] . ' replaced, ' . $counts['missing'] . ' missing, '
            . $counts['invalid'] . ' invalid.';
    }
}