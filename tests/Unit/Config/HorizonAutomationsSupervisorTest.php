<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Orchestration\AutomationRunner;
use App\Domains\Tenancy\Services\PlatformSettingRegistry;
use App\Jobs\Flow\ContinueAutomationRunJob;
use App\Jobs\Flow\StartAutomationRunJob;
use Tests\TestCase;

/**
 * Automation runs have a queue and a supervisor of their own, and a running segment is never reserved twice: the
 * job timeout stays below the connection's `retry_after`, and the longest segment budget plus the slowest node stays
 * below the job timeout.
 */
final class HorizonAutomationsSupervisorTest extends TestCase
{
    /** A `call` node at worst: the egress guard's 8 s name lookup plus the 10 s HTTP timeout. */
    private const int SLOWEST_NODE_SECONDS = 18;

    public function test_the_automation_queue_has_a_supervisor_of_its_own(): void
    {
        $automations = config('horizon.defaults.automations');

        $this->assertIsArray($automations);
        $this->assertSame([AutomationRunner::QUEUE], $automations['queue']);

        foreach ((array) config('horizon.defaults') as $name => $supervisor) {
            if ('automations' !== $name) {
                $this->assertNotContains(AutomationRunner::QUEUE, (array) ($supervisor['queue'] ?? []), "supervisor {$name}");
            }
        }
    }

    public function test_a_running_segment_is_never_reserved_twice(): void
    {
        $automations = config('horizon.defaults.automations');
        $retryAfter  = (int) config('queue.connections.' . $automations['connection'] . '.retry_after', 0);

        foreach ([new StartAutomationRunJob('t', 'f', 'tr', 'k'), new ContinueAutomationRunJob('t', 's', 'g')] as $job) {
            $this->assertLessThan($retryAfter, $job->timeout, $job::class . ' timeout must stay below retry_after.');
            $this->assertSame($automations['timeout'], $job->timeout, $job::class);
        }
    }

    public function test_the_longest_segment_budget_leaves_room_for_the_slowest_node(): void
    {
        $maxSegmentSeconds = $this->app->make(PlatformSettingRegistry::class)
            ->find(FlowEngineInterface::SEGMENT_SECONDS_SETTING)?->max;

        $this->assertIsInt($maxSegmentSeconds, 'The segment seconds setting must have a maximum.');

        $this->assertLessThan(
            (new ContinueAutomationRunJob('t', 's', 'g'))->timeout,
            $maxSegmentSeconds + self::SLOWEST_NODE_SECONDS + 5,
        );
    }

    public function test_every_environment_sizes_the_automations_supervisor(): void
    {
        foreach ((array) config('horizon.environments') as $environment => $supervisors) {
            $this->assertArrayHasKey('automations', $supervisors, 'environment ' . $environment);
        }
    }
}
