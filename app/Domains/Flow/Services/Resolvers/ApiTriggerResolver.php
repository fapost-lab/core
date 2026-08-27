<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services\Resolvers;

use Fapost\Foundation\Flow\Contracts\TriggerTypeResolverInterface;
use Fapost\Foundation\Flow\DTO\ResolvedTrigger;
use Fapost\Foundation\Flow\DTO\TriggerContext;

final class ApiTriggerResolver implements TriggerTypeResolverInterface
{
    public function resolve(TriggerContext $context): ?ResolvedTrigger
    {
        return null;
    }
}
