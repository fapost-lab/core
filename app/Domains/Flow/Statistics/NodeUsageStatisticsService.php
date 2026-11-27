<?php

declare(strict_types=1);

namespace App\Domains\Flow\Statistics;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Call\Transports\HandlerTransport;
use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Contracts\NodeUsageStatisticsInterface;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Logging\FlowLogStatus;
use App\Domains\Flow\Support\TimestampWindow;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * Node and action usage for the tenant of the current tenant context, for `flow:node-usage` and the admin panel's
 * node usage screen.
 *
 * Two independent slices, both derived from data that already exists:
 *
 * - **static** — what the published versions that can still run contain: the active version of every flow, and any
 *   other version a session that has not ended is still on (a session keeps the version it started on, so a handler
 *   such a version needs is still needed). Unbounded in time: a node in such a version will run sooner or later.
 *   A template's published versions are left out: a template never runs on its own, its nodes are in the definition
 *   of every flow that uses it.
 * - **runtime** — what `flow_logs` recorded. Bounded by the retention window
 *   ({@see \App\Console\Commands\PruneFlowLogsCommand} drops partitions older than 30 days), but it is a complete
 *   record inside that window: `FlowEngine` writes a row for every node it executes, regardless of `logging_enabled`
 *   (that flag gates the audit rows of `flow_session_history` only).
 *
 * An action of the action registry (Core's or a Solution's) is used by a `call` node on the `handler` transport whose
 * target names it as written. `flow_logs` does not record the action, so its executions are the executions of those
 * nodes: the log rows of `call` nodes, through their session, matched by the session's version and the node id. A
 * target built from a template is known at run time only and counts for no action.
 *
 * The static slice is aggregated in PHP rather than pushed into SQL on purpose. Expanding the `nodes` jsonb in the
 * database needs `jsonb_array_elements` on PostgreSQL and `json_each` on SQLite, which would mean the test suite
 * exercises a different statement than production runs. The versions read are bounded — one active per flow (the
 * partial unique index `flow_definitions_active_unique`) plus the versions live sessions are on, never every version
 * ever published — so counting them in PHP costs little and keeps one code path on both drivers.
 *
 * Every read filters the tenant's id (the console has no implicit tenancy scope); `flow_logs` has no tenant column,
 * so its rows are the tenant's through their session. Holds no connection and no tenant state (the connection is
 * asked for at the moment it reads), so it stays correct across
 * {@see \App\Domains\Tenancy\Services\TenantSwitcher::runForTenant()} switches.
 */
final readonly class NodeUsageStatisticsService implements NodeUsageStatisticsInterface
{
    /**
     * The node type that sends to an action (through {@see HandlerTransport::ID}).
     */
    private const string CALL_NODE_TYPE = 'call';

    public function __construct(
        private NodeHandlerRegistryInterface $registry,
        private ActionHandlerRegistry $actions,
        private TenantContextInterface $tenants,
        private ConnectionResolverInterface $db,
    ) {
    }

    public function reportForCurrentTenant(CarbonImmutable $runtimeSince, ?EloquentBuilder $assistants = null): NodeUsageReport
    {
        $tenantId = $this->tenants->get()->getId();
        $driver   = $this->db->connection()->getDriverName();
        $bounds   = TimestampWindow::bounds($driver, $runtimeSince, CarbonImmutable::now());
        $scope    = null === $assistants ? null : (clone $assistants)->select('assistants.id');

        [$static, $actionStatic, $callNodes] = $this->collectStaticUsage($tenantId, $scope);
        $runtime                             = $this->collectRuntimeUsage($tenantId, $scope, $bounds);
        $actionRuntime                       = $this->collectActionRuntime($tenantId, $scope, $bounds, $callNodes);

        $usages = [];

        foreach ($this->mergedKeys($static, $runtime) as $nodeType => $versions) {
            foreach ($versions as $nodeVersion) {
                $staticEntry  = $static[$nodeType][$nodeVersion] ?? null;
                $runtimeEntry = $runtime[$nodeType][$nodeVersion] ?? null;

                $usages[] = new NodeTypeUsage(
                    nodeType: $nodeType,
                    nodeVersion: $nodeVersion,
                    activeFlows: count($staticEntry['flows'] ?? []),
                    activeNodes: $staticEntry['nodes'] ?? 0,
                    executions: $runtimeEntry['executions'] ?? 0,
                    failures: $runtimeEntry['failures'] ?? 0,
                    lastExecutedAt: $runtimeEntry['last_executed_at'] ?? null,
                    handlerRegistered: $this->registry->has($nodeType, $nodeVersion),
                    publishedVersions: count($staticEntry['versions'] ?? []),
                );
            }
        }

        return new NodeUsageReport(
            runtimeSince: $runtimeSince,
            usages: $usages,
            unusedHandlerTypes: $this->unusedHandlerTypes($static, $runtime),
            actions: $this->actionUsages($actionStatic, $actionRuntime),
        );
    }

    /**
     * Node occurrences in the versions that can still run, keyed by type and version; the same for the actions the
     * `call` nodes send to; and which node of which version sends to which action.
     *
     * `nodes` and `flows` count the active versions only, `versions` every version read.
     *
     * @param  EloquentBuilder<Assistant>|null  $scope  the ids of the assistants to count for
     *
     * @return array{
     *     0: array<string, array<int, array{nodes?: int, flows?: array<string, true>, versions: array<string, true>}>>,
     *     1: array<string, array{flows?: array<string, true>, versions: array<string, true>}>,
     *     2: array<string, array<string, string>>
     * }
     */
    private function collectStaticUsage(string $tenantId, ?EloquentBuilder $scope): array
    {
        $usage     = [];
        $actions   = [];
        $callNodes = [];

        $definitions = $this->db->connection()->table('flow_definitions')
            ->select(['id', 'flow_id', 'nodes', 'is_active'])
            ->where('tenant_id', $tenantId)
            // A template never runs on its own: its nodes are already in the definition of every flow that uses it.
            ->where('kind', FlowKind::Flow->value)
            ->where(static fn (Builder $runnable): Builder => $runnable
                ->where('is_active', true)
                ->orWhereIn('id', static fn (Builder $sessions): Builder => $sessions
                    ->select('flow_definition_id')
                    ->from('flow_sessions')
                    ->where('tenant_id', $tenantId)
                    ->whereNotIn('status', FlowSessionStatus::terminalValues())))
            ->when(null !== $scope, static fn (Builder $scoped): Builder => $scoped
                ->whereIn('flow_id', static fn (Builder $drafts): Builder => $drafts
                    ->select('flow_id')
                    ->from('flow_drafts')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('assistant_id', $scope)))
            ->lazyById(column: 'id');

        foreach ($definitions as $definition) {
            $definitionId = (string) $definition->id;
            $flowId       = (string) $definition->flow_id;
            $isActive     = (bool) $definition->is_active;

            foreach ($this->nodesOf($definition->nodes) as $node) {
                $nodeType = $node['type'] ?? null;

                if (! is_string($nodeType) || '' === $nodeType) {
                    continue;
                }

                $nodeVersion = $this->nodeVersion($node);

                $usage[$nodeType][$nodeVersion]['versions'][$definitionId] = true;

                if ($isActive) {
                    $usage[$nodeType][$nodeVersion]['nodes']          = ($usage[$nodeType][$nodeVersion]['nodes'] ?? 0) + 1;
                    $usage[$nodeType][$nodeVersion]['flows'][$flowId] = true;
                }

                $actionId = $this->actionOf($nodeType, $node);

                if (null === $actionId) {
                    continue;
                }

                $actions[$actionId]['versions'][$definitionId] = true;

                if ($isActive) {
                    $actions[$actionId]['flows'][$flowId] = true;
                }

                if (is_string($node['id'] ?? null)) {
                    $callNodes[$definitionId][$node['id']] = $actionId;
                }
            }
        }

        return [$usage, $actions, $callNodes];
    }

    /**
     * Executions logged inside the window, keyed by type and version.
     *
     * Plain aggregate SQL — no JSON functions and no `AT TIME ZONE` — so the statement is identical on PostgreSQL and
     * SQLite; the bounds carry their offset on PostgreSQL ({@see TimestampWindow}). Partitioning is transparent to
     * reads through the `flow_logs` root, and the window keeps the read on the partitions it needs.
     *
     * @param  EloquentBuilder<Assistant>|null                              $scope
     * @param  array{0: CarbonImmutable|string, 1: CarbonImmutable|string}  $bounds
     *
     * @return array<string, array<int, array{executions: int, failures: int, last_executed_at: ?CarbonImmutable}>>
     */
    private function collectRuntimeUsage(string $tenantId, ?EloquentBuilder $scope, array $bounds): array
    {
        $rows = $this->db->connection()->table('flow_logs')
            ->whereBetween('created_at', $bounds)
            ->whereIn('session_id', static fn (Builder $sessions): Builder => $sessions
                ->select('id')
                ->from('flow_sessions')
                ->where('tenant_id', $tenantId)
                ->when(null !== $scope, static fn (Builder $scoped): Builder => $scoped->whereIn('assistant_id', $scope)))
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
     * Executions of the `call` nodes that send to an action, inside the window, keyed by action id.
     *
     * The log rows of `call` nodes are grouped by their session's version and their node id, and each group is given to
     * the action that node of that version names. A version the static slice did not read — one replaced since, whose
     * sessions have ended — is read here for its nodes, so its runs in the window still count for their action.
     *
     * @param  EloquentBuilder<Assistant>|null                              $scope
     * @param  array{0: CarbonImmutable|string, 1: CarbonImmutable|string}  $bounds
     * @param  array<string, array<string, string>>                         $callNodes  action id by version id and node id
     *
     * @return array<string, array{executions: int, failures: int, last_executed_at: ?CarbonImmutable}>
     */
    private function collectActionRuntime(string $tenantId, ?EloquentBuilder $scope, array $bounds, array $callNodes): array
    {
        $rows = $this->db->connection()->table('flow_logs')
            ->join('flow_sessions', 'flow_sessions.id', '=', 'flow_logs.session_id')
            ->where('flow_sessions.tenant_id', $tenantId)
            ->when(null !== $scope, static fn (Builder $scoped): Builder => $scoped->whereIn('flow_sessions.assistant_id', $scope))
            ->where('flow_logs.node_type', self::CALL_NODE_TYPE)
            ->whereBetween('flow_logs.created_at', $bounds)
            ->groupBy('flow_sessions.flow_definition_id', 'flow_logs.node_id')
            ->selectRaw(
                'flow_sessions.flow_definition_id AS definition_id, flow_logs.node_id AS node_id, COUNT(*) AS executions, '
                . 'SUM(CASE WHEN flow_logs.status = ? THEN 1 ELSE 0 END) AS failures, '
                . 'MAX(flow_logs.created_at) AS last_executed_at',
                [FlowLogStatus::Failed->value],
            )
            ->get();

        $unread = [];

        foreach ($rows as $row) {
            if (! isset($callNodes[(string) $row->definition_id])) {
                $unread[(string) $row->definition_id] = true;
            }
        }

        $callNodes += $this->callNodesOf($tenantId, array_keys($unread));
        $usage = [];

        foreach ($rows as $row) {
            $actionId = $callNodes[(string) $row->definition_id][(string) $row->node_id] ?? null;

            if (null === $actionId) {
                continue;
            }

            $current  = $usage[$actionId] ?? ['executions' => 0, 'failures' => 0, 'last_executed_at' => null];
            $last     = $this->toCarbon($row->last_executed_at);
            $previous = $current['last_executed_at'];

            $usage[$actionId] = [
                'executions'       => $current['executions'] + (int) $row->executions,
                'failures'         => $current['failures'] + (int) $row->failures,
                'last_executed_at' => null === $last || (null !== $previous && $previous->greaterThan($last)) ? $previous : $last,
            ];
        }

        return $usage;
    }

    /**
     * Which node of each given version sends to which action.
     *
     * @param  list<string>  $definitionIds
     *
     * @return array<string, array<string, string>>
     */
    private function callNodesOf(string $tenantId, array $definitionIds): array
    {
        $callNodes = [];

        foreach (array_chunk($definitionIds, 500) as $chunk) {
            $definitions = $this->db->connection()->table('flow_definitions')
                ->select(['id', 'nodes'])
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $chunk)
                ->get();

            foreach ($definitions as $definition) {
                foreach ($this->nodesOf($definition->nodes) as $node) {
                    $nodeType = $node['type'] ?? null;
                    $actionId = is_string($nodeType) ? $this->actionOf($nodeType, $node) : null;

                    if (null !== $actionId && is_string($node['id'] ?? null)) {
                        $callNodes[(string) $definition->id][$node['id']] = $actionId;
                    }
                }
            }
        }

        return $callNodes;
    }

    /**
     * The nodes of a definition row, as the query builder returns its `nodes` column.
     *
     * @return list<array<string, mixed>>
     */
    private function nodesOf(mixed $nodes): array
    {
        $nodes = is_string($nodes) ? json_decode($nodes, true) : $nodes;

        return is_array($nodes) ? array_values(array_filter($nodes, is_array(...))) : [];
    }

    /**
     * @param  array<string, array{flows?: array<string, true>, versions: array<string, true>}>                        $static
     * @param  array<string, array{executions: int, failures: int, last_executed_at: ?CarbonImmutable}>                $runtime
     *
     * @return list<ActionUsage>
     */
    private function actionUsages(array $static, array $runtime): array
    {
        $actionIds = array_map(strval(...), array_keys($static + $runtime));
        sort($actionIds);

        $usages = [];

        foreach ($actionIds as $actionId) {
            $entry      = $static[$actionId] ?? ['versions' => []];
            $registered = $this->actions->has($actionId);

            $usages[] = new ActionUsage(
                actionId: $actionId,
                solutionId: $registered ? $this->actions->ownerOf($actionId) : null,
                registered: $registered,
                activeFlows: count($entry['flows'] ?? []),
                publishedVersions: count($entry['versions']),
                executions: $runtime[$actionId]['executions'] ?? 0,
                failures: $runtime[$actionId]['failures'] ?? 0,
                lastExecutedAt: $runtime[$actionId]['last_executed_at'] ?? null,
            );
        }

        return $usages;
    }

    /**
     * The action a node sends to: a `call` node on the `handler` transport with a target written as it is.
     *
     * @param  array<string, mixed>  $node
     */
    private function actionOf(string $nodeType, array $node): ?string
    {
        if (self::CALL_NODE_TYPE !== $nodeType) {
            return null;
        }

        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        if (HandlerTransport::ID !== ($config['transport'] ?? null) || ! is_string($config['target'] ?? null)) {
            return null;
        }

        $target = mb_trim($config['target']);

        return '' === $target || str_contains($target, '{{') ? null : $target;
    }

    /**
     * Registered handler types absent from both slices: nothing references them, so retiring the type as a whole
     * breaks no tenant data. A type that only a version a live session is on still contains is not one.
     *
     * The registry resolves by `type@version` but `all()` exposes only the latest version of each type, so this
     * answers at type granularity. A per-version answer is read off the report's own rows.
     *
     * @param  array<string, array<int, mixed>>  $static
     * @param  array<string, array<int, mixed>>  $runtime
     *
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
     *
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
     * It has to: the runtime slice is keyed by the version the engine actually logged, so a definition whose node
     * omits `version` must land on the same key (1) as its log rows, or the two slices would never line up.
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

        return CarbonImmutable::parse($value)->utc();
    }
}
