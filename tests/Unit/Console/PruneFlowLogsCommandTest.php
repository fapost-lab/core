<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Domains\Flow\Logging\Contracts\FlowLogPartitionManagerInterface;
use Carbon\CarbonImmutable;
use Mockery;
use Tests\TestCase;

final class PruneFlowLogsCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_drops_only_partitions_older_than_30_days(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 00:00:00 UTC'));

        $manager = Mockery::mock(FlowLogPartitionManagerInterface::class);
        $manager->shouldReceive('listMonthlyPartitions')->once()->andReturn(collect([
            'flow_logs_2026_02',
            'flow_logs_2026_03',
            'flow_logs_2026_04',
        ]));
        $manager->shouldReceive('dropPartition')->once()->with('flow_logs_2026_02');
        $manager->shouldNotReceive('dropPartition')->with('flow_logs_2026_03');
        $manager->shouldNotReceive('dropPartition')->with('flow_logs_2026_04');

        $this->app->instance(FlowLogPartitionManagerInterface::class, $manager);

        $this->artisan('logs:prune-flow')
            ->expectsOutput('Dropped partitions: 1')
            ->assertSuccessful();
    }
}
