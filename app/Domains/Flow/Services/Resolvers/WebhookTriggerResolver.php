<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services\Resolvers;

use Fapost\Foundation\Flow\Contracts\TriggerTypeResolverInterface;
use Fapost\Foundation\Flow\DTO\ResolvedTrigger;
use Fapost\Foundation\Flow\DTO\TriggerContext;

/**
 * A webhook trigger is never resolved from an inbound message: its own URL starts it
 * ({@see \App\Domains\Flow\Webhook\FlowWebhookReceiver}).
 */
final class WebhookTriggerResolver implements TriggerTypeResolverInterface
{
    public function resolve(TriggerContext $context): ?ResolvedTrigger
    {
        return null;
    }
}
