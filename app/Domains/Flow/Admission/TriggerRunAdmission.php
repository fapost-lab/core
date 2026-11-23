<?php

declare(strict_types=1);

namespace App\Domains\Flow\Admission;

use App\Domains\Flow\Contracts\AfterCommitDispatcherInterface;
use App\Domains\Flow\Contracts\FlowTriggerStateRepositoryInterface;
use App\Domains\Tenancy\Enums\RefusedWork;
use App\Domains\Tenancy\Services\PeriodQuota;
use App\Jobs\Flow\NotifyTriggerPausedJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Decides whether a trigger's new run may start (adr-20261123-flow-run-admission). Asked once per new fire — after the
 * fire was found to have no run, before the run is created — by every automatic start path: the automation runner
 * (webhook, Core event, API), a flow's own event ({@see \App\Jobs\Flow\StartFlowFromEventJob}) and the schedule's claim.
 * A message trigger never asks: conversations are not runs of a trigger.
 *
 * In order:
 *  1. a paused trigger refuses (`trigger_paused`);
 *  2. the run rate breaker counts the run in the trigger's minute; over the platform's limit it refuses
 *     (`rate_breaker`) and pauses the trigger — the call that paused it queues one notice to whoever manages
 *     automations, after the commit;
 *  3. the plan's run limit spends one unit of {@see self::LIMIT_KEY} through {@see PeriodQuota} (`plan_limit`; the
 *     limit's own notice goes to the admins).
 * The breaker comes before the plan, so a run the breaker stops spends no unit of the plan; a run the plan refuses is
 * still counted in the breaker's minute.
 *
 * A refusal starts nothing; it is counted on the trigger ({@see TriggerRefusals}) and logged `flow.trigger.refused`.
 * A cache that cannot count the breaker's window lets the run through, reported, as an operator outage does in
 * {@see PeriodQuota}: a broken counter must not stop every automation of the installation.
 *
 * Bound scoped: the quota reads the tenant context. Nothing is kept between calls.
 */
final readonly class TriggerRunAdmission
{
    /** The plan limit on runs started by a trigger other than a message, per period. */
    public const string LIMIT_KEY = 'flow_runs';

    public function __construct(
        private FlowTriggerStateRepositoryInterface $states,
        private TriggerRateBreaker $breaker,
        private PeriodQuota $quota,
        private TriggerRefusals $refusals,
        private AfterCommitDispatcherInterface $afterCommit,
        private Dispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public function admit(TriggerAdmissionRequest $request): AdmissionDecision
    {
        $reason = $this->refusalFor($request);

        if (null === $reason) {
            return AdmissionDecision::allowed();
        }

        $this->refusals->record($request->tenantId, $request->triggerId, $reason, $request->occurredAt);

        $this->logger->info('flow.trigger.refused', [
            ...$this->context($request),
            'reason' => $reason->value,
        ]);

        return AdmissionDecision::refused($reason);
    }

    private function refusalFor(TriggerAdmissionRequest $request): ?RefusalReason
    {
        if ($this->states->isPaused($request->triggerId)) {
            return RefusalReason::TriggerPaused;
        }

        if ($this->breakerTrips($request)) {
            $this->pause($request);

            return RefusalReason::RateBreaker;
        }

        $decision = $this->quota->consume(self::LIMIT_KEY, $request->unitKey, $request->occurredAt, RefusedWork::FlowRun);

        return $decision->allowed ? null : RefusalReason::PlanLimit;
    }

    private function breakerTrips(TriggerAdmissionRequest $request): bool
    {
        try {
            return $this->breaker->hit($request->tenantId, $request->triggerId);
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function pause(TriggerAdmissionRequest $request): void
    {
        if (! $this->states->pause($request->triggerId, RefusalReason::RateBreaker, $request->occurredAt)) {
            return;
        }

        $this->logger->warning('flow.trigger.paused', [
            ...$this->context($request),
            'reason' => RefusalReason::RateBreaker->value,
        ]);

        $job = new NotifyTriggerPausedJob(
            tenantId: $request->tenantId,
            flowId: $request->flowId,
            triggerId: $request->triggerId,
            triggerType: $request->triggerType,
            pausedAt: $request->occurredAt->format(DATE_ATOM),
        );

        $this->afterCommit->afterCommit(fn (): mixed => $this->dispatcher->dispatch($job));
    }

    /**
     * @return array{tenant_id: string, flow_id: string, trigger_id: string, trigger_type: string|null}
     */
    private function context(TriggerAdmissionRequest $request): array
    {
        return [
            'tenant_id'    => $request->tenantId,
            'flow_id'      => $request->flowId,
            'trigger_id'   => $request->triggerId,
            'trigger_type' => $request->triggerType,
        ];
    }
}
