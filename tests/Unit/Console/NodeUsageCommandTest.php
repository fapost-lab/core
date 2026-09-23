<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use App\Console\Commands\Flow\NodeUsageCommand;
use App\Domains\Flow\Contracts\NodeUsageStatisticsInterface;
use App\Domains\Flow\Statistics\NodeTypeUsage;
use App\Domains\Flow\Statistics\NodeUsageReport;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantContext;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Mockery;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The tenant infrastructure is mocked on purpose: a real
 * {@see TenantSwitcher} purges the default connection, which on the suite's
 * in-memory SQLite would throw the seeded data away mid-run.
 */
final class NodeUsageCommandTest extends TestCase
{
    /** @var array<int, NodeUsageReport|RuntimeException> keyed by tenant object id */
    private array $reports = [];

    private ?TenantInterface $currentTenant = null;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_reports_usage_per_tenant(): void
    {
        $alpha = $this->tenant('alpha');

        $this->reports[spl_object_id($alpha)] = new NodeUsageReport(
            runtimeSince: CarbonImmutable::now()->subDays(30),
            usages: [$this->usage('usage_alpha', 1, activeFlows: 2, activeNodes: 3, executions: 10, failures: 1)],
            unusedHandlerTypes: [],
        );

        $this->bindCommand([$alpha]);

        $this->artisan('flow:node-usage')
            ->expectsOutputToContain('alpha')
            ->expectsTable(
                ['type', 'ver', 'handler', 'active flows', 'active nodes', 'executions', 'failures', 'last run'],
                [['usage_alpha', '1', 'yes', '2', '3', '10', '1', '2026-09-20 10:00:00']],
            )
            ->assertSuccessful();
    }

    public function test_a_type_used_by_one_tenant_is_not_reported_as_retirable(): void
    {
        $alpha = $this->tenant('alpha');
        $beta  = $this->tenant('beta');

        // `shared_unused` is unused by both; `beta_only` is unused by alpha
        // alone, so the intersection must drop it.
        $this->reports[spl_object_id($alpha)] = new NodeUsageReport(
            runtimeSince: CarbonImmutable::now()->subDays(30),
            usages: [],
            unusedHandlerTypes: ['beta_only', 'shared_unused'],
        );

        $this->reports[spl_object_id($beta)] = new NodeUsageReport(
            runtimeSince: CarbonImmutable::now()->subDays(30),
            usages: [$this->usage('beta_only', 1, activeFlows: 1, activeNodes: 1, executions: 0, failures: 0)],
            unusedHandlerTypes: ['shared_unused'],
        );

        $payload = $this->jsonFrom($this->artisanOutput([$alpha, $beta], ['--json' => true]));

        $this->assertSame(['shared_unused'], $payload['unused_everywhere']);
    }

    public function test_json_output_carries_orphaned_nodes_with_the_tenants_that_hold_them(): void
    {
        $alpha = $this->tenant('alpha');

        $this->reports[spl_object_id($alpha)] = new NodeUsageReport(
            runtimeSince: CarbonImmutable::now()->subDays(30),
            usages: [
                $this->usage('usage_alpha', 1, activeFlows: 1, activeNodes: 1, executions: 5, failures: 0),
                $this->usage('usage_alpha', 2, activeFlows: 1, activeNodes: 1, executions: 0, failures: 0, handlerRegistered: false),
            ],
            unusedHandlerTypes: [],
        );

        $payload = $this->jsonFrom($this->artisanOutput([$alpha], ['--json' => true]));

        $this->assertSame(['usage_alpha@2' => ['alpha']], $payload['orphaned']);
        $this->assertCount(2, $payload['tenants']['alpha']);
        $this->assertSame(5, $payload['tenants']['alpha'][0]['executions']);
        $this->assertFalse($payload['tenants']['alpha'][1]['handler_registered']);
    }

    public function test_type_filter_narrows_the_report(): void
    {
        $alpha = $this->tenant('alpha');

        $this->reports[spl_object_id($alpha)] = new NodeUsageReport(
            runtimeSince: CarbonImmutable::now()->subDays(30),
            usages: [
                $this->usage('usage_alpha', 1, activeFlows: 1, activeNodes: 1, executions: 1, failures: 0),
                $this->usage('usage_beta', 1, activeFlows: 1, activeNodes: 1, executions: 1, failures: 0),
            ],
            unusedHandlerTypes: [],
        );

        $payload = $this->jsonFrom($this->artisanOutput([$alpha], ['--json' => true, '--type' => 'usage_beta']));

        $this->assertCount(1, $payload['tenants']['alpha']);
        $this->assertSame('usage_beta', $payload['tenants']['alpha'][0]['node_type']);
    }

    public function test_a_failing_tenant_does_not_stop_the_remaining_ones(): void
    {
        $alpha = $this->tenant('alpha');
        $beta  = $this->tenant('beta');

        $this->reports[spl_object_id($alpha)] = new RuntimeException('schema is gone');
        $this->reports[spl_object_id($beta)]  = new NodeUsageReport(
            runtimeSince: CarbonImmutable::now()->subDays(30),
            usages: [$this->usage('usage_alpha', 1, activeFlows: 1, activeNodes: 1, executions: 1, failures: 0)],
            unusedHandlerTypes: [],
        );

        $this->bindCommand([$alpha, $beta]);

        $this->artisan('flow:node-usage')
            ->expectsOutputToContain('✘ alpha: schema is gone')
            ->expectsOutputToContain('beta')
            ->assertFailed();
    }

    public function test_it_warns_when_the_requested_window_outruns_log_retention(): void
    {
        $alpha = $this->tenant('alpha');

        $this->reports[spl_object_id($alpha)] = new NodeUsageReport(
            runtimeSince: CarbonImmutable::now()->subDays(90),
            usages: [],
            unusedHandlerTypes: [],
        );

        $this->bindCommand([$alpha]);

        $this->artisan('flow:node-usage', ['--days' => '90'])
            ->expectsOutputToContain('flow_logs keeps about 30 days')
            ->assertSuccessful();
    }

    public function test_a_window_inside_retention_is_not_flagged(): void
    {
        $alpha = $this->tenant('alpha');

        $this->reports[spl_object_id($alpha)] = new NodeUsageReport(
            runtimeSince: CarbonImmutable::now()->subDays(7),
            usages: [],
            unusedHandlerTypes: [],
        );

        $payload = $this->jsonFrom($this->artisanOutput([$alpha], ['--json' => true, '--days' => '7']));

        $this->assertSame(7, $payload['runtime_window_days']);
        $this->assertFalse($payload['window_exceeds_retention']);
    }

    public function test_json_payload_carries_the_retention_boundary(): void
    {
        $alpha = $this->tenant('alpha');

        $this->reports[spl_object_id($alpha)] = new NodeUsageReport(
            runtimeSince: CarbonImmutable::now()->subDays(90),
            usages: [],
            unusedHandlerTypes: [],
        );

        // A machine consumer never sees the printed warning, so the boundary
        // has to be readable from the payload itself.
        $payload = $this->jsonFrom($this->artisanOutput([$alpha], ['--json' => true, '--days' => '90']));

        $this->assertSame(30, $payload['log_retention_days']);
        $this->assertTrue($payload['window_exceeds_retention']);
    }

    public function test_an_unknown_tenant_slug_fails_without_scanning(): void
    {
        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldReceive('findBySlug')->once()->with('ghost')->andReturnNull();
        $repository->shouldNotReceive('findAllActive');

        $this->bindCommandWith($repository);

        $this->artisan('flow:node-usage', ['--tenant' => 'ghost'])
            ->expectsOutput('Unknown tenant: ghost')
            ->assertFailed();
    }

    public function test_a_non_positive_window_is_rejected(): void
    {
        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldNotReceive('findAllActive');

        $this->bindCommandWith($repository);

        $this->artisan('flow:node-usage', ['--days' => '0'])
            ->expectsOutput('--days must be a positive integer.')
            ->assertFailed();
    }

    /**
     * Runs the command and hands back everything it printed.
     *
     * @param  list<TenantInterface>       $tenants
     * @param  array<string, string|bool>  $options
     */
    private function artisanOutput(array $tenants, array $options): string
    {
        $this->bindCommand($tenants);

        // Artisan::call() rather than $this->artisan(): the PendingCommand
        // helper keeps its output in its own buffer for expectation matching,
        // and these assertions need the printed JSON itself.
        Artisan::call('flow:node-usage', $options);

        return Artisan::output();
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFrom(string $output): array
    {
        $start = mb_strpos($output, '{');

        $this->assertNotFalse($start, 'command produced no JSON');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode(mb_substr($output, $start), true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    private function usage(
        string $nodeType,
        int $nodeVersion,
        int $activeFlows,
        int $activeNodes,
        int $executions,
        int $failures,
        bool $handlerRegistered = true,
    ): NodeTypeUsage {
        return new NodeTypeUsage(
            nodeType: $nodeType,
            nodeVersion: $nodeVersion,
            activeFlows: $activeFlows,
            activeNodes: $activeNodes,
            executions: $executions,
            failures: $failures,
            lastExecutedAt: 0 === $executions ? null : CarbonImmutable::parse('2026-09-20 10:00:00 UTC'),
            handlerRegistered: $handlerRegistered,
        );
    }

    /**
     * @param  list<TenantInterface>  $tenants
     */
    private function bindCommand(array $tenants): void
    {
        $repository = Mockery::mock(TenantRepositoryInterface::class);
        $repository->shouldReceive('findAllActive')->andReturn($tenants);

        $this->bindCommandWith($repository);
    }

    private function bindCommandWith(TenantRepositoryInterface $repository): void
    {
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

        $statistics = Mockery::mock(NodeUsageStatisticsInterface::class);
        $statistics->shouldReceive('reportForCurrentTenant')->andReturnUsing(function (): NodeUsageReport {
            if (null === $this->currentTenant) {
                throw new RuntimeException('report requested outside tenant context');
            }

            $report = $this->reports[spl_object_id($this->currentTenant)];

            if ($report instanceof RuntimeException) {
                throw $report;
            }

            return $report;
        });

        $this->app->instance(
            NodeUsageCommand::class,
            new NodeUsageCommand($repository, $switcher, $statistics),
        );
    }

    private function tenant(string $slug): TenantInterface
    {
        $tenant = Mockery::mock(TenantInterface::class);
        $tenant->shouldReceive('getSlug')->andReturn($slug);

        return $tenant;
    }
}
