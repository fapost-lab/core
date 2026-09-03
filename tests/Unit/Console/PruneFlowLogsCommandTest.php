<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\PruneFlowLogsCommand;
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

final class PruneFlowLogsCommandTest extends TestCase
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

    public function test_it_drops_only_partitions_older_than_30_days_in_every_tenant_schema(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 00:00:00 UTC'));

        $alpha = $this->tenant('alpha');
        $beta  = $this->tenant('beta');

        $this->connectionFor($alpha, ['flow_logs_2026_02', 'flow_logs_2026_03', 'flow_logs_2026_04'])
            ->shouldReceive('statement')->once()->with('DROP TABLE IF EXISTS flow_logs_2026_02');

        $this->connectionFor($beta, ['flow_logs_2026_01', 'flow_logs_2026_04'])
            ->shouldReceive('statement')->once()->with('DROP TABLE IF EXISTS flow_logs_2026_01');

        $this->bindCommand([$alpha, $beta]);

        $this->artisan('logs:prune-flow')
            ->expectsOutput('✔ alpha: dropped 1')
            ->expectsOutput('✔ beta: dropped 1')
            ->expectsOutput('Dropped partitions: 2')
            ->assertSuccessful();
    }

    public function test_dry_run_counts_partitions_without_dropping_them(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 00:00:00 UTC'));

        $alpha = $this->tenant('alpha');

        $connection = $this->connectionFor($alpha, ['flow_logs_2026_01', 'flow_logs_2026_02', 'flow_logs_2026_04']);
        $connection->shouldNotReceive('statement');

        $this->bindCommand([$alpha]);

        $this->artisan('logs:prune-flow', ['--dry-run' => true])
            ->expectsOutput('✔ alpha: dropped 2')
            ->expectsOutput('Dropped partitions: 2')
            ->assertSuccessful();
    }

    public function test_a_failing_tenant_does_not_stop_the_remaining_ones(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-21 00:00:00 UTC'));

        $alpha = $this->tenant('alpha');
        $beta  = $this->tenant('beta');

        $broken = Mockery::mock(ConnectionInterface::class);
        $broken->shouldReceive('select')->once()->andThrow(new RuntimeException('schema is gone'));
        $this->connections[spl_object_id($alpha)] = $broken;

        $this->connectionFor($beta, ['flow_logs_2026_02'])
            ->shouldReceive('statement')->once()->with('DROP TABLE IF EXISTS flow_logs_2026_02');

        $this->bindCommand([$alpha, $beta]);

        $this->artisan('logs:prune-flow')
            ->expectsOutput('✘ alpha: schema is gone')
            ->expectsOutput('✔ beta: dropped 1')
            ->expectsOutput('Dropped partitions: 1')
            ->assertFailed();
    }

    /**
     * Registers the command with mocked tenant infrastructure.
     *
     * The database manager hands out a per-tenant connection based on the tenant
     * the switcher has activated, so a partition manager built outside the
     * switch (the bug this guards) blows up instead of silently pruning nothing.
     *
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
        $permissions->shouldReceive('forgetCachedPermissions');

        $switcher = new TenantSwitcher(new TenantContext(), $tenantDatabase, $permissions);

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('connection')->andReturnUsing(function (): ConnectionInterface {
            if (null === $this->currentTenant) {
                throw new RuntimeException('connection() resolved outside tenant context');
            }

            return $this->connections[spl_object_id($this->currentTenant)];
        });

        $this->app->instance(
            PruneFlowLogsCommand::class,
            new PruneFlowLogsCommand($repository, $switcher, $database),
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

        return $tenant;
    }
}
