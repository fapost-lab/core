<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Models\FlowTrigger;

interface FlowTriggerRepositoryInterface
{
    /**
     * @return iterable<FlowTrigger>
     */
    public function getActiveByType(string $tenantId, ?string $assistantId, string $type): iterable;

    public function findByFlowId(string $flowId): ?FlowTrigger;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function upsertForFlow(string $flowId, array $attributes): FlowTrigger;

    public function deleteByFlowId(string $flowId): void;

    /**
     * @return iterable<FlowTrigger>
     */
    public function getActiveEventTriggers(string $tenantId, string $eventName): iterable;
}
