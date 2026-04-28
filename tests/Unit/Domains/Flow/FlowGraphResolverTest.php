<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow;

use App\Domains\Flow\Exceptions\InvalidFlowGraphException;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Services\FlowGraphResolver;
use Tests\TestCase;

final class FlowGraphResolverTest extends TestCase
{
    public function test_resolve_entry_node_returns_single_node_without_incoming_edges(): void
    {
        $definition = new FlowDefinition([
            'nodes' => [
                ['id' => 'n1', 'type' => 't', 'version' => 1, 'config' => []],
                ['id' => 'n2', 'type' => 't', 'version' => 1, 'config' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'n1', 'to' => 'n2', 'handle' => 'default'],
            ],
        ]);

        $resolver = new FlowGraphResolver();

        $this->assertSame('n1', $resolver->resolveEntryNode($definition));
    }

    public function test_resolve_next_node_returns_target_for_transition(): void
    {
        $definition = new FlowDefinition([
            'nodes' => [
                ['id' => 'n1', 'type' => 't', 'version' => 1, 'config' => []],
                ['id' => 'n2', 'type' => 't', 'version' => 1, 'config' => []],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'n1', 'to' => 'n2', 'handle' => 'default'],
            ],
        ]);

        $resolver = new FlowGraphResolver();

        $this->assertSame('n2', $resolver->resolveNextNode($definition, 'n1', 'default'));
        $this->assertNull($resolver->resolveNextNode($definition, 'n1', 'missing'));
    }

    public function test_find_node_throws_when_missing(): void
    {
        $definition = new FlowDefinition([
            'nodes' => [
                ['id' => 'n1', 'type' => 't', 'version' => 1, 'config' => []],
            ],
            'edges' => [],
        ]);

        $resolver = new FlowGraphResolver();

        $this->expectException(InvalidFlowGraphException::class);

        $resolver->findNode($definition, 'ghost');
    }
}
