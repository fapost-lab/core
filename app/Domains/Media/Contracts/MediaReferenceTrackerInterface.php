<?php

declare(strict_types=1);

namespace App\Domains\Media\Contracts;

use App\Domains\Flow\Models\FlowDefinition;

/**
 * Synchronizes media_file_references with the current state of an entity that may
 * embed media_file_id pointers (currently only FlowDefinition).
 *
 * Replaces all previous references for the entity in a single transaction so the
 * table always reflects the saved snapshot.
 */
interface MediaReferenceTrackerInterface
{
    public function trackFlowDefinition(FlowDefinition $definition): void;
}
