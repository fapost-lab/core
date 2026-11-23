<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Schedule\ScheduleClock;
use App\Domains\Flow\Schedule\ScheduleTriggerConfig;
use App\Domains\Flow\Support\FlowTriggerComparison;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

final readonly class FlowTriggerRepository implements FlowTriggerRepositoryInterface
{
    public function __construct(private ScheduleClock $clock)
    {
    }

    public function getActiveByType(string $tenantId, ?string $assistantId, string $type): iterable
    {
        $query = FlowTrigger::query()
            ->where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('is_active', true);

        if (null !== $assistantId) {
            $query->where(static function (Builder $subQuery) use ($assistantId): void {
                $subQuery
                    ->where('assistant_id', $assistantId)
                    ->orWhereNull('assistant_id');
            })
                // Deterministic precedence: assistant-specific triggers first, global fallback second.
                ->orderByRaw('CASE WHEN assistant_id IS NULL THEN 1 ELSE 0 END');
        } else {
            $query->whereNull('assistant_id');
        }

        return $query
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    public function find(string $triggerId): ?FlowTrigger
    {
        return FlowTrigger::query()->find(mb_strtolower($triggerId));
    }

    public function getByFlowId(string $flowId): array
    {
        return FlowTrigger::query()
            ->where('flow_id', $flowId)
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function syncForFlow(FlowDraft $draft, array $triggers): void
    {
        $existing = [];

        foreach ($this->getByFlowId($draft->flow_id) as $row) {
            $existing[mb_strtolower($row->id)] = $row;
        }

        foreach ($triggers as $trigger) {
            $id  = mb_strtolower($trigger['id']);
            $row = $existing[$id] ?? null;
            unset($existing[$id]);

            if (null === $row) {
                $row = new FlowTrigger();
                $row->forceFill([
                    'id'           => $id,
                    'tenant_id'    => $draft->tenant_id,
                    'assistant_id' => $draft->assistant_id,
                    'flow_id'      => $draft->flow_id,
                    'type'         => $trigger['type'],
                    'is_active'    => $trigger['is_active'],
                    'priority'     => $trigger['priority'],
                    'config'       => $trigger['config'],
                ]);
                $this->scheduleNextRun($row);
                $row->save();

                continue;
            }

            $row->fill([
                'tenant_id'    => $draft->tenant_id,
                'assistant_id' => $draft->assistant_id,
                'is_active'    => $trigger['is_active'],
                'priority'     => $trigger['priority'],
            ]);

            if ($row->typeName() !== $trigger['type'] || ! FlowTriggerComparison::sameConfig($row->config, $trigger['config'])) {
                $row->fill([
                    'type'        => $trigger['type'],
                    'config'      => $trigger['config'],
                    'next_run_at' => null,
                ]);
            }

            $this->scheduleNextRun($row);

            // An unchanged row is not saved: saving runs today's config validator, and a row an older validator
            // accepted must not block a publish that does not touch it.
            if ($row->isDirty()) {
                $row->save();
            }
        }

        foreach ($existing as $gone) {
            $gone->delete();
        }
    }

    public function getActiveOfType(string $tenantId, string $type): iterable
    {
        return FlowTrigger::query()
            ->where('tenant_id', $tenantId)
            ->where('type', $type)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    public function getActiveEventTriggers(string $tenantId, string $eventName): iterable
    {
        return FlowTrigger::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'event')
            ->where('is_active', true)
            ->where('config->event_name', $eventName)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    public function hasActiveEventTrigger(string $tenantId, string $eventName): bool
    {
        return FlowTrigger::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'event')
            ->where('is_active', true)
            ->where('config->event_name', $eventName)
            ->exists();
    }

    public function dueSchedules(string $tenantId, CarbonImmutable $now, int $limit): iterable
    {
        return FlowTrigger::query()
            ->where('tenant_id', $tenantId)
            ->where('type', FlowTriggerType::Schedule->value)
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function nextScheduleRuns(string $tenantId, ?array $triggerIds): array
    {
        $query = FlowTrigger::query()
            ->where('tenant_id', $tenantId)
            ->where('type', FlowTriggerType::Schedule->value)
            ->where('is_active', true)
            ->whereNotNull('next_run_at');

        if (null !== $triggerIds) {
            $query->whereIn('id', array_map(mb_strtolower(...), $triggerIds));
        }

        $due = [];

        foreach ($query->get(['id', 'next_run_at']) as $trigger) {
            if (null !== $trigger->next_run_at) {
                $due[mb_strtolower($trigger->id)] = $trigger->next_run_at;
            }
        }

        return $due;
    }

    public function moveScheduleSlot(FlowTrigger $trigger, mixed $readSlot, ?CarbonImmutable $next, CarbonImmutable $slot, CarbonImmutable $now): bool
    {
        return 1 === FlowTrigger::query()
            ->whereKey($trigger->getKey())
            ->where('next_run_at', $readSlot)
            ->where('is_active', true)
            ->toBase()
            ->update([
                'next_run_at' => $next,
                'last_run_at' => $slot,
                'updated_at'  => $now,
            ]);
    }

    public function stopSchedules(string $flowId): array
    {
        $schedules = FlowTrigger::query()
            ->where('flow_id', $flowId)
            ->where('type', FlowTriggerType::Schedule->value)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all();

        if ([] !== $schedules) {
            FlowTrigger::query()->whereKey($schedules)->toBase()->update(['next_run_at' => null, 'updated_at' => now()]);
        }

        return $schedules;
    }

    /**
     * A schedule trigger's next slot as the publish leaves it: an active one with none (new, its cron or time zone
     * changed, just switched on, or published before schedules ran) gets the first slot after now; one that already
     * has a slot keeps it; a switched-off one has none. Other types never have one.
     */
    private function scheduleNextRun(FlowTrigger $row): void
    {
        if (FlowTriggerType::Schedule !== $row->type || ! $row->is_active) {
            if (null !== $row->next_run_at) {
                $row->next_run_at = null;
            }

            return;
        }

        if (null !== $row->next_run_at) {
            return;
        }

        $row->next_run_at = $this->clock->nextSlotAfter(
            ScheduleTriggerConfig::fromConfig(is_array($row->config) ? $row->config : []),
            CarbonImmutable::now(),
        );
    }
}
