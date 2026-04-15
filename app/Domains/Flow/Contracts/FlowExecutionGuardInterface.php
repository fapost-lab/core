<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use Closure;

interface FlowExecutionGuardInterface
{
    public function run(
        string $tenantId,
        string $contactId,
        string $assistantId,
        Closure $callback,
    ): mixed;
}
