<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Models\FlowDraft;

final class FlowDraftRepository implements FlowDraftRepositoryInterface
{
    public function findByFlowId(string $flowId): FlowDraft
    {
        return FlowDraft::query()
            ->where('flow_id', $flowId)
            ->firstOrFail();
    }

    public function isSwitchedOff(string $flowId): bool
    {
        return FlowDraft::query()
            ->where('flow_id', $flowId)
            ->where('is_active', false)
            ->exists();
    }
}
