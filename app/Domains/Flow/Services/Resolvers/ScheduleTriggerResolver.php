<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services\Resolvers;

use Fapost\Foundation\Flow\Contracts\TriggerTypeResolverInterface;
use Fapost\Foundation\Flow\DTO\ResolvedTrigger;
use Fapost\Foundation\Flow\DTO\TriggerContext;

/**
 * A schedule never starts from an inbound message: its runs are started by the minute's pass of
 * `flow:fire-scheduled-triggers` ({@see \App\Domains\Flow\Schedule\ScheduleTriggerFirer}), so this resolver matches
 * nothing.
 */
final class ScheduleTriggerResolver implements TriggerTypeResolverInterface
{
    public function resolve(TriggerContext $context): ?ResolvedTrigger
    {
        return null;
    }
}
