<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowSessionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Events\EventTriggerAssistantFilter;
use Fapost\Foundation\Flow\DTO\ResolvedTrigger;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;

final readonly class ResolveEventTriggersService
{
    public function __construct(
        private FlowTriggerRepositoryInterface $triggers,
        private FlowSessionRepositoryInterface $sessions,
        private LoggerInterface $logger,
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

    /**
     * The triggers a flow's own event (`emit_event`) starts: those of {@see execute()} whose `assistant_filter` lets
     * the event's source through ({@see EventTriggerAssistantFilter}).
     *
     * The source assistant is the emitting session's (`flow_sessions.assistant_id`; a subflow child carries its
     * parent's), read once and only when a trigger has a filter. A session that is gone, or has no assistant, passes
     * only triggers without a filter.
     *
     * @return list<ResolvedTrigger>
     */
    public function forFlowEvent(string $tenantId, string $eventName, ?string $sourceSessionId): array
    {
        $resolved = $this->execute($tenantId, $eventName);

        $sourceAssistantId = null;
        $sourceRead        = false;
        $started           = [];

        foreach ($resolved as $trigger) {
            $filter = EventTriggerAssistantFilter::fromConfig($trigger->config);

            if ($filter->isEmpty()) {
                $started[] = $trigger;

                continue;
            }

            if (! $sourceRead) {
                $sourceAssistantId = $this->sourceAssistantId($sourceSessionId);
                $sourceRead        = true;
            }

            if ($filter->allows($sourceAssistantId)) {
                $started[] = $trigger;

                continue;
            }

            $this->logger->debug('flow.event.trigger_filtered', [
                'tenant_id'           => $tenantId,
                'event_name'          => $eventName,
                'trigger_id'          => $trigger->triggerId,
                'source_assistant_id' => $sourceAssistantId,
            ]);
        }

        return $started;
    }

    private function sourceAssistantId(?string $sessionId): ?string
    {
        if (null === $sessionId || ! Str::isUuid($sessionId)) {
            return null;
        }

        $assistantId = $this->sessions->findById($sessionId)?->assistant_id;

        return is_string($assistantId) && '' !== $assistantId ? $assistantId : null;
    }
}
