<?php

declare(strict_types=1);

namespace App\Domains\Flow\Support;

use App\Domains\Flow\Handlers\LoopEndNodeHandler;
use App\Domains\Flow\Handlers\SendMessageNodeHandler;

/**
 * What leads to a node in a published graph shape — a message to tags or a segment (a broadcast), or a node that starts
 * a flow holding one — reduced to a string that two versions of the flow can be compared by.
 *
 * A node's cone is the node and every node it can be reached from: by an edge `from → to`, and by the implicit jump a
 * `loop_end` makes back to its `loop` (`config.loop_node_id`), so a node in a loop body after the broadcast still leads
 * to its next pass. Its fingerprint is each cone node's `type`, `version`, `config` and `parameters` by id, and each
 * edge into a cone node as `(from, handle, to)`. The canvas position, the label, an edge's id and the key order of a
 * config are not part of it; nor is a `loop_end`'s `iterator_name`, which publishing writes from its `loop`'s config,
 * itself in the cone. Every node that can write a `{{flow.*}}` the node reads before it runs is in its cone, so a cone
 * that compares equal means this version reaches and fills the node as the published one did; what a `subflow` callee
 * returns and data from outside (a trigger's payload, an API's answer) are not part of a version.
 */
final class AudienceMessageCone
{
    /**
     * The cone fingerprint of each of the given nodes, by node id. An id the graph does not hold gets a fingerprint of
     * its own (a missing node), so it never equals the cone of a node that exists.
     *
     * @param  array<array-key, mixed>  $nodes
     * @param  array<array-key, mixed>  $edges
     * @param  list<string>             $ids
     *
     * @return array<string, string>
     */
    public static function of(array $nodes, array $edges, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $byId        = self::nodesById($nodes);
        $edgeList    = self::edgeList($edges);
        $predecessor = self::predecessors($byId, $edgeList);
        $cones       = [];

        foreach ($ids as $id) {
            $cones[$id] = self::fingerprint(self::reaching($id, $predecessor), $byId, $edgeList);
        }

        return $cones;
    }

    /**
     * The ids of the `send_message` nodes to tags or a segment.
     *
     * @param  array<array-key, mixed>  $nodes
     *
     * @return list<string>
     */
    public static function audienceMessageIds(array $nodes): array
    {
        $ids = [];

        foreach (self::nodesById($nodes) as $id => $node) {
            if (self::isAudienceMessage($node)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * The fingerprint of the whole graph, in the cone's terms: every node and every edge.
     *
     * @param  array<array-key, mixed>  $nodes
     * @param  array<array-key, mixed>  $edges
     */
    public static function ofGraph(array $nodes, array $edges): string
    {
        $byId = self::nodesById($nodes);

        return self::fingerprint(array_fill_keys(array_keys($byId), true), $byId, self::edgeList($edges));
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function isAudienceMessage(array $node): bool
    {
        if (SendMessageNodeHandler::TYPE !== ($node['type'] ?? null) || ! is_array($node['config'] ?? null)) {
            return false;
        }

        return AddresseeResolver::isAudience(AddresseeResolver::parse($node['config'])['kind']);
    }

    /**
     * @param  array<array-key, mixed>  $nodes
     *
     * @return array<string, array<array-key, mixed>>
     */
    private static function nodesById(array $nodes): array
    {
        $byId = [];

        foreach ($nodes as $node) {
            if (is_array($node) && is_scalar($node['id'] ?? null)) {
                $byId[(string) $node['id']] = $node;
            }
        }

        return $byId;
    }

    /**
     * @param  array<array-key, mixed>  $edges
     *
     * @return list<array{from: string, handle: string, to: string}>
     */
    private static function edgeList(array $edges): array
    {
        $list = [];

        foreach ($edges as $edge) {
            if (! is_array($edge) || ! is_scalar($edge['from'] ?? null) || ! is_scalar($edge['to'] ?? null)) {
                continue;
            }

            $handle = $edge['handle'] ?? null;

            $list[] = [
                'from'   => (string) $edge['from'],
                'handle' => is_scalar($handle) && '' !== (string) $handle ? (string) $handle : 'default',
                'to'     => (string) $edge['to'],
            ];
        }

        return $list;
    }

    /**
     * The ids each node can be entered from: an edge's source, and every `loop_end` that jumps back to a `loop`.
     *
     * @param  array<string, array<array-key, mixed>>                    $byId
     * @param  list<array{from: string, handle: string, to: string}>     $edges
     *
     * @return array<string, list<string>>
     */
    private static function predecessors(array $byId, array $edges): array
    {
        $predecessors = [];

        foreach ($edges as $edge) {
            $predecessors[$edge['to']][] = $edge['from'];
        }

        foreach ($byId as $id => $node) {
            $loopNodeId = is_array($node['config'] ?? null) ? ($node['config']['loop_node_id'] ?? null) : null;

            if (LoopEndNodeHandler::TYPE === ($node['type'] ?? null) && is_scalar($loopNodeId)) {
                $predecessors[(string) $loopNodeId][] = $id;
            }
        }

        return $predecessors;
    }

    /**
     * The node and every node it can be reached from, breadth first; a cycle ends where a node was already seen.
     *
     * @param  array<string, list<string>>  $predecessors
     *
     * @return array<string, true>
     */
    private static function reaching(string $id, array $predecessors): array
    {
        $seen  = [$id => true];
        $queue = [$id];

        while ([] !== $queue) {
            $current = array_shift($queue);

            foreach ($predecessors[$current] ?? [] as $from) {
                if (! isset($seen[$from])) {
                    $seen[$from] = true;
                    $queue[]     = $from;
                }
            }
        }

        return $seen;
    }

    /**
     * @param  array<string, true>                                     $ids
     * @param  array<string, array<array-key, mixed>>                  $byId
     * @param  list<array{from: string, handle: string, to: string}>   $edges
     */
    private static function fingerprint(array $ids, array $byId, array $edges): string
    {
        $nodes = [];

        foreach (array_keys($ids) as $id) {
            $nodes[$id] = isset($byId[$id]) ? self::nodeFingerprint($byId[$id]) : null;
        }

        ksort($nodes, SORT_STRING);

        $into = [];

        foreach ($edges as $edge) {
            if (isset($ids[$edge['to']])) {
                $into[] = $edge['from'] . "\0" . $edge['handle'] . "\0" . $edge['to'];
            }
        }

        sort($into, SORT_STRING);

        return json_encode(['nodes' => $nodes, 'edges' => $into], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $node
     */
    private static function nodeFingerprint(array $node): mixed
    {
        $config = $node['config'] ?? null;

        if (LoopEndNodeHandler::TYPE === ($node['type'] ?? null) && is_array($config)) {
            unset($config['iterator_name']);
        }

        return self::canonical([
            'type'       => $node['type'] ?? null,
            'version'    => $node['version'] ?? null,
            'config'     => $config,
            'parameters' => $node['parameters'] ?? null,
        ]);
    }

    /**
     * The value with the keys of every map sorted, at every depth (jsonb does not keep their order); lists keep theirs.
     */
    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
