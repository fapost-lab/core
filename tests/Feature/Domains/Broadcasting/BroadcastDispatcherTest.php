<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Broadcasting;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Broadcasting\Enums\BroadcastStatus;
use App\Domains\Broadcasting\Jobs\RunBroadcastJob;
use App\Domains\Broadcasting\Models\Broadcast;
use App\Domains\Broadcasting\Services\BroadcastDispatcher;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\FeatureTestCase;

final class BroadcastDispatcherTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_start_transitions_draft_to_running_and_dispatches_the_run(): void
    {
        Bus::fake();
        $broadcast = $this->draft();

        $started = app(BroadcastDispatcher::class)->start($broadcast);

        $this->assertTrue($started);
        $broadcast->refresh();
        $this->assertSame(BroadcastStatus::Running, $broadcast->status);
        $this->assertNotNull($broadcast->started_at);
        Bus::assertDispatched(
            RunBroadcastJob::class,
            static fn (RunBroadcastJob $job): bool => $job->broadcastId === (string) $broadcast->getKey(),
        );
    }

    public function test_start_is_idempotent_and_only_wins_once(): void
    {
        Bus::fake();
        $broadcast  = $this->draft();
        $dispatcher = app(BroadcastDispatcher::class);

        $this->assertTrue($dispatcher->start($broadcast));
        $this->assertFalse($dispatcher->start($broadcast->fresh()), 'A already-running broadcast must not start twice.');

        Bus::assertDispatchedTimes(RunBroadcastJob::class, 1);
    }

    public function test_a_start_inside_a_transaction_that_rolls_back_queues_nothing(): void
    {
        $processed = 0;
        Event::listen(JobProcessing::class, static function () use (&$processed): void {
            ++$processed;
        });
        $broadcast = $this->draft();

        try {
            DB::transaction(function () use ($broadcast): void {
                app(BroadcastDispatcher::class)->start($broadcast);

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
            // The status change was rolled back with it.
        }

        $this->assertSame(0, $processed, 'The run was queued although the status change never committed.');
        $this->assertSame(BroadcastStatus::Draft, $broadcast->fresh()->status);
    }

    public function test_a_start_inside_a_transaction_queues_the_run_once_it_commits(): void
    {
        $processed = 0;
        Event::listen(JobProcessing::class, static function (JobProcessing $event) use (&$processed): void {
            if (RunBroadcastJob::class === $event->job->resolveName()) {
                ++$processed;
            }
        });
        $broadcast = $this->draft();

        DB::transaction(function () use ($broadcast, &$processed): void {
            app(BroadcastDispatcher::class)->start($broadcast);

            $this->assertSame(0, $processed, 'The run started before the transaction committed.');
        });

        $this->assertSame(1, $processed);
    }

    private function draft(): Broadcast
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);

        return Broadcast::query()->create([
            'tenant_id'    => self::TENANT_ID,
            'assistant_id' => $assistant->getKey(),
            'name'         => 'Promo',
            'message'      => 'Hello!',
            'target_type'  => 'all',
            'status'       => BroadcastStatus::Draft->value,
        ]);
    }
}
