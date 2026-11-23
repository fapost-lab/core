<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Models\FlowDraft;

interface FlowDraftRepositoryInterface
{
    public function findByFlowId(string $flowId): FlowDraft;

    /**
     * Whether the flow is switched off in the flows list (`flow_drafts.is_active = false`): its automatic triggers then
     * start no runs. A flow without a draft is not switched off; each start path treats a missing draft on its own.
     */
    public function isSwitchedOff(string $flowId): bool;
}
