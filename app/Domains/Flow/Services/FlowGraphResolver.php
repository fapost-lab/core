<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Exceptions\InvalidFlowGraphException;
use App\Domains\Flow\Models\FlowDefinition;

final class FlowGraphResolver
{
    public function resolveEntryNode(FlowDefinition $definition): string
    {
        $nodes = $definition->nodes;

        if ([] === $nodes) {
            throw InvalidFlowGraphException::missingEntryNode();
        }

        $incoming = [];

        foreach ($definition->edges as $edge) {
            if ( ! is_array($edge)) {
                continue;
            }

            $target = $edge['target_node_id'] ?? null;

            if (is_string($target) && '' !== $target) {
                $incoming[$target] = true;
            }
        }

        $candidates = [];

        foreach ($nodes as $node) {
            if ( ! is_array($node)) {
                continue;
            }

            $id = $node['id'] ?? null;

            if ( ! is_string($id) || '' === $id) {
                continue;
            }

            if ( ! isset($incoming[$id])) {
                $candidates[] = $id;
            }
        }

        if (1 !== count($candidates)) {
            throw InvalidFlowGraphException::missingEntryNode();
        }

        return $candidates[0];
    }

    /**
     * @return array<string, mixed>
     */
    public function findNode(FlowDefinition $definition, string $nodeId): array
    {
        foreach ($definition->nodes as $node) {
            if ( ! is_array($node)) {
                continue;
            }

            if (($node['id'] ?? null) === $nodeId) {
                return $node;
            }
        }

        throw InvalidFlowGraphException::missingNode($nodeId);
    }

    public function resolveNextNode(
        FlowDefinition $definition,
        string $nodeId,
        string $sourceHandle,
    ): ?string {
        foreach ($definition->edges as $edge) {
            if ( ! is_array($edge)) {
                continue;
            }

            $source     = $edge['source_node_id'] ?? null;
            $target     = $edge['target_node_id'] ?? null;
            $transition = $edge['transition'] ?? null;

            if ($source === $nodeId
                && is_string($transition)
                && $transition === $sourceHandle
                && is_string($target)
                && '' !== $target) {
                return $target;
            }
        }

        return null;
    }
}
