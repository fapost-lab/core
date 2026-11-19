<?php

declare(strict_types=1);

namespace App\Domains\Flow\Trace;

use App\Domains\Flow\Handlers\LoopNodeHandler;

/**
 * Which loops a node runs inside, read from the session state on entry to the node.
 *
 * A `loop` node keeps its pass in `flow.<iterator_name>` while its body runs and clears it on exit, so every loop of
 * the definition whose iterator is set is active: `{loop node id: pass}`, several keys for nested loops, empty outside
 * loops. A flow that left a loop body without going through its `loop` node leaves the iterator set, and the map then
 * names a loop that no longer runs (design R-6).
 */
final class LoopIterations
{
    /**
     * @param  array<array-key, mixed>  $nodes  the definition's nodes
     * @param  array<array-key, mixed>  $state  the session state on entry to the node
     *
     * @return array<string, int>
     */
    public static function of(array $nodes, array $state): array
    {
        $iterations = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || LoopNodeHandler::TYPE !== ($node['type'] ?? null) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            $config = is_array($node['config'] ?? null) ? $node['config'] : [];
            $name   = is_string($config['iterator_name'] ?? null) && '' !== $config['iterator_name']
                ? $config['iterator_name']
                : LoopNodeHandler::DEFAULT_ITERATOR_NAME;

            $pass = data_get($state, 'flow.' . $name);

            if (is_int($pass) || (is_string($pass) && ctype_digit($pass)) || (is_float($pass) && floor($pass) === $pass)) {
                $iterations[$node['id']] = (int) $pass;
            }
        }

        return $iterations;
    }
}
