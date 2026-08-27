<?php

declare(strict_types=1);

namespace App\Domains\Media\Services;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Media\Contracts\MediaReferenceExtractorInterface;
use App\Domains\Media\Contracts\MediaReferenceTrackerInterface;
use App\Domains\Media\Models\MediaFileReference;
use Illuminate\Support\Facades\DB;

/**
 * Persists media_file_references for a FlowDefinition snapshot.
 *
 * Replaces all existing rows for the (reference_type, reference_id) pair in a single
 * transaction so the table is always in sync with the saved nodes JSON. Run from a
 * model-event listener — never from request controllers directly.
 */
final readonly class MediaReferenceTracker implements MediaReferenceTrackerInterface
{
    public function __construct(
        private MediaReferenceExtractorInterface $extractor,
    ) {
    }

    public function trackFlowDefinition(FlowDefinition $definition): void
    {
        $references = $this->extractor->extractFromFlowDefinition($definition);

        DB::transaction(function () use ($definition, $references): void {
            MediaFileReference::query()
                ->where('reference_type', MediaFileReference::TYPE_FLOW_DEFINITION)
                ->where('reference_id', $definition->id)
                ->delete();

            foreach ($references as $row) {
                MediaFileReference::query()->create([
                    'media_file_id'  => $row['media_file_id'],
                    'reference_type' => MediaFileReference::TYPE_FLOW_DEFINITION,
                    'reference_id'   => $definition->id,
                    'snapshot'       => $row['snapshot'],
                ]);
            }
        });
    }
}
