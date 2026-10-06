<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\PruneConversationMessagesCommand;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class PruneConversationMessagesCommandTest extends TestCase
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

    public function test_it_does_nothing_when_retention_is_disabled(): void
    {
        config(['conversation.retention_days' => null]);

        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldNotReceive('findAllActive');

        $this->app->instance(
            PruneConversationMessagesCommand::class,
            new PruneConversationMessagesCommand(
                $repository,
                new TenantSwitcher(
                    new TenantContext(),
                    Mockery::mock(TenantDatabaseManagerInterface::class),
                    Mockery::mock(PermissionRegistrar::class),
                ),
                Mockery::mock(DatabaseManager::class),
            ),
        );

        $this->artisan('conversations:prune')
            ->expectsOutputToContain('retention is disabled')
            ->assertSuccessful();
    }

    public function test_it_drops_only_expired_partitions_in_every_tenant_schema(): void
    {
        config(['conversation.retention_days' => 30]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 00:00:00 UTC'));

        $alpha = $this->tenant('alpha');
        $beta  = $this->tenant('beta');

        $this->connectionFor($alpha, ['conversation_messages_2026_01', 'conversation_messages_2026_02', 'conversation_messages_2026_03'])
            ->shouldReceive('statement')->once()->with("SET LOCAL lock_timeout = '5s'")->shouldReceive('statement')->once()->with('DROP TABLE IF EXISTS "tenant_x"."conversation_messages_2026_01"');

        $this->connectionFor($beta, ['conversation_messages_2025_12', 'conversation_messages_2026_03'])
            ->shouldReceive('statement')->once()->with("SET LOCAL lock_timeout = '5s'")->shouldReceive('statement')->once()->with('DROP TABLE IF EXISTS "tenant_x"."conversation_messages_2025_12"');

        $this->bindCommand([$alpha, $beta]);

        $this->artisan('conversations:prune')
            ->expectsOutput('✔ alpha: dropped 1')
            ->expectsOutput('✔ beta: dropped 1')
            ->expectsOutput('Dropped partitions: 2')
            ->assertSuccessful();
    }

    public function test_dry_run_counts_partitions_without_dropping_them(): void
    {
        config(['conversation.retention_days' => 30]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 00:00:00 UTC'));

        $alpha = $this->tenant('alpha');

        $this->connectionFor($alpha, ['conversation_messages_2025_12', 'conversation_messages_2026_01', 'conversation_messages_2026_03'])
            ->shouldNotReceive('statement');

        $this->bindCommand([$alpha]);

        $this->artisan('conversations:prune', ['--dry-run' => true])
            ->expectsOutput('✔ alpha: dropped 2')
            ->assertSuccessful();
    }

    public function test_a_failing_tenant_does_not_stop_the_remaining_ones_and_fails_the_run(): void
    {
        config(['conversation.retention_days' => 30]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-15 00:00:00 UTC'));

        $alpha = $this->tenant('alpha');
        $beta  = $this->tenant('beta');

        $broken = Mockery::mock(ConnectionInterface::class);
        $broken->shouldReceive('getDriverName')->andReturn('pgsql');
        $broken->shouldReceive('select')->once()->andThrow(new RuntimeException('schema is gone'));
        $this->connections[spl_object_id($alpha)] = $broken;

        $this->connectionFor($beta, ['conversation_messages_2026_01'])
            ->shouldReceive('statement')->once()->with("SET LOCAL lock_timeout = '5s'")->shouldReceive('statement')->once()->with('DROP TABLE IF EXISTS "tenant_x"."conversation_messages_2026_01"');

        $this->bindCommand([$alpha, $beta]);

        $this->artisan('conversations:prune')
            ->expectsOutput('✘ alpha: schema is gone')
            ->expectsOutput('✔ beta: dropped 1')
            ->assertFailed();
    }

    public function test_it_warns_when_the_retention_env_is_set_but_rejected(): void
    {
        putenv('CONVERSATION_RETENTION_DAYS=12m');
        $_ENV['CONVERSATION_RETENTION_DAYS'] = '12m';

        try {
            config(['conversation.retention_days' => null]);

            $this->artisan('conversations:prune')
                ->expectsOutputToContain('set but rejected')
                ->expectsOutputToContain('retention is disabled')
                ->assertSuccessful();
        } finally {
            putenv('CONVERSATION_RETENTION_DAYS');
            unset($_ENV['CONVERSATION_RETENTION_DAYS']);
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
            PruneConversationMessagesCommand::class,
            new PruneConversationMessagesCommand($repository, $switcher, $database),
        );
    }

    /**
     * @param array<int, string> $partitionNames
     *
     * @return ConnectionInterface&MockInterface
     */
    private function connectionFor(TenantInterface $tenant, array $partitionNames): MockInterface
    {
        $connection = Mockery::mock(ConnectionInterface::class);
        $connection->shouldReceive('getDriverName')->andReturn('pgsql');
        $connection->shouldReceive('selectOne')->andReturn((object)['schema_name' => 'tenant_x']);
        $connection->shouldReceive('transaction')->andReturnUsing(static fn (callable $callback): mixed => $callback());
        $connection->shouldReceive('select')->once()->andReturn(array_map(
            static fn (string $name): object => (object)['partition_name' => $name],
            $partitionNames,
        ));

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
