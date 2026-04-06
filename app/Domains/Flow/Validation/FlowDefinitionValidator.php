<?php

declare(strict_types=1);

namespace App\Domains\Flow\Validation;

use App\Domains\Flow\Contracts\NodeHandlerRegistryInterface;
use App\Domains\Flow\Exceptions\FlowValidationException;
use LogicException;

final readonly class FlowDefinitionValidator
{
    public function __construct(
        private NodeHandlerRegistryInterface $registry,
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $nodes
     * @param  array<int, array<string, mixed>>  $edges
     * @return array{entry_node_id: string, adjacency: array<string, array<string, string>>, reverse_adjacency: array<string, list<string>>}
     */
    public function validate(array $nodes, array $edges): array
    {
        if ([] === $nodes) {
            throw new FlowValidationException('Flow definition must contain at least one node.');
        }

        $nodeMap = [];

        foreach ($nodes as $node) {
            $nodeId  = $this->stringField($node, 'id', 'Node must contain id.');
            $type    = $this->stringField($node, 'type', "Node {$nodeId} must contain type.");
            $version = $this->intField($node, 'version', "Node {$nodeId} must contain integer version.");

            try {
                $this->registry->resolve($type, $version);
            } catch (LogicException $exception) {
                throw new FlowValidationException(
                    "Node {$nodeId} references unknown handler version: {$type}@{$version}",
                    previous: $exception,
                );
            }

            if (isset($nodeMap[$nodeId])) {
                throw new FlowValidationException("Duplicate node id: {$nodeId}");
            }

            $nodeMap[$nodeId] = $node;
        }

        $adjacency        = [];
        $reverseAdjacency = [];

        foreach ($edges as $edge) {
            $sourceNodeId = $this->stringField($edge, 'source_node_id', 'Edge must contain source_node_id.');
            $targetNodeId = $this->stringField($edge, 'target_node_id', 'Edge must contain target_node_id.');
            $transition   = $this->stringField($edge, 'transition', 'Edge must contain transition.');

            if ( ! isset($nodeMap[$sourceNodeId])) {
                throw new FlowValidationException("Edge references unknown source node: {$sourceNodeId}");
            }

            if ( ! isset($nodeMap[$targetNodeId])) {
                throw new FlowValidationException("Edge references unknown target node: {$targetNodeId}");
            }

            if (isset($adjacency[$sourceNodeId][$transition])) {
                throw new FlowValidationException(
                    "Duplicate edge transition mapping for source {$sourceNodeId} and transition {$transition}"
                );
            }

            $adjacency[$sourceNodeId][$transition] = $targetNodeId;
            $reverseAdjacency[$targetNodeId] ??= [];
            $reverseAdjacency[$targetNodeId][] = $sourceNodeId;
        }

        $entryCandidates = [];

        foreach (array_keys($nodeMap) as $nodeId) {
            if ( ! isset($reverseAdjacency[$nodeId])) {
                $entryCandidates[] = $nodeId;
            }
        }

        if (1 !== count($entryCandidates)) {
            throw new FlowValidationException(
                'Flow definition must contain exactly one entry point node (node without incoming edges).'
            );
        }

        $entryNodeId = $entryCandidates[0];
        $visited     = [];
        $queue       = [$entryNodeId];

        while ([] !== $queue) {
            $current = array_shift($queue);

            if (null === $current || isset($visited[$current])) {
                continue;
            }

            $visited[$current] = true;

            foreach ($adjacency[$current] ?? [] as $targetNodeId) {
                $queue[] = $targetNodeId;
            }
        }

        if (count($visited) !== count($nodeMap)) {
            $orphanNodes = array_diff(array_keys($nodeMap), array_keys($visited));
            throw new FlowValidationException(
                'Flow definition contains orphan nodes: ' . implode(', ', $orphanNodes)
            );
        }

        foreach ($nodeMap as $nodeId => $node) {
            if ( ! isset($node['required_transitions']) || ! is_array($node['required_transitions'])) {
                continue;
            }

            foreach ($node['required_transitions'] as $transition) {
                if ( ! is_string($transition) || '' === $transition) {
                    throw new FlowValidationException("Node {$nodeId} has invalid required transition value.");
                }

                if ( ! isset($adjacency[$nodeId][$transition])) {
                    throw new FlowValidationException(
                        "Node {$nodeId} requires transition {$transition}, but edge is missing."
                    );
                }
            }
        }

        return [
            'entry_node_id'     => $entryNodeId,
            'adjacency'         => $adjacency,
            'reverse_adjacency' => $reverseAdjacency,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $field, string $message): string
    {
        $value = $payload[$field] ?? null;

        if ( ! is_string($value) || '' === $value) {
            throw new FlowValidationException($message);
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function intField(array $payload, string $field, string $message): int
    {
        $value = $payload[$field] ?? null;

        if ( ! is_int($value)) {
            throw new FlowValidationException($message);
        }

        return $value;
    }
}
