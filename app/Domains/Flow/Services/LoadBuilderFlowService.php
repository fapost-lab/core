<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\DTOs\BuilderFlowDto;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Tenancy\Settings\TenantSettings;

final readonly class LoadBuilderFlowService
{
    public function __construct(
        private FlowDraftRepositoryInterface $drafts,
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowTriggerRepositoryInterface $triggers,
        private TenantEventRepositoryInterface $tenantEvents,
        private TenantSettings $tenantSettings,
    ) {
    }

    public function execute(string $flowId): BuilderFlowDto
    {
        $draft     = $this->drafts->findByFlowId($flowId);
        $published = $this->definitions->findLatestActiveByFlowId($flowId);
        $trigger   = $this->triggers->findByFlowId($flowId);

        $availableFlows = FlowDraft::query()
            ->where('assistant_id', $draft->assistant_id)
            ->orderBy('name')
            ->get(['flow_id', 'name'])
            ->map(static fn (FlowDraft $f): array => ['id' => $f->flow_id, 'name' => $f->name])
            ->values()
            ->all();

        return BuilderFlowDto::fromDraftAndDefinition(
            draft: $draft,
            published: $published,
            trigger: $trigger,
            availableEvents: $this->tenantEvents->getEventNamesByTenant($draft->tenant_id),
            contentBaseLanguage: $this->tenantSettings->content_base_language,
            availableLanguages: $this->tenantSettings->available_languages,
            availableFlows: $availableFlows,
        );
    }
}
