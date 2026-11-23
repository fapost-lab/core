<?php

declare(strict_types=1);

namespace App\Domains\Flow\Admission;

use App\Domains\Audit\Contracts\AuditTrailInterface;
use App\Domains\Audit\DTOs\AuditRecord;
use App\Domains\Audit\Enums\AuditAction;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerStateRepositoryInterface;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;

/**
 * The admission state of a flow's live triggers as staff see it, and the one way a pause is lifted: a person resumes
 * the trigger. A publish never lifts it, and nothing lifts it on a timer.
 *
 * Bound scoped: it reads the tenant context.
 */
final readonly class TriggerPauseService
{
    public function __construct(
        private FlowTriggerRepositoryInterface $triggers,
        private FlowTriggerStateRepositoryInterface $states,
        private TriggerRateBreaker $breaker,
        private TriggerRefusals $refusals,
        private TenantContextInterface $tenantContext,
        private AuditTrailInterface $audit,
        private DatabaseManager $db,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The state of each of the flow's live triggers that has one, by lower-cased trigger id, with the refusals still
     * waiting in the cache for their write counted in.
     *
     * @return array<string, array{paused_at: string|null, paused_reason: string|null, refused_count: int, last_refused_at: string|null, last_refusal_reason: string|null}>
     */
    public function describe(string $flowId): array
    {
        $ids = array_map(static fn ($trigger): string => mb_strtolower($trigger->id), $this->triggers->getByFlowId($flowId));

        if ([] === $ids) {
            return [];
        }

        $tenantId = (string) $this->tenantContext->get()->getId();
        $stored   = $this->states->forTriggers($ids);
        $states   = [];

        foreach ($ids as $id) {
            $state   = $stored[$id] ?? null;
            $pending = $this->refusals->pending($tenantId, $id);

            if (null === $state && 0 === $pending['count']) {
                continue;
            }

            $states[$id] = [
                'paused_at'           => $state?->paused_at?->toIso8601String(),
                'paused_reason'       => $state?->paused_reason?->value,
                'refused_count'       => ($state->refused_count ?? 0) + $pending['count'],
                'last_refused_at'     => $pending['last_refused_at'] ?? $state?->last_refused_at?->toIso8601String(),
                'last_refusal_reason' => $pending['last_refusal_reason'] ?? $state?->last_refusal_reason?->value,
            ];
        }

        return $states;
    }

    /**
     * Lift the pause of the flow's live trigger and start its breaker window over. An actual resume is recorded in the
     * audit log, in the same transaction.
     *
     * @return bool false when the flow has no such live trigger
     */
    public function resume(FlowDraft $draft, string $triggerId): bool
    {
        $trigger = $this->triggers->find($triggerId);

        if (null === $trigger || mb_strtolower($trigger->flow_id) !== mb_strtolower($draft->flow_id)) {
            return false;
        }

        $tenantId  = (string) $this->tenantContext->get()->getId();
        $wasPaused = $this->db->transaction(function () use ($draft, $trigger): bool {
            if (! $this->states->resume($trigger->id, CarbonImmutable::now())) {
                return false;
            }

            $this->audit->record(new AuditRecord(
                action: AuditAction::FlowTriggerResumed,
                subject: $draft,
                subjectLabel: (string) $draft->name,
                assistantId: $draft->assistant_id,
                properties: ['trigger_id' => $trigger->id, 'trigger_type' => $trigger->type->value],
            ));

            return true;
        });

        $this->breaker->clear($tenantId, $trigger->id);

        if ($wasPaused) {
            $this->logger->info('flow.trigger.resumed', [
                'tenant_id'  => $tenantId,
                'flow_id'    => $trigger->flow_id,
                'trigger_id' => $trigger->id,
            ]);
        }

        return true;
    }
}
