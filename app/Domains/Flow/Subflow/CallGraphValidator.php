<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

/**
 * Pure-function call-graph validator. Given the prospective edge set originating
 * from `caller_flow_id` (i.e. the {@code subflow.flow_id} references inside a
 * draft definition), it consults the existing {@see CallGraphRepository} to
 * check global invariants:
 *
 *  - Direct recursion: `caller_flow_id ∈ outgoing` is rejected (A → A).
 *  - Indirect recursion: forward BFS from outgoing must not reach `caller_flow_id`.
 *  - Depth: longest chain starting at `caller_flow_id` must be ≤ MAX_DEPTH.
 *
 * Cross-assistant rejection is owned by the publisher (it has cheap access to
 * `flow_definitions.assistant_id`); this validator is pure-graph.
 */
final readonly class CallGraphValidator
{
    public const int MAX_DEPTH = 3;

    public function __construct(
        private CallGraphRepository $edges,
    ) {
    }

    /**
     * @param  list<string>  $proposedCallees  Direct callees of {@code $callerFlowId} from the draft.
     * @return list<CallGraphViolation>
     */
    public function validate(string $callerFlowId, array $proposedCallees): array
    {
        $violations = [];
        $callees    = array_values(array_unique(array_filter($proposedCallees, static fn (string $v) => '' !== $v)));

        // Direct recursion.
        if (in_array($callerFlowId, $callees, true)) {
            $violations[] = new CallGraphViolation(
                code: 'subflow_direct_recursion',
                message: 'A flow may not invoke itself as a subflow.',
                offendingFlowId: $callerFlowId,
            );
        }

        // Indirect recursion + depth from each callee.
        foreach ($callees as $callee) {
            if ($callee === $callerFlowId) {
                continue; // already reported as direct recursion
            }

            $cycle = $this->detectsCycleBackTo($callee, $callerFlowId);
            if (null !== $cycle) {
                $violations[] = new CallGraphViolation(
                    code: 'subflow_indirect_recursion',
                    message: 'Subflow chain ' . implode(' → ', $cycle)
                        . " forms a cycle back to '{$callerFlowId}'.",
                    offendingFlowId: $callee,
                );
            }
        }

        // Depth enforcement: longest forward chain from caller (counted in nodes including caller).
        $maxDepth = $this->longestChain($callerFlowId, $callees);
        if ($maxDepth > self::MAX_DEPTH) {
            $violations[] = new CallGraphViolation(
                code: 'subflow_depth_exceeded',
                message: "Subflow chain depth {$maxDepth} exceeds maximum " . self::MAX_DEPTH . '.',
                offendingFlowId: $callerFlowId,
            );
        }

        return $violations;
    }

    /**
     * BFS from $start; if $target is reached, return the chain
     * [$start, …, $target]. Otherwise null.
     *
     * @return list<string>|null
     */
    private function detectsCycleBackTo(string $start, string $target): ?array
    {
        $queue   = [[$start, [$start]]];
        $visited = [$start => true];

        while ([] !== $queue) {
            [$current, $path] = array_shift($queue);

            foreach ($this->edges->calleesOf($current) as $next) {
                if ($next === $target) {
                    return [...$path, $next];
                }

                if (isset($visited[$next])) {
                    continue;
                }

                $visited[$next] = true;
                $queue[]        = [$next, [...$path, $next]];
            }
        }

        return null;
    }

    /**
     * Longest forward chain length (in nodes) starting at $callerFlowId,
     * using $proposedCallees as the first-step neighbours.
     *
     * @param  list<string>  $proposedCallees
     */
    private function longestChain(string $callerFlowId, array $proposedCallees): int
    {
        $best = 1; // caller alone

        foreach ($proposedCallees as $first) {
            $depth = 1 + $this->forwardDepth($first, [$callerFlowId => true]);
            if ($depth > $best) {
                $best = $depth;
            }
        }

        return $best;
    }

    /**
     * Depth (in nodes) of the longest forward chain from $node, avoiding
     * already-visited flow_ids. Caps recursion at MAX_DEPTH+2 to bound work
     * on very large graphs — depth violations are still detected because we
     * only need to know "exceeds MAX_DEPTH or not".
     *
     * @param  array<string, true>  $visited
     */
    private function forwardDepth(string $node, array $visited): int
    {
        if (isset($visited[$node])) {
            return 0;
        }

        $visited[$node] = true;
        $best           = 1;

        foreach ($this->edges->calleesOf($node) as $next) {
            $depth = 1 + $this->forwardDepth($next, $visited);
            if ($depth > $best) {
                $best = $depth;
            }
            if ($best > self::MAX_DEPTH + 2) {
                break;
            }
        }

        return $best;
    }
}
