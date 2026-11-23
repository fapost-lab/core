<?php

declare(strict_types=1);

namespace App\Domains\Flow\Trace;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Enums\EndStatus;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Handlers\LoopNodeHandler;
use App\Domains\Flow\Handlers\SubflowNodeHandler;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowSessionHistoryEntry;
use App\Domains\Flow\Nodes\AnnotationNodeTypes;
use App\Domains\Flow\Subflow\CallGraphValidator;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\DTO\NodeExecutionStatus;
use Fapost\Foundation\Flow\History\HistoryEventType;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * Reads a session's node trace ({@see NodeTraceRecorder}) as the run graph of the console: the definition the session
 * executed, what each node did, the passes of each loop, and the subflow children drawn inside it.
 *
 * Statuses only. Every trace field is read by name from a whitelist ({@see self::FIELDS}) — the node's input, output and
 * error message never leave the database here, so a screen gated by `ViewFlowSessions` alone may show the result. Full
 * node data waits for `ViewRunPayloads` (spec flow-automations). The definition's node config is not passed either: it
 * can hold what the trace masks (a token in a `call` URL).
 *
 * Every query is bounded by the current tenant; a session is found by the caller.
 *
 * The shapes below are mirrored by `resources/js/console/run-graph/runGraph.ts`.
 *
 * @phpstan-type NodeRun array{status: string, visits: int, handles: list<string>, durationMs: float|null, errorClass: string|null}
 * @phpstan-type GraphNode array{id: string, type: string, version: int, label: string|null, typeLabel: string|null, endStatus: string|null, run: NodeRun|null}
 * @phpstan-type GraphEdge array{id: string, from: string, to: string, handle: string}
 * @phpstan-type PassNode array{id: string, status: string, handle: string|null}
 * @phpstan-type GraphLoop array{nodeId: string, passes: list<array{pass: int, nodes: list<PassNode>}>}
 * @phpstan-type GraphChild array{nodeId: string, count: int, flow: array<string, mixed>}
 * @phpstan-type GraphFlow array{sessionId: string, status: string, flowName: string|null, nodes: list<GraphNode>, edges: list<GraphEdge>, loops: list<GraphLoop>, children: list<GraphChild>}
 *
 * A child's `flow` is a `GraphFlow` one level deeper; a type alias cannot name itself, so it stays `array<string, mixed>`.
 */
final readonly class RunGraphReader
{
    /** The newest trace rows read for one session; a longer trace is marked truncated. */
    public const int TRACE_ROW_LIMIT = 20_000;

    /** The newest visits of one node the node panel shows. */
    public const int VISIT_LIMIT = 100;

    /** The run's own status of a node, from its last trace row. */
    public const string DONE = 'done';

    public const string WAITING = 'waiting';

    public const string FAILED = 'failed';

    public const string RUNNING = 'running';

    /**
     * The trace envelope fields a status-only reader may select, as `alias => JSON path`.
     *
     * @var array<string, string>
     */
    private const array FIELDS = [
        'status'      => 'metadata->status',
        'handle'      => 'metadata->handle',
        'next'        => 'metadata->next',
        'duration_ms' => 'metadata->duration_ms',
        'iterations'  => 'metadata->iterations',
        'error_class' => 'metadata->error->class',
    ];

    public function __construct(
        private TenantContextInterface $tenants,
        private NodeHandlerRegistryInterface $registry,
    ) {
    }

    /**
     * @return array{flow: GraphFlow, truncated: bool, limit: int}
     */
    public function graph(FlowSession $session): array
    {
        $truncated = false;
        $flow      = $this->flow($session, 0, $truncated);

        return ['flow' => $flow, 'truncated' => $truncated, 'limit' => self::TRACE_ROW_LIMIT];
    }

    /**
     * The newest {@see self::VISIT_LIMIT} trace rows of one node, statuses only.
     *
     * @return list<array{event: string, at: string|null, status: string, handle: string|null, next: string|null, durationMs: float|null, iterations: array<string, int>, errorClass: string|null}>
     */
    public function visits(FlowSession $session, string $nodeId): array
    {
        $rows = $this->traceQuery($session)
            ->where('node_id', $nodeId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::VISIT_LIMIT)
            ->get();

        $visits = [];

        foreach ($rows as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }

            $visits[] = [
                'event'      => (string) $row->event_type,
                'at'         => null === $row->created_at ? null : (string) $row->created_at,
                'status'     => self::statusOf($row),
                'handle'     => self::text($row->handle),
                'next'       => self::text($row->next),
                'durationMs' => is_numeric($row->duration_ms) ? (float) $row->duration_ms : null,
                'iterations' => self::iterations($row->iterations),
                'errorClass' => self::text($row->error_class),
            ];
        }

        return $visits;
    }

    /**
     * Whether a session belongs to the run drawn from `$root`: the root itself or a subflow child down to the call
     * graph's depth. The node panel of a frame asks for the child's session.
     */
    public function belongsTo(FlowSession $root, FlowSession $candidate): bool
    {
        $current = $candidate;

        for ($depth = 0; $depth <= CallGraphValidator::MAX_DEPTH; ++$depth) {
            if ((string) $current->getKey() === (string) $root->getKey()) {
                return true;
            }

            $parentId = $current->getAttribute('parent_session_id');

            if (! is_string($parentId) || '' === $parentId) {
                return false;
            }

            $parent = FlowSession::query()
                ->where('tenant_id', $this->tenantId())
                ->whereKey($parentId)
                ->first();

            if (null === $parent) {
                return false;
            }

            $current = $parent;
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $edges
     *
     * @return list<GraphEdge>
     */
    private static function edges(array $edges): array
    {
        $out = [];

        foreach ($edges as $index => $edge) {
            if (! is_array($edge) || ! is_string($edge['from'] ?? null) || ! is_string($edge['to'] ?? null)) {
                continue;
            }

            $handle = is_string($edge['handle'] ?? null) && '' !== $edge['handle'] ? $edge['handle'] : 'default';

            $out[] = [
                'id'     => is_string($edge['id'] ?? null) ? $edge['id'] : "edge-{$index}",
                'from'   => $edge['from'],
                'to'     => $edge['to'],
                'handle' => $handle,
            ];
        }

        return $out;
    }

    private static function statusOf(stdClass $row): string
    {
        return match ($row->event_type) {
            HistoryEventType::NodeEntered->value => self::RUNNING,
            HistoryEventType::NodeFailed->value  => self::FAILED,
            default                              => match ($row->status) {
                NodeExecutionStatus::Failed->value                                       => self::FAILED,
                NodeExecutionStatus::Waiting->value, NodeExecutionStatus::Delayed->value => self::WAITING,
                default                                                                  => self::DONE,
            },
        };
    }

    private static function endStatus(mixed $config): string
    {
        $status = is_array($config) && is_string($config['status'] ?? null) ? $config['status'] : 'success';

        return in_array($status, ['success', 'cancelled', 'failed'], true) ? $status : 'success';
    }

    /**
     * @return array<string, int>
     */
    private static function iterations(mixed $value): array
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $loopId => $pass) {
            if (is_numeric($pass)) {
                $out[(string) $loopId] = (int) $pass;
            }
        }

        return $out;
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $text = (string) $value;

        return '' === $text ? null : $text;
    }

    /**
     * A subflow node's trace ends at `waiting`: the engine resumes the parent when the child ends without writing a row
     * for the subflow node. Once its child has finished, the node is done and took the exit the child's end maps to
     * (as `DefaultSubflowResumer` routes it).
     *
     * When the child ends in the same run, that `waiting` row is written after the rows of the nodes the parent went on
     * to, and its duration covers them ({@see NodeTraceRecorder}). The fold is per node, so the other nodes' statuses do
     * not depend on it; only this node's own duration reads longer than the subflow call itself.
     *
     * @param  NodeRun|null  $run
     *
     * @return NodeRun|null
     */
    private static function withSubflowOutcome(?array $run, FlowSession $child): ?array
    {
        if (null === $run || self::WAITING !== $run['status'] || in_array($child->status, FlowSessionStatus::inFlight(), true)) {
            return $run;
        }

        $endStatus = $child->getAttribute('end_status');
        $handle    = match (true) {
            is_string($endStatus) && '' !== $endStatus                                                              => EndStatus::tryFrom($endStatus),
            FlowSessionStatus::Failed === $child->status, FlowSessionStatus::Expired === $child->status             => EndStatus::Failed,
            FlowSessionStatus::Cancelled === $child->status, FlowSessionStatus::TerminatedByUser === $child->status => EndStatus::Cancelled,
            default                                                                                                 => EndStatus::Success,
        };
        $handle = match ($handle) {
            EndStatus::Cancelled => SubflowNodeHandler::HANDLE_CANCELLED,
            EndStatus::Failed    => SubflowNodeHandler::HANDLE_FAILED,
            default              => SubflowNodeHandler::HANDLE_SUCCESS,
        };

        $run['status'] = self::DONE;

        if (! in_array($handle, $run['handles'], true)) {
            $run['handles'][] = $handle;
        }

        return $run;
    }

    /**
     * The body of each loop node: what its `loop` handle leads to, up to the `loop_end` that sends the run back (it has
     * no outgoing edge). A trace row belongs to a loop's pass only when its node is in that body: the iterator a row
     * carries is read from the state, so the loop node itself and a later loop sharing the iterator name carry it too.
     *
     * @param  array<array-key, mixed>  $nodes
     * @param  array<array-key, mixed>  $edges
     *
     * @return array<string, array<string, true>>
     */
    private static function loopBodies(array $nodes, array $edges): array
    {
        $next = [];

        foreach (self::edges($edges) as $edge) {
            $next[$edge['from']][] = $edge;
        }

        $bodies = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || LoopNodeHandler::TYPE !== ($node['type'] ?? null) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            $loopId = $node['id'];
            $body   = [];
            $queue  = array_map(
                static fn (array $edge): string => $edge['to'],
                array_filter($next[$loopId] ?? [], static fn (array $edge): bool => LoopNodeHandler::LOOP_HANDLE === $edge['handle']),
            );

            while ([] !== $queue) {
                $id = array_shift($queue);

                if ($id === $loopId || isset($body[$id])) {
                    continue;
                }

                $body[$id] = true;

                foreach ($next[$id] ?? [] as $edge) {
                    $queue[] = $edge['to'];
                }
            }

            $bodies[$loopId] = $body;
        }

        return $bodies;
    }

    /**
     * @return GraphFlow
     */
    private function flow(FlowSession $session, int $depth, bool &$truncated): array
    {
        $definition = FlowDefinition::query()
            ->where('tenant_id', $this->tenantId())
            ->whereKey((string) $session->flow_definition_id)
            ->first();

        $nodes = is_array($definition?->nodes) ? $definition->nodes : [];
        $edges = is_array($definition?->edges) ? $definition->edges : [];

        [$runs, $loops] = $this->aggregate($session, self::loopBodies($nodes, $edges), $truncated);
        $children       = $depth < CallGraphValidator::MAX_DEPTH ? $this->children($session) : [];
        $drawn          = [];

        foreach ($children as $nodeId => ['latest' => $child, 'count' => $count]) {
            $runs[$nodeId] = self::withSubflowOutcome($runs[$nodeId] ?? null, $child);
            $drawn[]       = ['nodeId' => $nodeId, 'count' => $count, 'flow' => $this->flow($child, $depth + 1, $truncated)];
        }

        return [
            'sessionId' => (string) $session->getKey(),
            'status'    => $session->status->value,
            'flowName'  => $definition?->name,
            'nodes'     => $this->nodes($nodes, array_filter($runs)),
            'edges'     => self::edges($edges),
            'loops'     => $loops,
            'children'  => $drawn,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     * @param  array<string, NodeRun>  $runs
     *
     * @return list<GraphNode>
     */
    private function nodes(array $nodes, array $runs): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null) || ! is_string($node['type'] ?? null)) {
                continue;
            }

            $type    = $node['type'];
            $version = is_int($node['version'] ?? null) ? $node['version'] : 1;

            $out[] = [
                'id'        => $node['id'],
                'type'      => $type,
                'version'   => $version,
                'label'     => is_string($node['label'] ?? null) && '' !== $node['label'] ? $node['label'] : null,
                'typeLabel' => $this->typeLabel($type, $version),
                'endStatus' => 'end' === $type ? self::endStatus($node['config'] ?? null) : null,
                'run'       => $runs[$node['id']] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Folds the trace into what each node did (its last status, visits, handles taken, total duration, last error class)
     * and the passes of each loop (`{loop node id → pass → node id → status}`).
     *
     * @return array{0: array<string, NodeRun>, 1: list<GraphLoop>}
     *
     * @param  array<string, array<string, true>>  $bodies  {@see self::loopBodies()}
     */
    private function aggregate(FlowSession $session, array $bodies, bool &$truncated): array
    {
        $rows = $this->traceQuery($session)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::TRACE_ROW_LIMIT + 1)
            ->get()
            ->all();

        if (count($rows) > self::TRACE_ROW_LIMIT) {
            $truncated = true;
            array_pop($rows);
        }

        $runs    = [];
        $passes  = [];
        $resumes = [];

        foreach (array_reverse($rows) as $row) {
            if (! $row instanceof stdClass) {
                continue;
            }

            $nodeId = (string) $row->node_id;
            $status = self::statusOf($row);
            $handle = self::text($row->handle);
            $run    = $runs[$nodeId] ?? ['status' => $status, 'visits' => 0, 'handles' => [], 'durationMs' => null, 'errorClass' => null];

            $run['status'] = $status;

            // A node that waited (a question, a delay) is entered again when it resumes: that is the same visit.
            if (HistoryEventType::NodeEntered->value === $row->event_type && ! ($resumes[$nodeId] ?? false)) {
                ++$run['visits'];
            }

            $resumes[$nodeId] = HistoryEventType::NodeCompleted->value === $row->event_type && self::WAITING === $status;

            if (null !== $handle && ! in_array($handle, $run['handles'], true)) {
                $run['handles'][] = $handle;
            }

            if (is_numeric($row->duration_ms)) {
                $run['durationMs'] = round((float) ($run['durationMs'] ?? 0.0) + (float) $row->duration_ms, 3);
            }

            if (HistoryEventType::NodeFailed->value === $row->event_type) {
                $run['errorClass'] = self::text($row->error_class);
            }

            $runs[$nodeId] = $run;

            foreach (self::iterations($row->iterations) as $loopId => $pass) {
                if (! isset($bodies[$loopId][$nodeId])) {
                    continue;
                }

                $passes[$loopId][$pass][$nodeId] = ['id' => $nodeId, 'status' => $status, 'handle' => $handle ?? ($passes[$loopId][$pass][$nodeId]['handle'] ?? null)];
            }
        }

        $loops = [];

        foreach ($passes as $loopId => $byPass) {
            ksort($byPass);
            $list = [];

            foreach ($byPass as $pass => $nodes) {
                $list[] = ['pass' => (int) $pass, 'nodes' => array_values($nodes)];
            }

            $loops[] = ['nodeId' => (string) $loopId, 'passes' => $list];
        }

        return [$runs, $loops];
    }

    /**
     * The latest subflow child each subflow node of the session started, with how many it started.
     *
     * @return array<string, array{latest: FlowSession, count: int}>
     */
    private function children(FlowSession $session): array
    {
        $children = FlowSession::query()
            ->where('tenant_id', $this->tenantId())
            ->where('parent_session_id', (string) $session->getKey())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $out = [];

        foreach ($children as $child) {
            $nodeId = $child->getAttribute('parent_resume_node_id');

            if (! is_string($nodeId) || '' === $nodeId) {
                continue;
            }

            $out[$nodeId] = ['latest' => $child, 'count' => ($out[$nodeId]['count'] ?? 0) + 1];
        }

        return $out;
    }

    /**
     * The session's trace rows, whitelisted fields only.
     */
    private function traceQuery(FlowSession $session): Builder
    {
        $columns = ['id', 'node_id', 'event_type', 'created_at'];

        foreach (self::FIELDS as $alias => $path) {
            $columns[] = "{$path} as {$alias}";
        }

        return FlowSessionHistoryEntry::query()
            ->toBase()
            ->select($columns)
            ->where('tenant_id', $this->tenantId())
            ->where('session_id', (string) $session->getKey())
            ->whereIn('event_type', NodeTraceRecorder::EVENT_TYPES);
    }

    private function typeLabel(string $type, int $version): ?string
    {
        if (AnnotationNodeTypes::COMMENT === $type) {
            return 'Comment';
        }

        return $this->registry->has($type, $version) ? $this->registry->resolve($type, $version)->label() : null;
    }

    private function tenantId(): string
    {
        return $this->tenants->get()->getId();
    }
}
