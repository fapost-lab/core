<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services\Resolvers;

use App\Domains\Flow\Enums\FlowTriggerType;
use FAPost\Foundation\Flow\Contracts\TriggerResolverInterface;
use FAPost\Foundation\Flow\Contracts\TriggerTypeResolverInterface;
use FAPost\Foundation\Flow\DTO\ResolvedTrigger;
use FAPost\Foundation\Flow\DTO\TriggerContext;

final readonly class TriggerResolver implements TriggerResolverInterface
{
    /**
     * Pure lookup orchestrator for trigger resolvers.
     * Keep this layer side-effect free: no writes, no session creation, no engine calls.
     */
    public function __construct(
        private TriggerTypeResolverInterface $messageResolver,
        private TriggerTypeResolverInterface $scheduleResolver,
        private TriggerTypeResolverInterface $webhookResolver,
        private TriggerTypeResolverInterface $apiResolver,
    ) {
    }

    public function resolve(TriggerContext $context): ?ResolvedTrigger
    {
        return match ($context->type) {
            FlowTriggerType::Message->value  => $this->messageResolver->resolve($context),
            FlowTriggerType::Schedule->value => $this->scheduleResolver->resolve($context),
            FlowTriggerType::Webhook->value  => $this->webhookResolver->resolve($context),
            FlowTriggerType::Api->value      => $this->apiResolver->resolve($context),
            default                          => null,
        };
    }
}
