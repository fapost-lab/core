<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Contracts;

interface AssistantFlowConfigRepositoryInterface
{
    public function findDefaultFlowId(string $assistantId): ?string;
}
