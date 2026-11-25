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
 * one of them only, and the state does not say which: its pass goes to the loop whose `loop` node the engine saw pass in
 * this call when that is one of them, and to no loop otherwise — a conversation resumed inside a body, or an automation
 * segment that starts in one, has not passed a `loop` node yet, and naming the first of the loops could name one that is
 * not running. A flow that left a loop body without going through its `loop` node leaves the iterator set, and the map
 * then names a loop that no longer runs (design R-6).
 */
final class LoopIterations
{
    /**
     * @param  array<array-key, mixed>  $nodes  the definition's nodes
     * @param  array<array-key, mixed>  $state  the session state on entry to the node
     * @param  string|null  $enteredLoopNodeId  the loop whose `loop` node last passed in this engine call
     *                                           ({@see \App\Domains\Flow\Services\StepBudget::enteredLoopNodeId()}),
     *                                           null when none did
     *
     * @return array<string, int>
     */
    public static function of(array $nodes, array $state, ?string $enteredLoopNodeId): array
    {
        /** @var array<string, list<string>> $loopsByIterator per iterator name, the loops that keep their pass under it */
        $loopsByIterator = [];

        foreach ($nodes as $node) {
            if (! is_array($node) || LoopNodeHandler::TYPE !== ($node['type'] ?? null) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            $loopsByIterator[LoopNodeHandler::iteratorNameOfNode($node)][] = $node['id'];
        }

        $iterations = [];

        foreach ($loopsByIterator as $name => $loopNodeIds) {
            $loopNodeId = match (true) {
                1 === count($loopNodeIds)                        => $loopNodeIds[0],
                in_array($enteredLoopNodeId, $loopNodeIds, true) => $enteredLoopNodeId,
                default                                          => null,
            };

            if (null === $loopNodeId) {
                continue;
            }

            $pass = data_get($state, 'flow.' . $name);

            if (is_int($pass) || (is_string($pass) && ctype_digit($pass)) || (is_float($pass) && floor($pass) === $pass)) {
                $iterations[$loopNodeId] = (int) $pass;
            }
        }

        return $iterations;
    }
}
