<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\DTOs\BuilderFlowDto;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;

final class LoadBuilderFlowService
{
    public function execute(string $flowId): BuilderFlowDto
    {
        $draft = FlowDraft::query()->where('flow_id', $flowId)->firstOrFail();

        $published = FlowDefinition::query()
            ->where('flow_id', $flowId)
            ->where('is_active', true)
            ->first();

        return BuilderFlowDto::fromDraftAndDefinition($draft, $published);
    }
}
