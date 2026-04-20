<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Models\FlowTrigger;

interface FlowTriggerRepositoryInterface
{
    /**
     * @return iterable<FlowTrigger>
     */
    public function getActiveByType(string $tenantId, ?string $assistantId, string $type): iterable;
}
