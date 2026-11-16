<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Jobs\Solution\PrepareSolutionSchemaJob;
use Tests\TestCase;

/**
 * The queue the Solution schema preparation runs on has a supervisor of its own, in every environment, apart from the
 * slow tenant removal it must never wait behind.
 */
final class HorizonSolutionsSupervisorTest extends TestCase
{
    public function test_the_preparation_queue_has_a_supervisor_on_the_jobs_connection_that_does_not_share_it_with_removal(): void
    {
        $solutions = config('horizon.defaults.solutions');
        $removal   = config('horizon.defaults.removal');

        $this->assertIsArray($solutions);
        $this->assertIsArray($removal);
        $this->assertSame(PrepareSolutionSchemaJob::CONNECTION, $solutions['connection']);
        $this->assertSame([PrepareSolutionSchemaJob::QUEUE], $solutions['queue']);
        $this->assertNotContains(PrepareSolutionSchemaJob::QUEUE, $removal['queue']);
        $this->assertGreaterThan($solutions['timeout'], (int) config('queue.connections.' . $solutions['connection'] . '.retry_after', 0), 'retry_after must exceed the timeout.');
    }

    public function test_every_environment_sizes_the_solutions_supervisor(): void
    {
        foreach ((array) config('horizon.environments') as $environment => $supervisors) {
            $this->assertArrayHasKey('solutions', $supervisors, 'environment ' . $environment);
        }
    }
}
