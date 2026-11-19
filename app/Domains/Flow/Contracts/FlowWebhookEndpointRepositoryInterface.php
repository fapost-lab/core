<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Models\FlowWebhookEndpoint;

interface FlowWebhookEndpointRepositoryInterface
{
    public function findByToken(string $tenantId, string $token): ?FlowWebhookEndpoint;

    public function findByTriggerId(string $triggerId): ?FlowWebhookEndpoint;

    /**
     * The endpoints made for the flow's triggers, keyed by lower-cased trigger id.
     *
     * @return array<string, FlowWebhookEndpoint>
     */
    public function forFlow(string $flowId): array;

    public function save(FlowWebhookEndpoint $endpoint): void;

    /**
     * Deletes the endpoints of the given triggers, with their secrets.
     *
     * @param  list<string>  $triggerIds
     */
    public function deleteForTriggers(array $triggerIds): void;

    /**
     * Deletes every endpoint of the flow, with their secrets.
     */
    public function deleteForFlow(string $flowId): void;
}
