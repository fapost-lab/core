<?php

declare(strict_types=1);

namespace App\Domains\Media\Extractors;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Media\Contracts\MediaReferenceExtractorInterface;

/**
 * Walks a FlowDefinition's nodes JSON and collects every media_file_id referenced.
 *
 * Currently knows about the `send_message` node (`config.media_file_id`) and the `input`
 * node (`config.media_file_id` for default attachments). Extending the engine with new
 * media-bearing nodes is a single-key addition here — kept centralized to avoid scattered
 * snake-paths across the codebase.
 */
final class FlowDefinitionMediaReferenceExtractor implements MediaReferenceExtractorInterface
{
    /**
     * Dot-paths under each node where a media_file_id may appear.
     *
     * @var list<string>
     */
    private const array MEDIA_FILE_ID_PATHS = [
        'config.media_file_id',
    ];

    public function extractFromFlowDefinition(FlowDefinition $definition): array
    {
        $nodes = $definition->nodes ?? [];

        if (! is_array($nodes)) {
            return [];
        }

        $found = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            foreach (self::MEDIA_FILE_ID_PATHS as $path) {
                $value = data_get($node, $path);

                if (! is_string($value) || '' === $value) {
                    continue;
                }

                $key = $value . '|' . (string)($node['id'] ?? '');

                if (isset($found[$key])) {
                    continue;
                }

                $found[$key] = [
                    'media_file_id' => $value,
                    'snapshot'      => [
                        'flow_id'    => $definition->flow_id ?? null,
                        'flow_name'  => $definition->name ?? null,
                        'node_id'    => $node['id'] ?? null,
                        'node_label' => $node['label'] ?? null,
                        'node_type'  => $node['type'] ?? null,
                    ],
                ];
            }
        }

        return array_values($found);
    }
}
