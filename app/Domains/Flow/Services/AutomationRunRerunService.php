<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\DTOs\RerunOutcome;
use App\Domains\Flow\Enums\FlowRunKind;
use App\Domains\Flow\Enums\FlowSessionStatus;
use App\Domains\Flow\Enums\RerunRefusal;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Flow\Orchestration\RerunFireKey;
use App\Jobs\Flow\StartAutomationRunJob;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Re-runs a failed automation run from the start, with the trigger payload it started with (spec `flow-automations`,
 * "Re-run from the start").
 *
 * A re-run takes the path every trigger takes: it queues {@see StartAutomationRunJob} for the source's trigger, under a
 * fire key of its own ({@see RerunFireKey}), with the source's assistant and event chain depth. The request names the
 * attempt the person confirmed ({@see check()} offers the next one), and the key is made from it: a double click, a
 * second tab or a replayed request makes the same key, which the trigger's unique `(trigger_id, fire_key)` starts once,
 * and one that arrives after that run has started is refused as {@see RerunRefusal::AlreadyRerun}. The trigger's lock, the
 * queue, the run's deadline and segments apply to it as to any run, and it runs the flow's latest active version. Its
 * key never starts with `wh:`, so it answers no webhook sender and leaves the delivery dedup alone.
 *
 * Only a root automation run that failed and kept its payload is re-run, and only while its trigger is still live, its
 * flow is not switched off in the flows list and has an active version; anything else is a refusal with its reason, never an exception. The source run is
 * not changed. Every queued re-run is recorded in the audit log, and the entry is committed before the job is queued:
 * a queue that refuses the job leaves an entry for a re-run the person is told failed, and asking again makes the same
 * key, so the run still starts once.
 *
 * The tenant is the source session's own, passed to the job explicitly.
 */
final readonly class AutomationRunRerunService
{
    public function __construct(
        private FlowTriggerRepositoryInterface $triggers,
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowDraftRepositoryInterface $drafts,
        private AuditTrailInterface $audit,
        private DatabaseManager $db,
        private Dispatcher $dispatcher,
    ) {
    }

    /**
     * Whether the run can be re-run now, on which version of its flow, and as which attempt.
     */
    public function check(FlowSession $source): RerunOutcome
    {
        $refusal = $this->refusalOf($source);

        if (null !== $refusal) {
            return RerunOutcome::refused($refusal);
        }

        if ($this->drafts->isSwitchedOff((string) $source->flowDefinition?->flow_id)) {
            return RerunOutcome::refused(RerunRefusal::FlowOff);
        }

        $target = $this->targetDefinition($source);

        return null === $target
            ? RerunOutcome::refused(RerunRefusal::NoActiveVersion)
            : RerunOutcome::possible($target->version, $this->lastAttempt($source) + 1);
    }

    /**
     * Queues the given attempt of the source's re-runs, or says why it cannot be.
     *
     * @param  int  $attempt  the attempt {@see check()} offered when the person confirmed
     */
    public function rerun(FlowSession $source, int $attempt): RerunOutcome
    {
        $check = $this->check($source);

        if ($check->isRefused() || null === $check->targetVersion) {
            return $check;
        }

        if ($attempt !== $check->attempt) {
            return RerunOutcome::refused(RerunRefusal::AlreadyRerun);
        }

        $sourceId = (string) $source->getKey();
        $tenantId = (string) $source->tenant_id;
        $flowId   = (string) $source->flowDefinition?->flow_id;
        $fireKey  = RerunFireKey::for($sourceId, $attempt);

        $this->db->transaction(function () use ($source, $sourceId, $fireKey, $check): void {
            $this->audit->record(new AuditRecord(
                action: AuditAction::FlowRunRerun,
                subject: $source,
                subjectLabel: $source->flowDefinition?->name,
                assistantId: $source->assistant_id,
                properties: [
                    'session_id'   => $sourceId,
                    'fire_key'     => $fireKey,
                    'flow_version' => $check->targetVersion,
                ],
            ));
        });

        $this->dispatcher->dispatch(new StartAutomationRunJob(
            tenantId: $tenantId,
            flowId: $flowId,
            triggerId: (string) $source->trigger_id,
            fireKey: $fireKey,
            initialState: $source->trigger_payload ?? [],
            assistantId: $source->assistant_id,
            chainDepth: $source->event_chain_depth,
        ));

        return RerunOutcome::queued($check->targetVersion, $attempt, $fireKey);
    }

    /**
     * Why the run itself can never be re-run, or whether its trigger is gone; the flow's active version is asked apart.
     */
    private function refusalOf(FlowSession $source): ?RerunRefusal
    {
        if (FlowRunKind::Automation !== $source->run_kind) {
            return RerunRefusal::NotAnAutomation;
        }

        if (null !== $source->getAttribute('parent_session_id')) {
            return RerunRefusal::NotARoot;
        }

        if (FlowSessionStatus::Failed !== $source->status) {
            return RerunRefusal::NotFailed;
        }

        if (null === $source->trigger_id || null === $source->fire_key || null === $source->trigger_payload) {
            return RerunRefusal::NoPayload;
        }

        return $this->triggerIsLive($source) ? null : RerunRefusal::TriggerGone;
    }

    /**
     * The source's trigger row is still there, active, of the same flow and tenant, and the flow still has its draft: a
     * deleted flow leaves its definitions and trigger rows behind.
     */
    private function triggerIsLive(FlowSession $source): bool
    {
        $flowId = $source->flowDefinition?->flow_id;

        if (null === $flowId) {
            return false;
        }

        $live = false;

        foreach ($this->triggers->getByFlowId($flowId) as $trigger) {
            if (mb_strtolower($trigger->id) === mb_strtolower((string) $source->trigger_id)) {
                $live = $trigger->is_active && $trigger->tenant_id === $source->tenant_id;

                break;
            }
        }

        if (! $live) {
            return false;
        }

        try {
            $this->drafts->findByFlowId($flowId);
        } catch (ModelNotFoundException) {
            return false;
        }

        return true;
    }

    private function targetDefinition(FlowSession $source): ?FlowDefinition
    {
        $flowId = $source->flowDefinition?->flow_id;

        return null === $flowId ? null : $this->definitions->findLatestActiveByFlowId($flowId);
    }

    /**
     * The highest attempt among the source's re-runs that have a run; 0 when none has. Not their count: run retention
     * deletes a re-run on its own status, so a lower attempt can be gone while a higher one is kept, and a count would
     * offer the kept one's key again.
     */
    private function lastAttempt(FlowSession $source): int
    {
        $keys = FlowSession::query()
            ->where('tenant_id', $source->tenant_id)
            ->where('trigger_id', $source->trigger_id)
            ->where('fire_key', 'like', RerunFireKey::prefixOf((string) $source->getKey()) . '%')
            ->pluck('fire_key');

        $last = 0;

        foreach ($keys as $key) {
            $last = max($last, RerunFireKey::attemptOf((string) $key) ?? 0);
        }

        return $last;
    }
}
