<?php

namespace Tests\Unit;

use App\Commands\ReconcileFamilyMedia;
use App\Jobs\FamilyMediaReconcileJob;
use App\Jobs\JobReporter;
use App\Libraries\FamilyMediaReconciler;
use App\Libraries\FamilyMediaStorage;
use App\Models\Jobs\JobQueueModel;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Config\FamilyMediaSettings;
use Config\Queue;
use RuntimeException;
use Tests\Support\Database\DumpSchema;

/** @internal */
final class FamilyMediaQueueTest extends CIUnitTestCase
{
    private JobQueueModel $queue;

    protected function setUp(): void
    {
        parent::setUp();
        DumpSchema::create(db_connect());
        $this->queue = new JobQueueModel();
    }

    protected function tearDown(): void
    {
        DumpSchema::drop(db_connect());
        parent::tearDown();
    }

    public function testOnlyOneActiveMediaReconcileJobCanBeQueued(): void
    {
        $first = $this->queue->enqueueIfNoActive('media_reconcile', []);
        $second = $this->queue->enqueueIfNoActive('media_reconcile', []);

        $this->assertIsInt($first);
        $this->assertNull($second);
    }

    public function testMediaReconcileHandlerReturnsDoneWithCompactCounts(): void
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'media-reconcile-queue-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);

        try {
            $settings = new FamilyMediaSettings();
            $settings->root = $root;
            $jobId = $this->queue->enqueue('media_reconcile', []);
            $handler = new FamilyMediaReconcileJob(new FamilyMediaReconciler(new FamilyMediaStorage($settings)));

            $outcome = $handler->handle([], ['jobID' => $jobId], new JobReporter($this->queue, $jobId));

            $this->assertSame('done', $outcome->status);
            $this->assertSame(['seen' => 0, 'linked' => 0, 'pending' => 0, 'invalid' => 0, 'missing' => 0, 'replaced' => 0], $outcome->result);
        } finally {
            @rmdir($root);
        }
    }

    public function testMediaReconcileHandlerThrowsOnAnUnconfiguredRoot(): void
    {
        // Pin the root explicitly: a deployment .env that sets a real root must
        // not turn this test into a scan of the office's actual folder.
        $settings = new FamilyMediaSettings();
        $settings->root = '';
        $handler = new FamilyMediaReconcileJob(new FamilyMediaReconciler(new FamilyMediaStorage($settings)));

        $jobId = $this->queue->enqueue('media_reconcile', []);

        $this->expectException(RuntimeException::class);

        $handler->handle([], ['jobID' => $jobId], new JobReporter($this->queue, $jobId));
    }

    public function testWorkerWrappersReconcileDirectlyBeforeDrainingTheSharedQueue(): void
    {
        $wrappers = [
            'scripts/queue-worker.sh' => [
                '"$PHP_BIN" "$PROJECT_DIR/spark" media:reconcile',
                '"$PHP_BIN" "$PROJECT_DIR/spark" queue:work',
            ],
            'scripts/queue-worker.ps1' => [
                "\$reconcileOutput = & \$phpExe \$spark 'media:reconcile'",
                "\$argList = @(\$spark, 'queue:work'",
            ],
        ];

        foreach ($wrappers as $wrapper => [$producer, $drainer]) {
            $contents = file_get_contents(ROOTPATH . $wrapper);

            $this->assertIsString($contents, $wrapper . ' must be readable.');
            $producerAt = strpos($contents, $producer);
            $drainerAt = strpos($contents, $drainer);
            $this->assertNotFalse($producerAt, $wrapper . ' must invoke media reconciliation directly.');
            $this->assertNotFalse($drainerAt, $wrapper . ' must invoke the shared queue drainer.');
            $this->assertLessThan($drainerAt, $producerAt, $wrapper . ' must reconcile before draining.');
            $this->assertStringNotContainsString('media:queue-reconcile', $contents);
        }
    }

    public function testWorkerInstallersDefaultToFiveMinuteFires(): void
    {
        $shell = file_get_contents(ROOTPATH . 'scripts/install-cron-worker.sh');
        $powershell = file_get_contents(ROOTPATH . 'scripts/install-cron-worker.ps1');

        $this->assertIsString($shell);
        $this->assertIsString($powershell);
        $this->assertStringContainsString('EVERY_MINUTES="${EVERY_MINUTES:-5}"', $shell);
        $this->assertStringContainsString('[int]    $EveryMinutes = 5,', $powershell);
    }

    public function testDirectReconciliationCommandIsRegisteredWithoutQueuePersistence(): void
    {
        $command = APPPATH . 'Commands/ReconcileFamilyMedia.php';
        $contents = is_file($command) ? file_get_contents($command) : false;

        $this->assertIsString($contents, 'The direct reconciliation command must exist.');
        $this->assertStringContainsString("protected \$name        = 'media:reconcile';", $contents);
        $this->assertStringNotContainsString('JobQueueModel', $contents);
    }

    public function testDirectReconciliationCommandCompletesWithoutCreatingAQueueRow(): void
    {
        $lockPath = $this->temporaryLockPath();
        $command = $this->command($this->reconcilerWithCounts(), $lockPath);

        try {
            $this->assertSame(EXIT_SUCCESS, $command->run([]));
            $this->assertSame(0, db_connect()->table('job_queue')->countAllResults());
        } finally {
            @unlink($lockPath);
        }
    }

    public function testDirectReconciliationCommandReturnsSuccessWhenAnotherScanHoldsTheLock(): void
    {
        $lockPath = $this->temporaryLockPath();
        $handle = fopen($lockPath, 'c');
        $this->assertNotFalse($handle);
        $this->assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            $command = $this->command($this->reconcilerWithCounts(), $lockPath);

            $this->assertSame(EXIT_SUCCESS, $command->run([]));
            $this->assertSame(0, db_connect()->table('job_queue')->countAllResults());
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            @unlink($lockPath);
        }
    }

    public function testDirectReconciliationCommandReturnsErrorWhenTheScanFails(): void
    {
        $reconciler = new class extends FamilyMediaReconciler {
            public function run(?JobReporter $reporter = null): array
            {
                throw new RuntimeException('Media root is unavailable.');
            }
        };
        $lockPath = $this->temporaryLockPath();
        $command = $this->command($reconciler, $lockPath);

        try {
            $this->assertSame(EXIT_ERROR, $command->run([]));
            $this->assertSame(0, db_connect()->table('job_queue')->countAllResults());
        } finally {
            @unlink($lockPath);
        }
    }

    public function testQueueConfigurationResolvesTheMediaReconcileHandler(): void
    {
        $this->assertSame(FamilyMediaReconcileJob::class, (new Queue())->handlers['media_reconcile']);
    }

    private function reconcilerWithCounts(): FamilyMediaReconciler
    {
        return new class extends FamilyMediaReconciler {
            public function run(?JobReporter $reporter = null): array
            {
                return ['seen' => 0, 'linked' => 0, 'pending' => 0, 'invalid' => 0, 'missing' => 0, 'replaced' => 0];
            }
        };
    }

    private function temporaryLockPath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'media-reconcile-command-' . bin2hex(random_bytes(6)) . '.lock';
    }

    private function command(FamilyMediaReconciler $reconciler, string $lockPath): ReconcileFamilyMedia
    {
        return new ReconcileFamilyMedia(Services::logger(), Services::commands(), $reconciler, $lockPath);
    }
}
