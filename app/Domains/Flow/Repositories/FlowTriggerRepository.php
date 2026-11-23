<?php

declare(strict_types=1);

namespace App\Domains\Flow\Repositories;

use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Models\FlowTrigger;
use App\Domains\Flow\Schedule\ScheduleClock;
use App\Domains\Flow\Schedule\ScheduleTriggerConfig;
use App\Domains\Flow\Services\FlowTriggerSet;
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

            if ($row->type->value !== $trigger['type'] || ! FlowTriggerSet::sameConfig($row->config, $trigger['config'])) {
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
