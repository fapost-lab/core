<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Flow\Enums\FlowActivityPeriod;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Support\TimestampWindow;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Carbon\CarbonImmutable;
use Fapost\Foundation\Analytics\Enums\AnalyticsEventType;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use RuntimeException;

/**
 * Flow runs per flow over a period, for the admin dashboard: how many runs started, completed and failed, read from
 * `analytics_events` (the `flow_started`, `flow_completed` and `flow_failed` events the engine writes after commit).
 *
 * An event names only its session (`payload.session_id`), so its flow is the one of that session's published version
 * (`flow_sessions.flow_definition_id → flow_definitions.flow_id`); every version of a flow counts as that flow. A
 * session can be gone by the time the panel reads its events — finished automation runs are pruned
 * ({@see \App\Domains\Flow\Retention\AutomationRunPruner}), a contact's deletion takes its sessions — so those
 * events are counted apart as `unattributed`, never dropped. They belong to no assistant any more, so a reader
 * narrowed to some assistants does not see them at all.
 *
 * The read is a range scan of `analytics_events_tenant_type_occurred_idx` (`tenant_id, event_type, occurred_at`),
 * bounded by a {@see FlowActivityPeriod} — 30 days at most — joined to each event's session by its key; no rollup
 * table (measured on PostgreSQL: 13k events of 30 days against 500k sessions, about 0.1 s). Comparing the ids as text
 * instead would lose the key and cost ten times that.
 * The bounds carry their offset on PostgreSQL ({@see TimestampWindow}).
 */
final readonly class FlowRunAnalytics
{
    /**
     * How many flows the panel lists, the busiest first.
     */
    public const int LIMIT = 10;

    /**
     * A session id as PostgreSQL may cast it to `uuid`.
     */
    private const string UUID_PATTERN = '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$';

    /**
     * The events read, by the figure each one counts in.
     *
     * @var array<string, string>
     */
    private const array FIGURES = [
        AnalyticsEventType::FlowStarted->value   => 'started',
        AnalyticsEventType::FlowCompleted->value => 'completed',
        AnalyticsEventType::FlowFailed->value    => 'failed',
    ];

    /**
     * @param  ConnectionResolverInterface  $db  asked for its connection at the moment it reads, never kept: a tenant
     *                                         switch moves the default connection
     */
    public function __construct(
        private TenantContextInterface $tenants,
        private ConnectionResolverInterface $db,
    ) {
    }

    /**
     * The busiest {@see self::LIMIT} flows of the period by runs started (then by name), how many flows had any run, and
     * the runs whose session is gone (`null` for a reader narrowed to some assistants).
     *
     * @param  EloquentBuilder<Assistant>|null  $assistants  the assistants to count for; `null` is the whole tenant
     *
     * @return array{
     *     flows: list<array{flow_id: string, name: string, started: int, completed: int, failed: int}>,
     *     total: int,
     *     unattributed: array{started: int, completed: int, failed: int}|null
     * }
     */
    public function perFlow(FlowActivityPeriod $period, CarbonImmutable $now, ?EloquentBuilder $assistants = null): array
    {
        $now      = $now->utc();
        $start    = $period->since($now);
        $tenantId = $this->tenantId();

        $sessionKey = match ($driver = $this->db->connection()->getDriverName()) {
            // Cast to `uuid` so the session is found by its key, and only a well-formed id is cast: another writer of the
            // Foundation contract may name a session in its own way, and one bad cast would fail the whole read.
            'pgsql' => new Expression(
                "CASE WHEN analytics_events.payload->>'session_id' ~* '" . self::UUID_PATTERN . "' "
                . "THEN CAST(analytics_events.payload->>'session_id' AS uuid) END"
            ),
            'sqlite' => new Expression("json_extract(analytics_events.payload, '$.session_id')"),
            default  => throw new RuntimeException("FlowRunAnalytics: unsupported database driver [{$driver}]."),
        };

        $bounds = TimestampWindow::bounds($driver, $start, $now);

        $rows = $this->db->connection()->table('analytics_events')
            ->select(['flow_definitions.flow_id', 'analytics_events.event_type'])
            ->selectRaw('COUNT(*) AS total')
            ->leftJoin('flow_sessions', static fn (JoinClause $join): JoinClause => $join
                ->on('flow_sessions.id', '=', $sessionKey)
                ->where('flow_sessions.tenant_id', '=', $tenantId))
            ->leftJoin('flow_definitions', static fn (JoinClause $join): JoinClause => $join
                ->on('flow_definitions.id', '=', 'flow_sessions.flow_definition_id')
                ->where('flow_definitions.tenant_id', '=', $tenantId))
            ->where('analytics_events.tenant_id', $tenantId)
            ->whereIn('analytics_events.event_type', array_keys(self::FIGURES))
            ->whereBetween('analytics_events.occurred_at', $bounds)
            ->when(null !== $assistants, fn (Builder $scoped): Builder => $scoped
                ->whereIn('flow_sessions.assistant_id', (clone $assistants)->select('assistants.id')))
            ->groupBy(['flow_definitions.flow_id', 'analytics_events.event_type'])
            ->get();

        /** @var array<string, array{started: int, completed: int, failed: int}> $flows */
        $flows        = [];
        $unattributed = ['started' => 0, 'completed' => 0, 'failed' => 0];

        foreach ($rows as $row) {
            $figure = self::FIGURES[(string) $row->event_type];

            if (null === $row->flow_id) {
                $unattributed[$figure] += (int) $row->total;

                continue;
            }

            $flows[(string) $row->flow_id] ??= ['started' => 0, 'completed' => 0, 'failed' => 0];
            $flows[(string) $row->flow_id][$figure] += (int) $row->total;
        }

        $names = $this->names(array_keys($flows));
        $list  = [];

        foreach ($flows as $flowId => $figures) {
            $list[] = ['flow_id' => (string) $flowId, 'name' => $names[$flowId] ?? '', ...$figures];
        }

        usort($list, static fn (array $a, array $b): int => [$b['started'], $a['name'], $a['flow_id']] <=> [$a['started'], $b['name'], $b['flow_id']]);

        return [
            'flows'        => array_slice($list, 0, self::LIMIT),
            'total'        => count($list),
            'unattributed' => null === $assistants ? $unattributed : null,
        ];
    }

    /**
     * Each flow's name as its draft shows it now, else as its latest published version had it (the draft of a deleted
     * flow is gone, its versions stay while sessions point at them).
     *
     * @param  list<string>  $flowIds
     *
     * @return array<string, string>
     */
    private function names(array $flowIds): array
    {
        if ([] === $flowIds) {
            return [];
        }

        $tenantId = $this->tenantId();
        $names    = [];

        $versions = $this->db->connection()->table('flow_definitions')
            ->select(['flow_id', 'name'])
            ->where('tenant_id', $tenantId)
            ->whereIn('flow_id', $flowIds)
            ->orderBy('version')
            ->get();

        foreach ($versions as $version) {
            $names[(string) $version->flow_id] = (string) $version->name;
        }

        $drafts = $this->db->connection()->table('flow_drafts')
            ->select(['flow_id', 'name'])
            ->where('tenant_id', $tenantId)
            ->where('kind', FlowKind::Flow->value)
            ->whereIn('flow_id', $flowIds)
            ->get();

        foreach ($drafts as $draft) {
            $names[(string) $draft->flow_id] = (string) $draft->name;
        }

        return $names;
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
