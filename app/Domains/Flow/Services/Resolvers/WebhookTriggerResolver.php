<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services\Resolvers;

use FAPost\Foundation\Flow\Contracts\TriggerTypeResolverInterface;
use FAPost\Foundation\Flow\DTO\ResolvedTrigger;
use FAPost\Foundation\Flow\DTO\TriggerContext;

final class WebhookTriggerResolver implements TriggerTypeResolverInterface
{
    public function resolve(TriggerContext $context): ?ResolvedTrigger
    {
        return null;
    }
}
