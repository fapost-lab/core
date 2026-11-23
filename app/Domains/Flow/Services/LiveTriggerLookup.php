<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Enums\FlowTriggerType;
use App\Domains\Flow\Models\FlowTrigger;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Finds a trigger an outside system calls — a webhook's or an API trigger's — as it is live now: a `flow_triggers` row
 * of the tenant, of the given type and active, whose flow has an active runnable definition and still has its draft
 * and the draft's assistant. A trigger only saved in a draft has no row yet; anything else is no trigger at all.
 *
 * Bound scoped: it reads tenant-schema repositories.
 */
final readonly class LiveTriggerLookup
{
    public function __construct(
        private FlowTriggerRepositoryInterface $triggers,
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowDraftRepositoryInterface $drafts,
    ) {
    }

    /**
     * @param  string|null  $flowId  the flow the caller already knows the trigger by (a webhook endpoint's), or null
     */
    public function find(string $tenantId, string $triggerId, FlowTriggerType $type, ?string $flowId = null): ?FlowTrigger
    {
        $row = $this->triggers->find($triggerId);

        if (null === $row
            || mb_strtolower((string) $row->tenant_id) !== mb_strtolower($tenantId)
            || (null !== $flowId && mb_strtolower($row->flow_id) !== mb_strtolower($flowId))
            || $type !== $row->builtInType()
            || ! $row->is_active) {
            return null;
        }

        if (! $this->flowIsManaged($row->flow_id)) {
            return null;
        }

        return null === $this->definitions->findLatestActiveByFlowId($row->flow_id) ? null : $row;
    }

    /**
     * Whether someone can still manage the flow: its draft and the draft's assistant exist. A deleted flow or assistant
     * leaves its definitions and trigger rows behind, but an address nobody can revoke must not start runs.
     */
    private function flowIsManaged(string $flowId): bool
    {
        try {
            return null !== $this->drafts->findByFlowId($flowId)->assistant;
        } catch (ModelNotFoundException) {
            return false;
        }
    }
}
