<?php

declare(strict_types=1);

namespace App\Domains\Flow\Schedule;

use App\Domains\Flow\Contracts\AfterCommitDispatcherInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Tenancy\Contracts\FlowScheduleRegistryInterface;
use Carbon\CarbonImmutable;

/**
 * Copies schedule triggers' next slots from the tenant's `flow_triggers` (the truth) into the landlord registry
 * ({@see FlowScheduleRegistryInterface}), always after the tenant's transaction committed: a registry row written for a
 * change that was rolled back would only cost a useless visit, but one written before the commit could be read before
 * the slot it points at exists.
 *
 * Tenant context is owned by the caller.
 */
final readonly class ScheduleRegistrySync
{
    public function __construct(
        private FlowScheduleRegistryInterface $registry,
        private FlowTriggerRepositoryInterface $triggers,
        private AfterCommitDispatcherInterface $afterCommitDispatcher,
    ) {
    }

    /**
     * Once the current transaction commits, record each listed trigger as its row then stands: due at its
     * `next_run_at` when it is an active schedule with one, forgotten otherwise (gone, switched off, another type).
     *
     * @param  list<string>  $triggerIds
     */
    public function syncAfterCommit(string $tenantId, array $triggerIds): void
    {
        if ([] === $triggerIds) {
            return;
        }

        $this->afterCommitDispatcher->afterCommit(function () use ($tenantId, $triggerIds): void {
            $due = $this->triggers->nextScheduleRuns($tenantId, $triggerIds);

            foreach (array_unique(array_map(mb_strtolower(...), $triggerIds)) as $triggerId) {
                if (isset($due[$triggerId])) {
                    $this->registry->put($tenantId, $triggerId, $due[$triggerId]);
                } else {
                    $this->registry->forget($triggerId);
                }
            }
        });
    }

    /**
     * Rewrite every registry row of the tenant from its triggers. The moment is taken before the triggers are read, so
     * a row a publish writes meanwhile ({@see syncAfterCommit()}) is newer than the read and survives the rewrite.
     *
     * @return int how many triggers are recorded
     */
    public function rebuild(string $tenantId): int
    {
        $readAt = CarbonImmutable::now();
        $due    = $this->triggers->nextScheduleRuns($tenantId, null);

        $this->registry->replaceForTenant($tenantId, $due, $readAt);

        return count($due);
    }
}
