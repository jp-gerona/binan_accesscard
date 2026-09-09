<?php

namespace App\Commands;

use App\Libraries\FamilyMediaReconciler;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Direct scheduled reconciliation for the office-managed family-media folder.
 *
 * This is maintenance polling, not user-requested work: it must not add a
 * completed job row every time the scheduler fires. The shared queue continues
 * to process imports and any media jobs created before this command was added.
 */
class ReconcileFamilyMedia extends BaseCommand
{
    protected $group       = 'Jobs';
    protected $name        = 'media:reconcile';
    protected $description = 'Reconcile family media directly without creating a queue job.';
    protected $usage       = 'media:reconcile';

    private FamilyMediaReconciler $reconciler;
    private string $lockPath;

    public function __construct(
        LoggerInterface $logger,
        Commands $commands,
        ?FamilyMediaReconciler $reconciler = null,
        ?string $lockPath = null,
    ) {
        parent::__construct($logger, $commands);

        $this->reconciler = $reconciler ?? new FamilyMediaReconciler();
        $this->lockPath = $lockPath ?? WRITEPATH . 'media-reconcile.lock';
    }

    public function run(array $params)
    {
        $lockHandle = @fopen($this->lockPath, 'c');

        if ($lockHandle === false) {
            CLI::error('Could not open media reconciliation lock: ' . $this->lockPath);

            return EXIT_ERROR;
        }

        if (! @flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);

            return EXIT_SUCCESS;
        }

        try {
            $counts = $this->reconciler->run();

            if ($this->hasActionableCounts($counts)) {
                CLI::write(
                    'Reconciled media: ' . $counts['seen'] . ' seen, '
                    . $counts['linked'] . ' linked, ' . $counts['pending'] . ' pending, '
                    . $counts['replaced'] . ' replaced, ' . $counts['missing'] . ' missing, '
                    . $counts['invalid'] . ' invalid.',
                    $counts['invalid'] > 0 ? 'yellow' : 'green',
                );
            }

            return EXIT_SUCCESS;
        } catch (Throwable $e) {
            CLI::error('Media reconciliation failed: ' . $e->getMessage());

            return EXIT_ERROR;
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /** @param array<string, int> $counts */
    private function hasActionableCounts(array $counts): bool
    {
        foreach (['linked', 'pending', 'invalid', 'missing', 'replaced'] as $key) {
            if (($counts[$key] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
