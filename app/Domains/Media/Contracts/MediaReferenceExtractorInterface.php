<?php

declare(strict_types=1);

namespace App\Domains\Media\Contracts;

use App\Domains\Flow\Models\FlowDefinition;

/**
 * Walks an entity's serialized payload and extracts the media_file_ids it points at.
 *
 * Output is keyed by reference snapshot so the tracker can persist context (flow name,
 * node id, node label) without re-querying the entity.
 */
interface MediaReferenceExtractorInterface
{
    /**
     * @return array<int, array{media_file_id: string, snapshot: array<string, mixed>}>
     */
    public function extractFromFlowDefinition(FlowDefinition $definition): array;
}
