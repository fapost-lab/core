<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Media;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Media\Extractors\FlowDefinitionMediaReferenceExtractor;
use Tests\TestCase;

final class FlowDefinitionMediaReferenceExtractorTest extends TestCase
{
    public function test_extracts_send_message_media_file_id(): void
    {
        $definition = $this->makeDefinition([
            [
                'id'     => 'node-1',
                'type'   => 'send_message',
                'label'  => 'Greeting',
                'config' => ['media_file_id' => 'media-1'],
            ],
            ['id' => 'node-2', 'type' => 'send_message', 'config' => ['media_file_id' => 'media-2']],
        ]);

        $rows = (new FlowDefinitionMediaReferenceExtractor())->extractFromFlowDefinition($definition);

        $this->assertCount(2, $rows);
        $this->assertSame('media-1', $rows[0]['media_file_id']);
        $this->assertSame('node-1', $rows[0]['snapshot']['node_id']);
        $this->assertSame('Greeting', $rows[0]['snapshot']['node_label']);
        $this->assertSame('media-2', $rows[1]['media_file_id']);
    }

    public function test_returns_empty_array_when_no_media_referenced(): void
    {
        $definition = $this->makeDefinition([
            ['id' => 'node-1', 'type' => 'send_message', 'config' => ['text' => 'hi']],
        ]);

        $rows = (new FlowDefinitionMediaReferenceExtractor())->extractFromFlowDefinition($definition);

        $this->assertSame([], $rows);
    }

    public function test_skips_invalid_payloads(): void
    {
        $definition = $this->makeDefinition([
            'not-a-node',
            ['id' => 'node-1', 'config' => ['media_file_id' => 12345]],
            ['id' => 'node-2', 'config' => ['media_file_id' => '']],
        ]);

        $rows = (new FlowDefinitionMediaReferenceExtractor())->extractFromFlowDefinition($definition);

        $this->assertSame([], $rows);
    }

    public function test_deduplicates_same_node_with_same_media(): void
    {
        $definition = $this->makeDefinition([
            ['id' => 'node-1', 'type' => 'send_message', 'config' => ['media_file_id' => 'media-1']],
            ['id' => 'node-1', 'type' => 'send_message', 'config' => ['media_file_id' => 'media-1']],
        ]);

        $rows = (new FlowDefinitionMediaReferenceExtractor())->extractFromFlowDefinition($definition);

        $this->assertCount(1, $rows);
    }

    /**
     * @param  array<int, mixed>  $nodes
     */
    private function makeDefinition(array $nodes): FlowDefinition
    {
        $definition          = new FlowDefinition();
        $definition->id      = 'def-1';
        $definition->flow_id = 'flow-1';
        $definition->name    = 'Test flow';
        $definition->nodes   = $nodes;

        return $definition;
    }
}
