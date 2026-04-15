<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Repositories;

use App\Domains\Assistant\Contracts\AssistantFlowConfigRepositoryInterface;
use App\Domains\Assistant\Models\Assistant;

final class AssistantFlowConfigRepository implements AssistantFlowConfigRepositoryInterface
{
    public function findDefaultFlowId(string $assistantId): ?string
    {
        /** @var string|null $defaultFlowId */
        $defaultFlowId = Assistant::query()
            ->whereKey($assistantId)
            ->value('default_flow_id');

        if (null === $defaultFlowId || '' === $defaultFlowId) {
            return null;
        }

        return $defaultFlowId;
    }
}
