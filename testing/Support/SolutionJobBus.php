<?php

declare(strict_types=1);

namespace Fapost\Testing\Support;

use Fapost\Foundation\Solution\Queue\SolutionJob;
use Fapost\Foundation\Solution\Queue\SolutionQueueInterface;
use Illuminate\Bus\Dispatcher;
use LogicException;

/**
 * Laravel's dispatcher that keeps Solution jobs instead of queueing them and lets everything else through.
 *
 * Because it is a real dispatcher and not a fake, a test's own `Bus::fake()` wraps it: a Solution job that the test's
 * fake does not take (`Bus::fake([Other::class])`) still reaches this one.
 *
 * - A Solution job has to come through {@see SolutionQueueInterface::dispatch()}, which stamps the tenant on it. One that
 *   reaches the bus without the stamp would fail on a worker, so here it fails at once, where the author can see where
 *   it was queued.
 * - The job is kept as a serialized and restored copy, as a worker would get it, so a job that does not survive
 *   serialization fails in the author's test.
 *
 * @internal not part of the testing kit's API: tests use the methods of `SolutionTestCase`
 */
final class SolutionJobBus extends Dispatcher
{
    /** @var list<SolutionJob> */
    private array $queued = [];

    /**
     * @param  mixed  $command
     */
    public function dispatch($command)
    {
        if ($command instanceof SolutionJob) {
            $this->keep($command);

            return null;
        }

        return parent::dispatch($command);
    }

    /**
     * @param  mixed  $command
     */
    public function dispatchToQueue($command)
    {
        if ($command instanceof SolutionJob) {
            $this->keep($command);

            return null;
        }

        return parent::dispatchToQueue($command);
    }

    /**
     * @return list<SolutionJob>
     */
    public function queuedJobs(): array
    {
        return $this->queued;
    }

    private function keep(SolutionJob $job): void
    {
        if (null === $job->tenantId) {
            throw new LogicException(sprintf(
                'Solution job %s was queued without a tenant. Queue it through %s::dispatch() while a tenant is current; '
                . 'dispatching it with Laravel\'s dispatcher or dispatch() leaves it with no tenant to run in.',
                $job::class,
                SolutionQueueInterface::class,
            ));
        }

        $restored = unserialize(serialize($job));

        if (!$restored instanceof SolutionJob) {
            throw new LogicException(sprintf('Solution job %s did not survive serialization.', $job::class));
        }

        $this->queued[] = $restored;
    }
}
