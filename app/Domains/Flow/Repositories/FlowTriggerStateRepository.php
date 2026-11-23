<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Flow\Admission\RefusalReason;
use App\Domains\Flow\Contracts\FlowTriggerStateRepositoryInterface;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Models\FlowTriggerState;
use DateTimeImmutable;

final readonly class FlowTriggerStateRepository implements FlowTriggerStateRepositoryInterface
{
    public function isPaused(string $triggerId): bool
    {
        return FlowTriggerState::query()
            ->where('trigger_id', mb_strtolower($triggerId))
            ->whereNotNull('paused_at')
            ->exists();
    }

    public function pause(string $triggerId, RefusalReason $reason, DateTimeImmutable $at): bool
    {
        $this->ensureRow($triggerId, $at);

        // Conditional: of two workers tripping the breaker at once, one pauses and the other sees it paused.
        return 1 === FlowTriggerState::query()
            ->where('trigger_id', mb_strtolower($triggerId))
            ->whereNull('paused_at')
            ->toBase()
            ->update([
                'paused_at'     => $at,
                'paused_reason' => $reason->value,
                'updated_at'    => $at,
            ]);
    }

    public function resume(string $triggerId, DateTimeImmutable $at): bool
    {
        return 1 === FlowTriggerState::query()
            ->where('trigger_id', mb_strtolower($triggerId))
            ->whereNotNull('paused_at')
            ->toBase()
            ->update([
                'paused_at'     => null,
                'paused_reason' => null,
                'resumed_at'    => $at,
                'updated_at'    => $at,
            ]);
    }

    public function addRefusals(string $triggerId, int $count, RefusalReason $reason, DateTimeImmutable $at): void
    {
        if ($count < 1) {
            return;
        }

        $this->ensureRow($triggerId, $at);

        // One atomic statement: concurrent refusals add up rather than overwrite each other.
        FlowTriggerState::query()
            ->where('trigger_id', mb_strtolower($triggerId))
            ->toBase()
            ->increment('refused_count', $count, [
                'last_refused_at'     => $at,
                'last_refusal_reason' => $reason->value,
                'updated_at'          => $at,
            ]);
    }

    public function forTriggers(array $triggerIds): array
    {
        if ([] === $triggerIds) {
            return [];
        }

        $states = [];

        foreach (FlowTriggerState::query()->whereIn('trigger_id', array_map(mb_strtolower(...), $triggerIds))->get() as $state) {
            $states[mb_strtolower($state->trigger_id)] = $state;
        }

        return $states;
    }

    /**
     * Make the trigger's row unless it exists, and only while the trigger does: a trigger a publish just deleted gets no
     * row. A delete between the check and the insert is left to the foreign key; admission reads the trigger just before.
     */
    private function ensureRow(string $triggerId, DateTimeImmutable $at): void
    {
        $triggerId = mb_strtolower($triggerId);

        if (! FlowTrigger::query()->whereKey($triggerId)->exists()) {
            return;
        }

        FlowTriggerState::query()->toBase()->insertOrIgnore([
            'id'            => (new FlowTriggerState())->newUniqueId(),
            'trigger_id'    => $triggerId,
            'refused_count' => 0,
            'created_at'    => $at,
            'updated_at'    => $at,
        ]);
    }
}
