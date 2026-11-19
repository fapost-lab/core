<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;

interface FlowTriggerRepositoryInterface
{
    /**
     * @return iterable<FlowTrigger>
     */
    public function getActiveByType(string $tenantId, ?string $assistantId, string $type): iterable;

    /**
     * The flow's live triggers, active or not, in routing order (priority, then id).
     *
     * @return list<FlowTrigger>
     */
    public function getByFlowId(string $flowId): array;

    /**
     * Makes the flow's live triggers the given list, matched by id: a new id is inserted with that id, a gone one
     * deleted, a kept one updated. A kept trigger whose type and config did not change keeps its run state
     * (`last_run_at`, `next_run_at`) and is written only when its flag or priority changed; one whose type or config
     * changed loses `next_run_at`.
     *
     * @param  list<array{id: string, type: string, is_active: bool, priority: int, config: array<string, mixed>}>  $triggers
     */
    public function syncForFlow(FlowDraft $draft, array $triggers): void;

    /**
     * @return iterable<FlowTrigger>
     */
    public function getActiveEventTriggers(string $tenantId, string $eventName): iterable;
}
