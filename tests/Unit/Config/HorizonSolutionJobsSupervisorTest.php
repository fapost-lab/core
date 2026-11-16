<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Infrastructure\Solution\CoreSolutionQueue;
use App\Infrastructure\Solution\SolutionQueues;
use App\Jobs\Solution\PrepareSolutionSchemaJob;
use Fapost\Foundation\Solution\Queue\SolutionQueueInterface;
use Tests\TestCase;

/**
 * The supervisor of the Solution jobs, read from the file: at boot Core fills its queues from the installed Solutions
 * (or removes it when there are none), so the booted configuration cannot show what the file promises.
 */
final class HorizonSolutionJobsSupervisorTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $horizon;

    protected function setUp(): void
    {
        parent::setUp();

        $horizon = require base_path('config/horizon.php');
        $this->assertIsArray($horizon);
        $this->horizon = $horizon;
    }

    public function test_the_supervisor_is_on_the_long_connection_and_balances_between_the_solution_queues(): void
    {
        $supervisor = $this->horizon['defaults'][SolutionQueues::SUPERVISOR] ?? null;

        $this->assertIsArray($supervisor);
        $this->assertSame(SolutionQueues::CONNECTION, $supervisor['connection']);
        $this->assertSame('auto', $supervisor['balance']);
        $this->assertSame([], $supervisor['queue'], 'The queues come from the installed Solutions, not from the file.');
    }

    public function test_its_timeout_stays_below_the_retry_after_of_the_connection_with_a_margin(): void
    {
        $supervisor = $this->horizon['defaults'][SolutionQueues::SUPERVISOR];
        $retryAfter = (int) config('queue.connections.' . SolutionQueues::CONNECTION . '.retry_after', 0);

        $this->assertSame(1800, $retryAfter);
        $this->assertLessThanOrEqual(SolutionQueueInterface::MAX_TIMEOUT_SECONDS, $supervisor['timeout']);
        $this->assertLessThan($retryAfter, $supervisor['timeout']);
        $this->assertGreaterThanOrEqual(60, $retryAfter - $supervisor['timeout'], 'A margin for the worker to give the job up before the queue hands it out again.');
    }

    public function test_the_longest_timeout_a_job_may_ask_for_fits_the_supervisor(): void
    {
        $this->assertLessThanOrEqual(
            $this->horizon['defaults'][SolutionQueues::SUPERVISOR]['timeout'],
            SolutionQueueInterface::MAX_TIMEOUT_SECONDS,
            'A job may declare ' . CoreSolutionQueue::class . '\'s maximum; the worker must not kill it earlier.',
        );
    }

    public function test_every_environment_sizes_the_supervisor(): void
    {
        $this->assertNotEmpty($this->horizon['environments']);

        foreach ($this->horizon['environments'] as $environment => $supervisors) {
            $this->assertArrayHasKey(SolutionQueues::SUPERVISOR, $supervisors, 'environment ' . $environment);
        }
    }

    public function test_the_supervisor_does_not_share_a_queue_with_the_other_ones(): void
    {
        foreach ($this->horizon['defaults'] as $name => $supervisor) {
            if (SolutionQueues::SUPERVISOR === $name) {
                continue;
            }

            foreach ((array) $supervisor['queue'] as $queue) {
                if (PrepareSolutionSchemaJob::QUEUE === $queue) {
                    continue; // Core's own queue; see SolutionQueuesTest for the id that would collide with it.
                }

                $this->assertStringStartsNotWith(SolutionQueues::PREFIX, $queue, $name . ' serves a Solution queue.');
            }
        }
    }
}
