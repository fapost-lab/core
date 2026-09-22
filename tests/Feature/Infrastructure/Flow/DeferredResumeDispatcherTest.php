<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Flow;

use App\Infrastructure\Flow\QueuedDelayResumeScheduler;
use App\Infrastructure\Flow\QueuedSendMessageTimeoutScheduler;
use App\Jobs\Flow\ResumeDelayedFlowSessionJob;
use App\Jobs\Flow\ResumeTimedOutSendMessageNodeJob;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Deferred resumes — `send_message` timeouts and `delay` wake-ups — are queued
 * with their delay on an asynchronous queue and not at all on `sync`, which
 * would run them at once inside the engine run that scheduled them.
 */
final class DeferredResumeDispatcherTest extends TestCase
{
    public function test_send_message_timeout_is_queued_with_its_delay_on_flow_execution(): void
    {
        config(['queue.default' => 'redis']);
        Bus::fake();
        $timeoutAt = Carbon::now()->addMinute();

        $this->app->make(QueuedSendMessageTimeoutScheduler::class)
            ->schedule('tenant-1', 'session-1', 'node-1', 'telegram', $timeoutAt);

        Bus::assertDispatched(
            ResumeTimedOutSendMessageNodeJob::class,
            fn (ResumeTimedOutSendMessageNodeJob $job): bool => 'node-1' === $job->nodeId
                && 'flow.execution' === $job->queue
                && $timeoutAt->equalTo($job->delay),
        );
    }

    public function test_send_message_timeout_is_not_queued_on_a_sync_queue(): void
    {
        config(['queue.default' => 'sync']);
        Bus::fake();
        Log::spy();

        $this->app->make(QueuedSendMessageTimeoutScheduler::class)
            ->schedule('tenant-1', 'session-1', 'node-1', 'telegram', Carbon::now()->addMinute());

        Bus::assertNotDispatched(ResumeTimedOutSendMessageNodeJob::class);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => 'flow.deferred_resume.skipped_on_sync_queue' === $message
                && ResumeTimedOutSendMessageNodeJob::class === $context['job'],
        );
    }

    public function test_delay_wake_up_is_not_queued_on_a_sync_queue(): void
    {
        config(['queue.default' => 'sync']);
        Bus::fake();

        $this->app->make(QueuedDelayResumeScheduler::class)
            ->schedule('tenant-1', 'session-1', 'node-1', Carbon::now()->addMinute());

        Bus::assertNotDispatched(ResumeDelayedFlowSessionJob::class);
    }
}
