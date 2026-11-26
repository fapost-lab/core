<?php

declare(strict_types=1);

namespace App\Domains\Flow\Schedule;

use App\Domains\Flow\Admission\TriggerAdmissionRequest;
use App\Domains\Flow\Admission\TriggerRunAdmission;
use App\Domains\Flow\Contracts\AfterCommitDispatcherInterface;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowEngineInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\DTOs\FlowRunOrigin;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Models\FlowTrigger;
use App\Jobs\Flow\StartAutomationRunJob;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Claims the due slots of one tenant's schedule triggers and creates their runs (adr-20261123-flow-schedule-trigger).
 *
 * The truth is the tenant's `flow_triggers.next_run_at`. A slot is claimed by a compare-and-set on it — the row moves
 * on (to its next slot, or to none) only if it still holds the one this pass read — and the slot's fate is decided and
 * written:
 *
 *  - a trigger whose config can never run (published under an older validator) starts nothing
 *    (`flow.schedule.unusable_config`) and its schedule stops: the row moves to no slot;
 *  - a flow nobody can manage any more — its draft or the draft's assistant is gone — starts nothing
 *    (`flow.schedule.tick_skipped`, `flow_not_live`) and its schedule stops: the row moves to no slot, as when the flow
 *    is deleted, since nobody is left to switch it off. These two fates are decided first, before the transaction and
 *    whether the slot is late; every other one below is decided in one transaction of the tenant connection with the
 *    move to the next slot;
 *  - a slot claimed more than {@see self::LATE_AFTER_SECONDS} after it came due (the platform or the tenant was down)
 *    starts nothing and logs `flow.schedule.tick_missed`; the next slot is counted from now, so a downtime is one line
 *    and the missed slots are never caught up;
 *  - a slot claimed late, but within that bound, is run, and the slots that came due after it before the pass are
 *    missed: a pass runs one slot per trigger, and the ones it passes over are logged as one
 *    `flow.schedule.tick_missed` with their count — late passes without a downtime are the signal that the pass
 *    outgrows the minute;
 *  - a flow without an active runnable definition starts nothing (`flow.schedule.tick_skipped`, `flow_not_live`);
 *  - a flow switched off in the flows list starts nothing (`flow.schedule.tick_skipped`, `flow_off`, at info: the
 *    owner chose it): the slot is spent, so turning the flow back on starts only its next slot, and run admission is
 *    not asked;
 *  - a previous run of the trigger still going — its root `pending` or in flight, waiting in a `delay` or for an answer
 *    included — starts nothing (`flow.schedule.tick_skipped`, `previous_run_live`);
 *  - run admission refuses the run ({@see TriggerRunAdmission}: the trigger is paused, the run rate breaker trips or the
 *    plan's run limit is used up) — starts nothing (`tick_skipped`, `admission_refused`); the refusal is counted on the
 *    trigger and the slot is spent;
 *  - otherwise the run is created `pending` ({@see FlowEngineInterface::prepare()}) with the slot's fire key, so a slot
 *    eaten by the claim always leaves a run: a lost start job leaves it for the deadline sweeper (`start_lost`).
 *
 * After the commit {@see StartAutomationRunJob} is queued for each created run, delayed by a jitter inside the minute
 * that depends only on the trigger. The landlord registry is not written here: the caller rewrites the tenant's rows
 * from the truth once the pass is done.
 *
 * Tenant context is owned by the caller; the tenant is passed explicitly as well.
 */
final readonly class ScheduleTriggerFirer
{
    /** A slot claimed later than this after it came due is missed, not run: a schedule never catches up. */
    public const int LATE_AFTER_SECONDS = 300;

    /** The most due triggers one pass claims in a tenant; the rest are still due on the next pass. */
    public const int MAX_PER_PASS = 500;

    public const string SKIP_FLOW_NOT_LIVE = 'flow_not_live';

    public const string SKIP_FLOW_OFF = 'flow_off';

    public const string SKIP_PREVIOUS_RUN_LIVE = 'previous_run_live';

    public const string SKIP_ADMISSION_REFUSED = 'admission_refused';

    /** The most passed-over slots one claim counts; a minutely schedule passes over at most five within the bound. */
    private const int MAX_PASSED_OVER = 10;

    public function __construct(
        private ConnectionInterface $connection,
        private ScheduleClock $clock,
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowDraftRepositoryInterface $drafts,
        private FlowTriggerRepositoryInterface $triggers,
        private FlowEngineInterface $engine,
        private TriggerRunAdmission $admission,
        private AfterCommitDispatcherInterface $afterCommitDispatcher,
        private Dispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Claim every slot of {@code $tenantId} due at {@code $now}.
     *
     * @return array{fired: int, skipped: int, missed: int}
     */
    public function fireDue(string $tenantId, CarbonImmutable $now): array
    {
        $report = ['fired' => 0, 'skipped' => 0, 'missed' => 0];

        foreach ($this->triggers->dueSchedules($tenantId, $now, self::MAX_PER_PASS) as $trigger) {
            try {
                $outcome = $this->claim($tenantId, $trigger, $now);
            } catch (Throwable $exception) {
                $this->logger->error('flow.schedule.claim_failed', [
                    ...$this->context($tenantId, $trigger),
                    'exception' => $exception->getMessage(),
                ]);

                continue;
            }

            if (null !== $outcome) {
                ++$report[$outcome];
            }
        }

        return $report;
    }

    /**
     * The run's `flow.schedule.*`: the slot in UTC and in the trigger's time zone, and the schedule that made it.
     *
     * @return array{flow: array{schedule: array{slot: string, local_time: string, timezone: string, cron: string}}}
     */
    private static function initialState(CarbonImmutable $slot, ScheduleTriggerConfig $config): array
    {
        return [
            'flow' => [
                'schedule' => [
                    'slot'       => $slot->utc()->format(DateTimeInterface::ATOM),
                    'local_time' => $slot->setTimezone(new DateTimeZone($config->timezone))->format(DateTimeInterface::ATOM),
                    'timezone'   => $config->timezone,
                    'cron'       => $config->cron,
                ],
            ],
        ];
    }

    /**
     * A delay inside the minute that depends only on the trigger, so the triggers of a busy minute do not all start
     * at its first second. The fire key never depends on it.
     */
    private static function jitterSeconds(string $triggerId): int
    {
        return crc32(mb_strtolower($triggerId)) % 60;
    }

    /**
     * @return 'fired'|'skipped'|'missed'|null null when another pass took the slot, or the config cannot run
     */
    private function claim(string $tenantId, FlowTrigger $trigger, CarbonImmutable $now): ?string
    {
        $readSlot = $trigger->getRawOriginal('next_run_at');
        $slot     = CarbonImmutable::instance($trigger->next_run_at)->utc();

        try {
            $config = ScheduleTriggerConfig::fromConfig(is_array($trigger->config) ? $trigger->config : []);
        } catch (InvalidScheduleConfigException) {
            // Published under an older validator: it can never run, so it stops coming due.
            $this->logger->warning('flow.schedule.unusable_config', $this->context($tenantId, $trigger));
            $this->triggers->moveScheduleSlot($trigger, $readSlot, null, $slot, $now);

            return null;
        }

        $context = [...$this->context($tenantId, $trigger), 'slot' => $slot->toIso8601String()];

        if (! $this->isManageable($trigger->flow_id)) {
            // Nobody can switch this schedule off any more: it stops, as it does when its flow is deleted.
            if (! $this->triggers->moveScheduleSlot($trigger, $readSlot, null, $slot, $now)) {
                return null;
            }

            $this->logger->warning('flow.schedule.tick_skipped', [...$context, 'reason' => self::SKIP_FLOW_NOT_LIVE]);

            return 'skipped';
        }

        $next = $this->clock->nextSlotAfter($config, $slot->greaterThan($now) ? $slot : $now);

        return $this->connection->transaction(function () use ($tenantId, $trigger, $readSlot, $slot, $next, $now, $config, $context): ?string {
            if (! $this->triggers->moveScheduleSlot($trigger, $readSlot, $next, $slot, $now)) {
                return null;
            }

            $late = $now->getTimestamp() - $slot->getTimestamp();

            if ($late > self::LATE_AFTER_SECONDS) {
                $this->logger->warning('flow.schedule.tick_missed', [...$context, 'late_seconds' => $late]);

                return 'missed';
            }

            $this->logPassedOver($config, $slot, $next, $now, $context);

            $definition = $this->definitions->findLatestActiveByFlowId($trigger->flow_id);

            if (null === $definition) {
                $this->logger->warning('flow.schedule.tick_skipped', [...$context, 'reason' => self::SKIP_FLOW_NOT_LIVE]);

                return 'skipped';
            }

            if ($this->drafts->isSwitchedOff($trigger->flow_id)) {
                // The owner switched the flow off on purpose: as for the other automatic triggers, this is not a warning.
                $this->logger->info('flow.schedule.tick_skipped', [...$context, 'reason' => self::SKIP_FLOW_OFF]);

                return 'skipped';
            }

            if ($this->previousRunIsLive($trigger)) {
                $this->logger->warning('flow.schedule.tick_skipped', [...$context, 'reason' => self::SKIP_PREVIOUS_RUN_LIVE]);

                return 'skipped';
            }

            $fireKey  = ScheduleClock::fireKey($slot);
            $decision = $this->admission->admit(new TriggerAdmissionRequest(
                tenantId: $tenantId,
                flowId: $trigger->flow_id,
                triggerId: $trigger->id,
                triggerType: FlowTriggerType::Schedule->value,
                unitKey: TriggerAdmissionRequest::unitKeyFor($trigger->id, $fireKey),
                occurredAt: $now,
            ));

            if (null !== $decision->refusal) {
                $this->logger->warning('flow.schedule.tick_skipped', [
                    ...$context,
                    'reason'  => self::SKIP_ADMISSION_REFUSED,
                    'refusal' => $decision->refusal->value,
                ]);

                return 'skipped';
            }

            $initialState = self::initialState($slot, $config);

            $this->engine->prepare(
                $definition,
                $initialState,
                FlowRunOrigin::automation($trigger->id, $trigger->assistant_id, $fireKey),
            );

            $job = (new StartAutomationRunJob(
                tenantId: $tenantId,
                flowId: $trigger->flow_id,
                triggerId: $trigger->id,
                fireKey: $fireKey,
                initialState: $initialState,
                assistantId: $trigger->assistant_id,
            ))->delay(self::jitterSeconds($trigger->id));

            $this->afterCommitDispatcher->afterCommit(fn (): mixed => $this->dispatcher->dispatch($job));

            return 'fired';
        });
    }

    /**
     * Whether someone can still manage the flow: its draft and the draft's assistant exist, as for a webhook.
     */
    private function isManageable(string $flowId): bool
    {
        try {
            return null !== $this->drafts->findByFlowId($flowId)->assistant;
        } catch (ModelNotFoundException) {
            return false;
        }
    }

    /**
     * Log the slots that came due after {@code $slot} and before this pass: this claim runs only {@code $slot}, and the
     * next slot is counted from now, so they are missed. One line, with their count and the first of them.
     *
     * @param  array<string, string>  $context
     */
    private function logPassedOver(ScheduleTriggerConfig $config, CarbonImmutable $slot, ?CarbonImmutable $next, CarbonImmutable $now, array $context): void
    {
        $first  = null;
        $count  = 0;
        $cursor = $slot;

        while ($count < self::MAX_PASSED_OVER) {
            $candidate = $this->clock->nextSlotAfter($config, $cursor);

            if (null === $candidate || $candidate->greaterThan($now) || (null !== $next && $candidate->greaterThanOrEqualTo($next))) {
                break;
            }

            $first ??= $candidate;
            ++$count;
            $cursor = $candidate;
        }

        if (null === $first) {
            return;
        }

        $this->logger->warning('flow.schedule.tick_missed', [
            ...$context,
            'slot'         => $first->toIso8601String(),
            'late_seconds' => $now->getTimestamp() - $first->getTimestamp(),
            'missed_slots' => $count,
            'run_slot'     => $slot->toIso8601String(),
        ]);
    }

    /**
     * Whether a run of the trigger is still going: its root waits to start, runs, or waits in a `delay`, for an answer
     * or for a subflow.
     */
    private function previousRunIsLive(FlowTrigger $trigger): bool
    {
        return FlowSession::query()
            ->where('trigger_id', $trigger->id)
            ->whereNull('parent_session_id')
            ->whereIn('status', [FlowSessionStatus::Pending->value, ...FlowSessionStatus::inFlightValues()])
            ->exists();
    }

    /**
     * @return array{tenant_id: string, flow_id: string, trigger_id: string}
     */
    private function context(string $tenantId, FlowTrigger $trigger): array
    {
        return ['tenant_id' => $tenantId, 'flow_id' => $trigger->flow_id, 'trigger_id' => $trigger->id];
    }
}
