<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Admission\RefusalReason;
use App\Domains\Flow\Models\FlowTriggerState;
use DateTimeImmutable;

/**
 * The only writer of `flow_trigger_states`: a live trigger's pause and its refused runs.
 *
 * A row is made on a trigger's first pause or refusal, and only while the trigger's `flow_triggers` row exists; for a
 * trigger that is gone every write is a no-op.
 */
interface FlowTriggerStateRepositoryInterface
{
    public function isPaused(string $triggerId): bool;

    /**
     * Pause the trigger unless it already is.
     *
     * @return bool true only for the call that paused it
     */
    public function pause(string $triggerId, RefusalReason $reason, DateTimeImmutable $at): bool;

    /**
     * Lift the trigger's pause, keeping its refusal counter.
     *
     * @return bool true when the trigger was paused
     */
    public function resume(string $triggerId, DateTimeImmutable $at): bool;

    /**
     * Add {@code $count} refused runs to the trigger's counter, the last one refused at {@code $at} for {@code $reason}.
     */
    public function addRefusals(string $triggerId, int $count, RefusalReason $reason, DateTimeImmutable $at): void;

    /**
     * The states of the given triggers that have one, by lower-cased trigger id.
     *
     * @param  list<string>  $triggerIds
     *
     * @return array<string, FlowTriggerState>
     */
    public function forTriggers(array $triggerIds): array;
}
