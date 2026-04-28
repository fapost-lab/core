<?php

declare(strict_types=1);

namespace App\Domains\Media\Listeners;

use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Media\Contracts\MediaReferenceTrackerInterface;

/**
 * Subscribed to FlowDefinition::saved by {@see \App\Domains\Media\Providers\MediaServiceProvider}.
 *
 * Runs synchronously inside the saving transaction so a failed reference write rolls
 * back the FlowDefinition save itself.
 */
final readonly class TrackFlowDefinitionMediaReferences
{
    public function __construct(
        private MediaReferenceTrackerInterface $tracker,
    ) {
    }

    public function handle(FlowDefinition $definition): void
    {
        $this->tracker->trackFlowDefinition($definition);
    }
}
