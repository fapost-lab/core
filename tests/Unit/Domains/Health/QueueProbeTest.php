<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Health;

use App\Domains\Health\Enums\HealthState;
use App\Domains\Health\Probes\QueueProbe;
use App\Domains\Health\Services\HorizonQueueMetrics;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Connection;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\WaitTimeCalculator;
use Mockery;
use RuntimeException;
use Tests\TestCase;

final class QueueProbeTest extends TestCase
{
    private const int NOW = 1_800_000_000;

    /** @var array<int, object> */
    private array $masters = [];

    /** @var array<string, int> */
    private array $waits = [];

    /** @var array<string, int> */
    private array $throughput = [];

    private ?int $snapshotAt = self::NOW - 600;

    private int $failed = 0;

    private ?RuntimeException $failure = null;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::createFromTimestamp(self::NOW));

        config()->set('queue.default', 'redis');
        config()->set('queue.connections.redis.driver', 'redis');
        config()->set('horizon.defaults', [
            'high'    => ['connection' => 'redis', 'queue' => ['flow.execution', 'messaging.transactional']],
            'default' => ['connection' => 'redis', 'queue' => ['default']],
        ]);
        config()->set('horizon.waits', ['redis:default' => 30, 'redis:flow.execution' => 5]);
        config()->set('horizon.trim.recent_failed', 10080);

        $this->masters = [(object) ['status' => 'running']];
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_a_queue_connection_that_is_not_redis_is_disabled(): void
    {
        config()->set('queue.default', 'database');
        config()->set('queue.connections.database.driver', 'database');

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Disabled, $result->state);
        $this->assertStringContainsString('"database"', $result->reason);
        $this->assertSame(['connection' => 'database', 'driver' => 'database'], $result->details);
    }

    public function test_a_reader_that_throws_is_unknown_and_names_only_the_exception_class(): void
    {
        $this->failure = new RuntimeException('Connection refused redis://:hunter2@redis:6379');

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Unknown, $result->state);
        $this->assertStringContainsString(RuntimeException::class, $result->reason);
        $this->assertStringNotContainsString('hunter2', $result->reason);
    }

    public function test_no_supervisor_means_down_and_still_reports_what_could_be_read(): void
    {
        $this->masters = [];
        $this->failed  = 3;

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Down, $result->state);
        $this->assertStringContainsString('Horizon is not running', $result->reason);
        $this->assertSame(0, $result->details['masters']);
        $this->assertSame(3, $result->details['failed']);
        $this->assertCount(3, $result->details['queues']);
    }

    public function test_all_supervisors_paused_means_down(): void
    {
        $this->masters = [(object) ['status' => 'paused'], (object) ['status' => 'paused']];

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Down, $result->state);
        $this->assertStringContainsString('Horizon is paused', $result->reason);
    }

    public function test_one_running_supervisor_among_paused_ones_is_up(): void
    {
        $this->masters = [(object) ['status' => 'paused'], (object) ['status' => 'running']];

        $this->assertSame(HealthState::Up, $this->probe()->check()->state);
    }

    public function test_up_reports_each_named_queue_with_its_figures(): void
    {
        $this->waits      = ['redis:flow.execution' => 12, 'redis:default' => 0];
        $this->throughput = ['flow.execution' => 50, 'default' => 7];
        $this->failed     = 4;

        $result = $this->probe()->check();

        $this->assertSame(HealthState::Up, $result->state);
        $this->assertSame('Horizon is running (1 instance).', $result->reason);
        $this->assertSame(1, $result->details['masters']);
        $this->assertSame(4, $result->details['failed']);
        $this->assertSame(10080, $result->details['failed_window_minutes']);
        $this->assertSame(CarbonImmutable::createFromTimestamp(self::NOW - 600)->toIso8601String(), $result->details['window_started_at']);

        $this->assertSame(
            ['flow.execution', 'messaging.transactional', 'default'],
            array_column($result->details['queues'], 'name'),
        );
        $this->assertSame([
            'name'            => 'flow.execution',
            'connection'      => 'redis',
            'wait_seconds'    => 12,
            'wait_threshold'  => 5,
            'throughput'      => 50,
            'jobs_per_minute' => 5.0,
        ], $result->details['queues'][0]);
    }

    public function test_a_queue_no_supervisor_serves_has_no_wait_not_zero_and_the_default_threshold(): void
    {
        $this->waits = ['redis:default' => 0];

        $queues = $this->probe()->check()->details['queues'];

        $this->assertNull($queues[1]['wait_seconds']);
        $this->assertSame(60, $queues[1]['wait_threshold']);
        $this->assertSame(0, $queues[2]['wait_seconds']);
        $this->assertSame(30, $queues[2]['wait_threshold']);
    }

    public function test_jobs_per_minute_is_unknown_without_a_snapshot_and_never_divides_by_less_than_a_minute(): void
    {
        $this->snapshotAt = null;
        $this->throughput = ['default' => 9];

        $result = $this->probe()->check();

        $this->assertNull($result->details['window_started_at']);
        $this->assertNull($result->details['queues'][2]['jobs_per_minute']);

        $this->snapshotAt = self::NOW - 10;

        $this->assertSame(9.0, $this->probe()->check()->details['queues'][2]['jobs_per_minute']);
    }

    public function test_a_queue_only_the_wait_calculator_knows_is_added_after_the_configured_ones(): void
    {
        $this->waits = ['redis:fast,slow' => 3, 'redis:default' => 1];

        $queues = $this->probe()->check()->details['queues'];

        $this->assertSame('fast,slow', $queues[3]['name']);
        $this->assertSame(3, $queues[3]['wait_seconds']);
        $this->assertCount(4, $queues);
    }

    private function probe(): QueueProbe
    {
        $masters = Mockery::mock(MasterSupervisorRepository::class);
        $masters->shouldReceive('all')->andReturnUsing(function (): array {
            if (null !== $this->failure) {
                throw $this->failure;
            }

            return $this->masters;
        });

        $calculator = Mockery::mock(WaitTimeCalculator::class);
        $calculator->shouldReceive('calculate')->andReturnUsing(fn (): array => $this->waits);

        $metrics = Mockery::mock(MetricsRepository::class);
        $metrics->shouldReceive('throughputForQueue')->andReturnUsing(fn (string $queue): int => $this->throughput[$queue] ?? 0);

        $jobs = Mockery::mock(JobRepository::class);
        $jobs->shouldReceive('countRecentlyFailed')->andReturnUsing(fn (): int => $this->failed);

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('get')->with('last_snapshot_at')->andReturnUsing(fn (): ?string => null === $this->snapshotAt ? null : (string) $this->snapshotAt);
        $redis = Mockery::mock(RedisFactory::class);
        $redis->shouldReceive('connection')->with('horizon')->andReturn($connection);

        return new QueueProbe(
            $this->app->make('config'),
            new HorizonQueueMetrics($masters, $calculator, $metrics, $jobs, $redis),
        );
    }
}
