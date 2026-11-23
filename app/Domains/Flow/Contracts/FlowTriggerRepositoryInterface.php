<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Support\FlowTriggerComparison;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * @phpstan-import-type Trigger from FlowTriggerComparison
 */
interface FlowTriggerRepositoryInterface
{
    /**
     * @return iterable<FlowTrigger>
     */
    public function getActiveByType(string $tenantId, ?string $assistantId, string $type): iterable;

    /**
     * The live trigger with this id, or null when no published flow has it.
     */
    public function find(string $triggerId): ?FlowTrigger;

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
     * @param  list<Trigger>  $triggers
     */
    public function syncForFlow(FlowDraft $draft, array $triggers): void;

    /**
     * The tenant's active triggers of one type, whatever their assistant, in routing order (priority, then id): what
     * a fire of a registered trigger type is matched against.
     *
     * @return iterable<FlowTrigger>
     */
    public function getActiveOfType(string $tenantId, string $type): iterable;

    /**
     * @return iterable<FlowTrigger>
     */
    public function getActiveEventTriggers(string $tenantId, string $eventName): iterable;

    /**
     * Whether any flow of the tenant has an active event trigger on this event — a cheap check before an event is
     * queued, so a Core event nobody listens to costs no job.
     */
    public function hasActiveEventTrigger(string $tenantId, string $eventName): bool;

    /**
     * The tenant's active schedule triggers whose next slot has come, earliest slot first (then id), at most `$limit`.
     *
     * @return iterable<FlowTrigger>
     */
    public function dueSchedules(string $tenantId, CarbonImmutable $now, int $limit): iterable;

    /**
     * The next slot of the tenant's active schedule triggers that have one, by lowercased trigger id.
     *
     * @param  list<string>|null  $triggerIds  null for all of the tenant's
     *
     * @return array<string, DateTimeInterface>
     */
    public function nextScheduleRuns(string $tenantId, ?array $triggerIds): array;

    /**
     * The compare-and-set that claims a schedule's slot: moves the row from `$readSlot` (its raw `next_run_at` as the
     * caller read it) to `$next`, recording `$slot` as its last run, only if it still holds `$readSlot` and is still
     * active.
     *
     * @return bool whether this call moved it
     */
    public function moveScheduleSlot(FlowTrigger $trigger, mixed $readSlot, ?CarbonImmutable $next, CarbonImmutable $slot, CarbonImmutable $now): bool;

    /**
     * Stops every schedule trigger of the flow (no next slot), active or not.
     *
     * @return list<string> the ids of the flow's schedule triggers, empty when it has none
     */
    public function stopSchedules(string $flowId): array;
}
