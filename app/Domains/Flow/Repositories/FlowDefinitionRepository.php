<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Models\FlowDefinition;

final class FlowDefinitionRepository implements FlowDefinitionRepositoryInterface
{
    public function findLatestActiveByFlowId(string $flowId): ?FlowDefinition
    {
        return FlowDefinition::query()
            ->where('flow_id', $flowId)
            ->active()
            ->orderByDesc('version')
            ->first();
    }
}
