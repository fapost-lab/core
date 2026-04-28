<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\Exceptions\InvalidTriggerPayloadException;
use App\Domains\Flow\Models\FlowDraft;

final readonly class SyncFlowTriggerService
{
    public function __construct(
        private FlowTriggerRepositoryInterface $triggers,
        private TenantEventRepositoryInterface $tenantEvents,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $triggerPayload
     */
    public function execute(FlowDraft $draft, ?array $triggerPayload): void
    {
        if (null === $triggerPayload) {
            return;
        }

        if (true === ($triggerPayload['_delete'] ?? false)) {
            $this->triggers->deleteByFlowId($draft->flow_id);

            return;
        }

        $type = $triggerPayload['type'] ?? null;
        if ( ! is_string($type) || '' === $type) {
            throw new InvalidTriggerPayloadException('Trigger type is required.');
        }

        $config = $triggerPayload['config'] ?? null;
        if ( ! is_array($config)) {
            throw new InvalidTriggerPayloadException('Trigger config must be an object.');
        }

        if ('event' === $type) {
            $eventName = $config['event_name'] ?? null;

            if ( ! is_string($eventName) || '' === mb_trim($eventName)) {
                throw new InvalidTriggerPayloadException('Event trigger requires an existing event selection.');
            }

            $availableEvents = $this->tenantEvents->getEventNamesByTenant($draft->tenant_id);

            if ( ! in_array($eventName, $availableEvents, true)) {
                throw new InvalidTriggerPayloadException('Selected event does not exist in the tenant event registry.');
            }
        }

        $this->triggers->upsertForFlow($draft->flow_id, [
            'tenant_id'    => $draft->tenant_id,
            'assistant_id' => $draft->assistant_id,
            'flow_id'      => $draft->flow_id,
            'type'         => $type,
            'is_active'    => (bool)($triggerPayload['is_active'] ?? true),
            'priority'     => (int)($triggerPayload['priority'] ?? 100),
            'config'       => $config,
        ]);
    }
}
