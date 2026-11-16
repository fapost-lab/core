<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Solution;

use Acme\Feedback\RecordFeedbackJob;
use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\Services\SolutionActivator;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Closure;
use Fapost\Foundation\Solution\Queue\SolutionQueueInterface;
use Fapost\Testing\Support\SolutionRequestState;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Tests\Feature\FeatureTestCase;
use Tests\Support\InstallsFeedbackSolution;

/**
 * The job on its real way, with no bus fake in between: pushed on the Solution's queue of the long connection, and, on
 * a synchronous queue, serialized, restored and run through the job's own middleware inside the tenant's schema.
 */
final class SolutionQueuePathTest extends FeatureTestCase
{
    use InstallsFeedbackSolution;

    private TenantInterface $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->app->make(TenantRepositoryInterface::class)->findBySlug('main');
        $this->assertNotNull($tenant);
        $this->tenant = $tenant;

        $this->installFeedback();
    }

    protected function tearDown(): void
    {
        $this->removeFeedback();

        parent::tearDown();
    }

    public function test_on_a_synchronous_queue_the_job_runs_through_the_middleware_in_the_tenants_schema(): void
    {
        config(['queue.connections.redis-long.driver' => 'sync']);
        $this->createFeedbackJobLog($this->tenant);
        $this->inTenant(fn () => $this->app->make(SolutionActivator::class)->activate('feedback', ActivationActor::console()));
        SolutionRequestState::forget($this->app);

        $this->inTenant(fn () => $this->app->make(SolutionQueueInterface::class)->dispatch(new RecordFeedbackJob('through the queue')));

        $this->assertSame(['through the queue'], $this->feedbackJobLog($this->tenant));
    }

    public function test_on_a_synchronous_queue_a_job_of_a_solution_that_is_not_active_is_dropped(): void
    {
        config(['queue.connections.redis-long.driver' => 'sync']);
        $this->createFeedbackJobLog($this->tenant);

        $this->inTenant(fn () => $this->app->make(SolutionQueueInterface::class)->dispatch(new RecordFeedbackJob('unwanted')));

        $this->assertSame([], $this->feedbackJobLog($this->tenant));
    }

    public function test_the_job_is_pushed_on_the_solutions_queue_of_the_long_connection(): void
    {
        $queue = Queue::fake();

        $this->inTenant(fn () => $this->app->make(SolutionQueueInterface::class)->dispatch(new RecordFeedbackJob('now')));

        Queue::assertPushedOn(
            'solution-jobs.feedback',
            RecordFeedbackJob::class,
            static fn (RecordFeedbackJob $job): bool => 'redis-long' === $job->connection && null === $job->delay,
        );
        $this->assertSame([], $this->delayed($queue), 'Nothing was queued for later.');
    }

    public function test_a_delay_queues_the_job_for_later(): void
    {
        $queue = Queue::fake();

        $this->inTenant(fn () => $this->app->make(SolutionQueueInterface::class)->dispatch(new RecordFeedbackJob('later'), 120));

        Queue::assertPushedOn('solution-jobs.feedback', RecordFeedbackJob::class, static fn (RecordFeedbackJob $job): bool => 120 === $job->delay);
        $this->assertCount(1, $this->delayed($queue)[RecordFeedbackJob::class] ?? []);
        $this->assertSame('solution-jobs.feedback', $this->delayed($queue)[RecordFeedbackJob::class][0]['queue']);
    }

    /**
     * @return array<class-string, list<array<string, mixed>>>
     */
    private function delayed(QueueFake $queue): array
    {
        /** @var array<class-string, list<array<string, mixed>>> $delayed */
        $delayed = Closure::bind(fn (): array => $this->delayed, $queue, QueueFake::class)();

        return $delayed;
    }

    private function inTenant(callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($this->tenant, $callback(...));
    }
}
