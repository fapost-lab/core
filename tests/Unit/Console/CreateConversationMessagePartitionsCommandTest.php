<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\CreateConversationMessagePartitionsCommand;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class CreateConversationMessagePartitionsCommandTest extends TestCase
{
    /** @var array<int, ConnectionInterface> keyed by tenant object id */
    private array $connections = [];

    private ?TenantInterface $currentTenant = null;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @return array<string, array{string, list<array{string, string, string}>}>
     */
    public static function monthEdges(): array
    {
        return [
            'the 31st of a month before a shorter one' => ['2026-01-31 23:59:59 UTC', [
                ['conversation_messages_2026_01', '2026-01-01 00:00:00+00:00', '2026-02-01 00:00:00+00:00'],
                ['conversation_messages_2026_02', '2026-02-01 00:00:00+00:00', '2026-03-01 00:00:00+00:00'],
            ]],
            'the last day of the year' => ['2026-12-31 12:00:00 UTC', [
                ['conversation_messages_2026_12', '2026-12-01 00:00:00+00:00', '2027-01-01 00:00:00+00:00'],
                ['conversation_messages_2027_01', '2027-01-01 00:00:00+00:00', '2027-02-01 00:00:00+00:00'],
            ]],
            'a clock outside UTC still names UTC months' => ['2026-04-30 22:30:00 -05:00', [
                ['conversation_messages_2026_05', '2026-05-01 00:00:00+00:00', '2026-06-01 00:00:00+00:00'],
                ['conversation_messages_2026_06', '2026-06-01 00:00:00+00:00', '2026-07-01 00:00:00+00:00'],
            ]],
        ];
    }

    public function test_it_creates_the_current_and_next_month_in_every_tenant_schema(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 10:00:00 UTC'));

        $alpha = $this->tenant('alpha');
        $beta  = $this->tenant('beta');

        foreach ([$alpha, $beta] as $tenant) {
            $this->expectPartitions($this->connectionFor($tenant), [
                ['conversation_messages_2026_03', '2026-03-01 00:00:00+00:00', '2026-04-01 00:00:00+00:00'],
                ['conversation_messages_2026_04', '2026-04-01 00:00:00+00:00', '2026-05-01 00:00:00+00:00'],
            ]);
        }

        $this->bindCommand([$alpha, $beta]);

        $this->artisan('conversations:create-partition')
            ->expectsOutput('✔ alpha: conversation_messages_2026_03, conversation_messages_2026_04')
            ->expectsOutput('✔ beta: conversation_messages_2026_03, conversation_messages_2026_04')
            ->assertSuccessful();
    }

    /**
     * @param list<array{string, string, string}> $partitions
     */
    #[DataProvider('monthEdges')]
    public function test_next_month_is_the_calendar_month_after_the_current_one(string $now, array $partitions): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($now));

        $alpha = $this->tenant('alpha');
        $this->expectPartitions($this->connectionFor($alpha), $partitions);
        $this->bindCommand([$alpha]);

        $this->artisan('conversations:create-partition')
            ->expectsOutput("✔ alpha: {$partitions[0][0]}, {$partitions[1][0]}")
            ->assertSuccessful();
    }

    public function test_a_failing_tenant_does_not_stop_the_remaining_ones_and_fails_the_run(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 10:00:00 UTC'));

        $alpha = $this->tenant('alpha');
        $beta  = $this->tenant('beta');

        $this->connectionFor($alpha)
            ->shouldReceive('statement')->once()->andThrow(new RuntimeException('schema is gone'));

        $this->expectPartitions($this->connectionFor($beta), [
            ['conversation_messages_2026_03', '2026-03-01 00:00:00+00:00', '2026-04-01 00:00:00+00:00'],
            ['conversation_messages_2026_04', '2026-04-01 00:00:00+00:00', '2026-05-01 00:00:00+00:00'],
        ]);

        $this->bindCommand([$alpha, $beta]);

        $this->artisan('conversations:create-partition')
            ->expectsOutput('✘ alpha: schema is gone')
            ->expectsOutput('✔ beta: conversation_messages_2026_03, conversation_messages_2026_04')
            ->assertFailed();
    }

    public function test_it_is_scheduled_daily(): void
    {
        $event = collect($this->app->make(Schedule::class)->events())
            ->first(static fn (Event $event): bool => str_contains((string)$event->command, 'conversations:create-partition'));

        $this->assertNotNull($event);
        $this->assertSame('45 2 * * *', $event->expression);
    }

    /**
     * @param ConnectionInterface&MockInterface      $connection
     * @param list<array{string, string, string}> $partitions name, from, to
     */
    private function expectPartitions(MockInterface $connection, array $partitions): void
    {
        foreach ($partitions as [$name, $from, $to]) {
            $connection->shouldReceive('statement')->once()->with(
                "CREATE TABLE IF NOT EXISTS {$name} PARTITION OF conversation_messages FOR VALUES FROM ('{$from}') TO ('{$to}')",
            )->andReturnTrue();
        }
    }

    /**
     * @param array<int, TenantInterface> $tenants
     */
    private function bindCommand(array $tenants): void
    {
        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldReceive('findAllActive')->once()->andReturn($tenants);

        $tenantDatabase = Mockery::mock(TenantDatabaseManagerInterface::class);
        $tenantDatabase->shouldReceive('switchTo')->andReturnUsing(function (TenantInterface $tenant): void {
            $this->currentTenant = $tenant;
        });
        $tenantDatabase->shouldReceive('restore')->andReturnUsing(function (): void {
            $this->currentTenant = null;
        });

        $permissions = Mockery::mock(PermissionRegistrar::class);
        $permissions->shouldReceive('clearPermissionsCollection');

        $switcher = new TenantSwitcher(new TenantContext(), $tenantDatabase, $permissions);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->andReturnUsing(function (): ConnectionInterface {
            if (null === $this->currentTenant) {
                throw new RuntimeException('connection() resolved outside tenant context');
            }

            return $this->connections[spl_object_id($this->currentTenant)];
        });

        $this->app->instance(
            CreateConversationMessagePartitionsCommand::class,
            new CreateConversationMessagePartitionsCommand($repository, $switcher, $database),
        );
    }

    /**
     * @return ConnectionInterface&MockInterface
     */
    private function connectionFor(TenantInterface $tenant): MockInterface
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');

        $this->connections[spl_object_id($tenant)] = $connection;

        return $connection;
    }

    private function tenant(string $slug): TenantInterface
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('getSlug')->andReturn($slug);
        $tenant->shouldReceive('getId')->andReturn($slug);

        return $tenant;
    }
}
