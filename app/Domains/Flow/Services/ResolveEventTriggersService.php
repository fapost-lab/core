<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use Fapost\Foundation\Flow\DTO\ResolvedTrigger;

final readonly class ResolveEventTriggersService
{
    public function __construct(
        private FlowTriggerRepositoryInterface $triggers,
    ) {
    }

    /**
     * @return list<ResolvedTrigger>
     */
    public function execute(string $tenantId, string $eventName): array
    {
        if ('' === mb_trim($eventName)) {
            return [];
        }

        $resolved = [];

        foreach ($this->triggers->getActiveEventTriggers($tenantId, $eventName) as $trigger) {
            $resolved[] = new ResolvedTrigger(
                triggerId: (string)$trigger->getKey(),
                flowId: $trigger->flow_id,
                type: $trigger->type->value,
                config: $trigger->config,
                metadata: [
                    'tenant_id'    => $tenantId,
                    'assistant_id' => $trigger->assistant_id,
                    'event_name'   => $eventName,
                ],
            );
        }

        return $resolved;
    }
}
