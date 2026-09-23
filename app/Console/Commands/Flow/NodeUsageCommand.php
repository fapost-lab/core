<?php

declare(strict_types=1);

namespace App\Console\Commands\Flow;

use App\Domains\Flow\Contracts\NodeUsageStatisticsInterface;
use App\Domains\Flow\Statistics\NodeTypeUsage;
use App\Domains\Flow\Statistics\NodeUsageReport;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

/**
 * Reports which node types are still in use, per tenant and platform-wide.
 *
 * Answers the question a handler removal asks: `flow_definitions` and `flow_logs`
 * both live in the tenant schema, so "does anyone still use `send_message@1`"
 * cannot be read off one connection — every tenant has to be visited and the
 * answers combined. The platform summary at the end is that combination.
 */
final class NodeUsageCommand extends Command
{
    /**
     * Days of `flow_logs` history that survive, mirroring the cutoff in
     * {@see \App\Console\Commands\PruneFlowLogsCommand}. It is a hardcoded
     * constant there rather than configuration, so this one has to be kept in
     * step by hand; it is used to warn, never to silently shrink the window the
     * caller asked for.
     */
    private const int LOG_RETENTION_DAYS = 30;

    protected $signature = 'flow:node-usage
        {--days=30 : Runtime window in days; capped in practice by flow_logs retention}
        {--tenant= : Limit the report to one tenant slug}
        {--type= : Limit the report to one node type}
        {--json : Emit machine-readable JSON instead of tables}';

    protected $description = 'Reports node type usage across active flow definitions and runtime logs for every active tenant';

    public function __construct(
        private readonly TenantRepositoryInterface $tenants,
        private readonly TenantSwitcher $tenantSwitcher,
        private readonly NodeUsageStatisticsInterface $statistics,
    ) {
        parent::__construct();
    }

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');

        if ($days < 1) {
            $this->error('--days must be a positive integer.');

            return self::FAILURE;
        }

        $tenants = $this->resolveTenants();

        if (null === $tenants) {
            return self::FAILURE;
        }

        $since      = CarbonImmutable::now()->subDays($days);
        $typeFilter = $this->stringOption('type');
        $asJson     = (bool) $this->option('json');

        /** @var array<string, NodeUsageReport> $reports */
        $reports = [];
        $failed  = 0;

        foreach ($tenants as $tenant) {
            $slug = $tenant->getSlug();

            try {
                $reports[$slug] = $this->tenantSwitcher->runForTenant(
                    $tenant,
                    fn (): NodeUsageReport => $this->statistics->reportForCurrentTenant($since),
                );
            } catch (Throwable $throwable) {
                $failed++;

                if ($asJson) {
                    continue;
                }

                $this->line("✘ {$slug}: {$throwable->getMessage()}");
            }
        }

        if ($asJson) {
            $this->line(json_encode(
                $this->toJsonPayload($since, $days, $reports, $typeFilter, $failed),
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->renderWindowNotice($days, $since);
        $this->renderReports($reports, $typeFilter);
        $this->renderPlatformSummary($reports, $typeFilter);

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<TenantInterface>|null null when the requested slug does not exist
     */
    private function resolveTenants(): ?array
    {
        $slug = $this->stringOption('tenant');

        if (null === $slug) {
            return array_values($this->tenants->findAllActive());
        }

        $tenant = $this->tenants->findBySlug($slug);

        if (null === $tenant) {
            $this->error("Unknown tenant: {$slug}");

            return null;
        }

        return [$tenant];
    }

    /**
     * States the runtime slice's blind spot before any number is shown.
     *
     * `logs:prune-flow` drops `flow_logs` partitions older than
     * {@see self::LOG_RETENTION_DAYS}, so no window reaches past that however
     * large `--days` is. The static columns have no window at all, which is why
     * they, not the execution counts, are the authoritative answer to "is this
     * node type still in use".
     */
    private function renderWindowNotice(int $days, CarbonImmutable $since): void
    {
        $this->info(sprintf('Runtime window: %d day(s), since %s UTC.', $days, $since->toDateTimeString()));

        if ($days > self::LOG_RETENTION_DAYS) {
            $this->warn(sprintf(
                '  flow_logs keeps about %d days, so the window is capped there in practice: executions older than that were pruned and cannot be counted.',
                self::LOG_RETENTION_DAYS,
            ));
        }

        $this->line('  "executions"/"failures"/"last run" see only this window; "active flows"/"active nodes" read current definitions and have no window.');
    }

    /**
     * @param  array<string, NodeUsageReport>  $reports
     */
    private function renderReports(array $reports, ?string $typeFilter): void
    {
        foreach ($reports as $slug => $report) {
            $usages = $this->filterUsages($report->usages, $typeFilter);

            $this->newLine();
            $this->line("<comment>{$slug}</comment>");

            if ([] === $usages) {
                $this->line('  no node usage found');

                continue;
            }

            $this->table(
                ['type', 'ver', 'handler', 'active flows', 'active nodes', 'executions', 'failures', 'last run'],
                array_map(
                    static fn (NodeTypeUsage $usage): array => [
                        $usage->nodeType,
                        (string) $usage->nodeVersion,
                        $usage->handlerRegistered ? 'yes' : 'MISSING',
                        (string) $usage->activeFlows,
                        (string) $usage->activeNodes,
                        (string) $usage->executions,
                        (string) $usage->failures,
                        $usage->lastExecutedAt?->toDateTimeString() ?? '-',
                    ],
                    $usages,
                ),
            );
        }
    }

    /**
     * @param  array<string, NodeUsageReport>  $reports
     */
    private function renderPlatformSummary(array $reports, ?string $typeFilter): void
    {
        $this->newLine();
        $this->info('Platform summary');

        if ([] === $reports) {
            $this->warn('  no tenant was scanned, so nothing can be called unused');

            return;
        }

        $orphaned = $this->orphanedAcrossTenants($reports, $typeFilter);

        if ([] === $orphaned) {
            $this->line('  every node in an active definition has a registered handler');
        } else {
            $this->warn('  nodes in active definitions with no registered handler:');

            foreach ($orphaned as $key => $slugs) {
                sort($slugs);
                $this->line("    {$key} — " . implode(', ', $slugs));
            }
        }

        $unused = $this->unusedEverywhere($reports, $typeFilter);

        if ([] === $unused) {
            $this->line('  no registered handler type is unused across every scanned tenant');

            return;
        }

        $this->line('  handler types unused by every scanned tenant (safe to retire):');
        $this->line('    ' . implode(', ', $unused));
    }

    /**
     * Node keys with no handler, mapped to the tenants that still contain them.
     *
     * @param  array<string, NodeUsageReport>  $reports
     * @return array<string, list<string>>
     */
    private function orphanedAcrossTenants(array $reports, ?string $typeFilter): array
    {
        $orphaned = [];

        foreach ($reports as $slug => $report) {
            foreach ($this->filterUsages($report->orphaned(), $typeFilter) as $usage) {
                $orphaned[$usage->key()][] = $slug;
            }
        }

        ksort($orphaned);

        return $orphaned;
    }

    /**
     * Handler types every scanned tenant reported as unused.
     *
     * An intersection, not a union: a type still used by one tenant is not
     * retirable, however many others have dropped it. With no tenant scanned at
     * all the answer is empty rather than "everything is unused".
     *
     * @param  array<string, NodeUsageReport>  $reports
     * @return list<string>
     */
    private function unusedEverywhere(array $reports, ?string $typeFilter): array
    {
        if ([] === $reports) {
            return [];
        }

        $unused = null;

        foreach ($reports as $report) {
            $unused = null === $unused
                ? $report->unusedHandlerTypes
                : array_intersect($unused, $report->unusedHandlerTypes);
        }

        $unused = array_values($unused ?? []);

        if (null !== $typeFilter) {
            $unused = array_values(array_filter($unused, static fn (string $type): bool => $type === $typeFilter));
        }

        return $unused;
    }

    /**
     * @param  list<NodeTypeUsage>  $usages
     * @return list<NodeTypeUsage>
     */
    private function filterUsages(array $usages, ?string $typeFilter): array
    {
        if (null === $typeFilter) {
            return $usages;
        }

        return array_values(array_filter(
            $usages,
            static fn (NodeTypeUsage $usage): bool => $usage->nodeType === $typeFilter,
        ));
    }

    /**
     * @param  array<string, NodeUsageReport>  $reports
     * @return array<string, mixed>
     */
    private function toJsonPayload(
        CarbonImmutable $since,
        int $days,
        array $reports,
        ?string $typeFilter,
        int $failed,
    ): array {
        $tenants = [];

        foreach ($reports as $slug => $report) {
            $tenants[$slug] = array_map(
                static fn (NodeTypeUsage $usage): array => [
                    'node_type'          => $usage->nodeType,
                    'node_version'       => $usage->nodeVersion,
                    'handler_registered' => $usage->handlerRegistered,
                    'active_flows'       => $usage->activeFlows,
                    'active_nodes'       => $usage->activeNodes,
                    'executions'         => $usage->executions,
                    'failures'           => $usage->failures,
                    'last_executed_at'   => $usage->lastExecutedAt?->toIso8601String(),
                ],
                $this->filterUsages($report->usages, $typeFilter),
            );
        }

        return [
            'runtime_since'       => $since->toIso8601String(),
            'runtime_window_days' => $days,
            // A machine consumer sees no printed warning, so the retention
            // boundary has to travel with the data.
            'log_retention_days'       => self::LOG_RETENTION_DAYS,
            'window_exceeds_retention' => $days > self::LOG_RETENTION_DAYS,
            'failed_tenants'           => $failed,
            'tenants'                  => $tenants,
            'orphaned'                 => $this->orphanedAcrossTenants($reports, $typeFilter),
            'unused_everywhere'        => $this->unusedEverywhere($reports, $typeFilter),
        ];
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return '' === $value ? null : $value;
    }
}
