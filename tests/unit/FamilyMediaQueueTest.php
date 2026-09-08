<?php

namespace Tests\Unit;

use App\Jobs\FamilyMediaReconcileJob;
use App\Jobs\JobReporter;
use App\Libraries\FamilyMediaReconciler;
use App\Libraries\FamilyMediaStorage;
use App\Models\Jobs\JobQueueModel;
use CodeIgniter\Test\CIUnitTestCase;
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
        $jobId = $this->queue->enqueue('media_reconcile', []);
        $handler = new FamilyMediaReconcileJob(new FamilyMediaReconciler());

        $this->expectException(RuntimeException::class);

        $handler->handle([], ['jobID' => $jobId], new JobReporter($this->queue, $jobId));
    }

    public function testQueueConfigurationResolvesTheMediaReconcileHandler(): void
    {
        $this->assertSame(FamilyMediaReconcileJob::class, (new Queue())->handlers['media_reconcile']);
    }
}
