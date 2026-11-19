<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Flow\Contracts\FlowWebhookEndpointRepositoryInterface;
use App\Domains\Flow\Models\FlowWebhookEndpoint;

final class FlowWebhookEndpointRepository implements FlowWebhookEndpointRepositoryInterface
{
    public function findByToken(string $tenantId, string $token): ?FlowWebhookEndpoint
    {
        return FlowWebhookEndpoint::query()
            ->where('tenant_id', $tenantId)
            ->where('token', $token)
            ->first();
    }

    public function findByTriggerId(string $triggerId): ?FlowWebhookEndpoint
    {
        return FlowWebhookEndpoint::query()
            ->where('trigger_id', mb_strtolower($triggerId))
            ->first();
    }

    public function forFlow(string $flowId): array
    {
        $endpoints = [];

        foreach (FlowWebhookEndpoint::query()->where('flow_id', $flowId)->get() as $endpoint) {
            $endpoints[mb_strtolower($endpoint->trigger_id)] = $endpoint;
        }

        return $endpoints;
    }

    public function save(FlowWebhookEndpoint $endpoint): void
    {
        $endpoint->save();
    }

    public function deleteForTriggers(array $triggerIds): void
    {
        if ([] === $triggerIds) {
            return;
        }

        FlowWebhookEndpoint::query()
            ->whereIn('trigger_id', array_map(mb_strtolower(...), $triggerIds))
            ->delete();
    }

    public function deleteForFlow(string $flowId): void
    {
        FlowWebhookEndpoint::query()->where('flow_id', $flowId)->delete();
    }
}
