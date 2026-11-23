<?php

declare(strict_types=1);

namespace App\Domains\Flow\Trace;

use App\Domains\Flow\Handlers\LoopNodeHandler;

/**
 * Which loops a node runs inside, read from the session state on entry to the node.
 *
 * A `loop` node keeps its pass in `flow.<iterator_name>` while its body runs and clears it on exit, so a loop of the
 * definition whose iterator is set is active: `{loop node id: pass}`, several keys for loops with different iterators,
 * empty outside loops. An iterator name several loops share (sequential loops left at the default `iterator`) is set by
 * one of them only, so its pass goes to one loop: the loop the engine's step budget follows when it is one of them,
 * else the first of them in the definition — the loop a segment that starts in the body takes as well. A flow that left
 * a loop body without going through its `loop` node leaves the iterator set, and the map then names a loop that no
 * longer runs (design R-6).
 */
final class LoopIterations
{
    /**
     * @param  array<array-key, mixed>  $nodes  the definition's nodes
     * @param  array<array-key, mixed>  $state  the session state on entry to the node
     * @param  string|null  $activeLoopNodeId  the loop whose body the engine counts the node in
     *                                          ({@see \App\Domains\Flow\Services\StepBudget::activeLoopNodeId()}),
     *                                          null when it counts none
     *
     * @return array<string, int>
     */
    public static function of(array $nodes, array $state, ?string $activeLoopNodeId = null): array
    {
        /** @var array<string, string> $loopByIterator per iterator name, the loop its pass is attributed to */
        $loopByIterator = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || LoopNodeHandler::TYPE !== ($node['type'] ?? null) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            $name = LoopNodeHandler::iteratorNameOf(is_array($node['config'] ?? null) ? $node['config'] : []);

            if (! isset($loopByIterator[$name]) || $node['id'] === $activeLoopNodeId) {
                $loopByIterator[$name] = $node['id'];
            }
        }

        $iterations = [];

        foreach ($loopByIterator as $name => $loopNodeId) {
            $pass = data_get($state, 'flow.' . $name);

            if (is_int($pass) || (is_string($pass) && ctype_digit($pass)) || (is_float($pass) && floor($pass) === $pass)) {
                $iterations[$loopNodeId] = (int) $pass;
            }
        }

        return $iterations;
    }
}
