<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Models\FlowDefinition;

interface FlowDefinitionRepositoryInterface
{
    public function findLatestActiveByFlowId(string $flowId): ?FlowDefinition;

    public function findById(string $id): FlowDefinition;
}
