<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Models\FlowDraft;

interface FlowDraftRepositoryInterface
{
    public function findByFlowId(string $flowId): FlowDraft;
}
