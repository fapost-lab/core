<?php

declare(strict_types=1);

namespace App\Domains\Flow\Statistics;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\NodeUsageStatisticsInterface;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowLog;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Node usage for the tenant whose schema the default connection currently points at.
 *
 * Two independent slices, both derived from data that already exists:
 *
 * - **static** — what active `flow_definitions` contain. Authoritative and
 *   unbounded in time: a node in an active definition will run sooner or later.
 * - **runtime** — what `flow_logs` recorded. Bounded by the retention window
 *   ({@see \App\Console\Commands\PruneFlowLogsCommand} drops partitions older
 *   than 30 days), but it is a complete record inside that window: `FlowEngine`
 *   writes a row for every node it executes, regardless of `logging_enabled`
 *   (that flag gates `flow_session_history` only).
 *
 * The static slice is aggregated in PHP rather than pushed into SQL on purpose.
 * Expanding the `nodes` jsonb in the database needs `jsonb_array_elements` on
 * PostgreSQL and `json_each` on SQLite, which would mean the test suite exercises
 * a different statement than production runs. Active definitions are bounded — the
 * partial unique index `flow_definitions_active_unique` allows one per flow — so
 * counting them in PHP costs little and keeps one code path on both drivers.
 *
 * Holds no connection and no tenant state, so a single instance stays correct
 * across {@see \App\Domains\Tenancy\Services\TenantSwitcher::runForTenant()} switches.
 */
final readonly class NodeUsageStatisticsService implements NodeUsageStatisticsInterface
{
    public function __construct(
        private NodeHandlerRegistryInterface $registry,
    ) {
    }

    public function reportForCurrentTenant(CarbonImmutable $runtimeSince): NodeUsageReport
    {
        $static  = $this->collectStaticUsage();
        $runtime = $this->collectRuntimeUsage($runtimeSince);

        $usages = [];

        foreach ($this->mergedKeys($static, $runtime) as $nodeType => $versions) {
            foreach ($versions as $nodeVersion) {
                $staticEntry  = $static[$nodeType][$nodeVersion] ?? null;
                $runtimeEntry = $runtime[$nodeType][$nodeVersion] ?? null;

                $usages[] = new NodeTypeUsage(
                    nodeType: $nodeType,
                    nodeVersion: $nodeVersion,
                    activeFlows: null === $staticEntry ? 0 : count($staticEntry['flows']),
                    activeNodes: $staticEntry['nodes'] ?? 0,
                    executions: $runtimeEntry['executions'] ?? 0,
                    failures: $runtimeEntry['failures'] ?? 0,
                    lastExecutedAt: $runtimeEntry['last_executed_at'] ?? null,
                    handlerRegistered: $this->registry->has($nodeType, $nodeVersion),
                );
            }
        }

        return new NodeUsageReport(
            runtimeSince: $runtimeSince,
            usages: $usages,
            unusedHandlerTypes: $this->unusedHandlerTypes($static, $runtime),
        );
    }

    /**
     * Node occurrences in active definitions, keyed by type and version.
     *
     * @return array<string, array<int, array{nodes: int, flows: array<string, true>}>>
     */
    private function collectStaticUsage(): array
    {
        $usage = [];

        $definitions = FlowDefinition::query()
            ->active()
            ->select(['id', 'flow_id', 'nodes'])
            ->lazyById();

        foreach ($definitions as $definition) {
            $flowId = (string) $definition->flow_id;

            foreach ($definition->nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                $nodeType = $node['type'] ?? null;

                if (! is_string($nodeType) || '' === $nodeType) {
                    continue;
                }

                $nodeVersion = $this->nodeVersion($node);

                $usage[$nodeType][$nodeVersion]['nodes']          = ($usage[$nodeType][$nodeVersion]['nodes'] ?? 0) + 1;
                $usage[$nodeType][$nodeVersion]['flows'][$flowId] = true;
            }
        }

        return $usage;
    }

    /**
     * Executions logged since `$since`, keyed by type and version.
     *
     * Plain aggregate SQL — no JSON functions and no `AT TIME ZONE` — so the
     * statement is identical on PostgreSQL and SQLite. Partitioning is
     * transparent to reads through the `flow_logs` root.
     *
     * @return array<string, array<int, array{executions: int, failures: int, last_executed_at: ?CarbonImmutable}>>
     */
    private function collectRuntimeUsage(CarbonImmutable $since): array
    {
        $rows = FlowLog::query()
            ->toBase()
            ->where('created_at', '>=', $since)
            ->groupBy('node_type', 'node_version')
            ->selectRaw(
                'node_type, node_version, COUNT(*) AS executions, '
                . 'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS failures, '
                . 'MAX(created_at) AS last_executed_at',
                [FlowLogStatus::Failed->value],
            )
            ->get();

        $usage = [];

        foreach ($rows as $row) {
            $nodeType = (string) $row->node_type;

            if ('' === $nodeType) {
                continue;
            }

            $usage[$nodeType][(int) $row->node_version] = [
                'executions'       => (int) $row->executions,
                'failures'         => (int) $row->failures,
                'last_executed_at' => $this->toCarbon($row->last_executed_at),
            ];
        }

        return $usage;
    }

    /**
     * Registered handler types absent from both slices: nothing references them,
     * so retiring the type as a whole breaks no tenant data.
     *
     * The registry resolves by `type@version` but `all()` exposes only the latest
     * version of each type, so this answers at type granularity. A per-version
     * answer is read off the report's own rows.
     *
     * @param  array<string, array<int, mixed>>  $static
     * @param  array<string, array<int, mixed>>  $runtime
     * @return list<string>
     */
    private function unusedHandlerTypes(array $static, array $runtime): array
    {
        $unused = [];

        foreach ($this->registry->all() as $handler) {
            $nodeType = $handler->type();

            if (isset($static[$nodeType]) || isset($runtime[$nodeType])) {
                continue;
            }

            $unused[] = $nodeType;
        }

        sort($unused);

        return $unused;
    }

    /**
     * Every `type@version` seen in either slice, types sorted and versions ascending.
     *
     * @param  array<string, array<int, mixed>>  $static
     * @param  array<string, array<int, mixed>>  $runtime
     * @return array<string, list<int>>
     */
    private function mergedKeys(array $static, array $runtime): array
    {
        $keys = [];

        foreach ([$static, $runtime] as $slice) {
            foreach ($slice as $nodeType => $versions) {
                foreach (array_keys($versions) as $nodeVersion) {
                    $keys[$nodeType][$nodeVersion] = true;
                }
            }
        }

        ksort($keys);

        return array_map(
            static function (array $versions): array {
                $versions = array_keys($versions);
                sort($versions);

                return $versions;
            },
            $keys,
        );
    }

    /**
     * Mirrors {@see \App\Domains\Flow\Services\FlowEngine::resolveNodeVersion()}.
     *
     * It has to: the runtime slice is keyed by the version the engine actually
     * logged, so a definition whose node omits `version` must land on the same
     * key (1) as its log rows, or the two slices would never line up.
     *
     * @param  array<string, mixed>  $node
     */
    private function nodeVersion(array $node): int
    {
        $raw = $node['version'] ?? 1;

        if (is_int($raw)) {
            return $raw;
        }

        if (is_numeric($raw)) {
            return (int) $raw;
        }

        return 1;
    }

    private function toCarbon(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || '' === $value) {
            return null;
        }

        return CarbonImmutable::parse($value);
    }
}
