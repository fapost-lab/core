<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\DTOs\BuilderFlowDto;

final readonly class LoadBuilderFlowService
{
    public function __construct(
        private FlowDraftRepositoryInterface $drafts,
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowTriggerRepositoryInterface $triggers,
        private TenantEventRepositoryInterface $tenantEvents,
    ) {
    }

    public function execute(string $flowId): BuilderFlowDto
    {
        $draft     = $this->drafts->findByFlowId($flowId);
        $published = $this->definitions->findLatestActiveByFlowId($flowId);
        $trigger   = $this->triggers->findByFlowId($flowId);

        return BuilderFlowDto::fromDraftAndDefinition(
            $draft,
            $published,
            $trigger,
            $this->tenantEvents->getEventNamesByTenant($draft->tenant_id),
        );
    }
}
