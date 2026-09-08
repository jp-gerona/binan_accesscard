<?php

namespace Tests\Unit;

use App\Jobs\FamilyMediaReconcileJob;
use App\Jobs\JobReporter;
use App\Libraries\FamilyMediaReconciler;
use App\Models\Jobs\JobQueueModel;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Queue;
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
        $jobId = $this->queue->enqueue('media_reconcile', []);
        $handler = new FamilyMediaReconcileJob(new FamilyMediaReconciler());

        $outcome = $handler->handle([], ['jobID' => $jobId], new JobReporter($this->queue, $jobId));

        $this->assertSame('done', $outcome->status);
        $this->assertSame(['seen' => 0, 'linked' => 0, 'pending' => 0, 'invalid' => 0, 'missing' => 0, 'replaced' => 0], $outcome->result);
    }

    public function testQueueConfigurationResolvesTheMediaReconcileHandler(): void
    {
        $this->assertSame(FamilyMediaReconcileJob::class, (new Queue())->handlers['media_reconcile']);
    }
}
