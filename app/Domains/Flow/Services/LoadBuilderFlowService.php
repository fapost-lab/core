<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Support\CountryCatalog;
use App\Domains\Flow\Action\ActionHandlerRegistry;
use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\Contracts\FlowTriggerRepositoryInterface;
use App\Domains\Flow\Contracts\SolutionAvailabilityInterface;
use App\Domains\Flow\Contracts\TenantEventRepositoryInterface;
use App\Domains\Flow\DTOs\BuilderFlowDto;
use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowDraft;
use App\Domains\Flow\Templates\TemplateExpander;
use App\Domains\Flow\Templates\TemplateUsageFinder;
use App\Domains\Tenancy\Settings\TenantSettings;

final readonly class LoadBuilderFlowService
{
    public function __construct(
        private FlowDraftRepositoryInterface $drafts,
        private FlowDefinitionRepositoryInterface $definitions,
        private FlowTriggerRepositoryInterface $triggers,
        private TenantEventRepositoryInterface $tenantEvents,
        private TenantSettings $tenantSettings,
        private ActionHandlerRegistry $actionHandlers,
        private SolutionAvailabilityInterface $actionAvailability,
        private CountryCatalog $countries,
        private TemplateExpander $expander,
        private TemplateUsageFinder $templateUsages,
    ) {
    }

    public function execute(string $flowId): BuilderFlowDto
    {
        $draft     = $this->drafts->findByFlowId($flowId);
        $published = $this->definitions->findLatestPublishedByFlowId($flowId);
        $trigger   = $this->triggers->findByFlowId($flowId);

        $availableFlows = FlowDraft::query()
            ->where('assistant_id', $draft->assistant_id)
            ->where('kind', FlowKind::Flow->value)
            ->orderBy('name')
            ->get(['flow_id', 'name'])
            ->map(static fn (FlowDraft $f): array => ['id' => $f->flow_id, 'name' => $f->name])
            ->values()
            ->all();

        $assistant          = Assistant::query()->select(['id', 'available_countries'])->find($draft->assistant_id);
        $assistantCountries = is_array($assistant?->available_countries) ? $assistant->available_countries : [];

        return BuilderFlowDto::fromDraftAndDefinition(
            draft: $draft,
            published: $published,
            trigger: $trigger,
            availableEvents: $this->tenantEvents->getEventNamesByTenant($draft->tenant_id),
            contentBaseLanguage: $this->tenantSettings->content_base_language,
            availableLanguages: $this->tenantSettings->available_languages,
            availableFlows: $availableFlows,
            availableActions: $this->availableActions(),
            availableCountries: $this->countries->toOptions($assistantCountries),
            availableTemplates: $draft->isTemplate() ? [] : $this->availableTemplates((string) $draft->assistant_id),
            usedBy: $draft->isTemplate()
                ? array_map(static fn (FlowDraft $host): array => ['id' => $host->flow_id, 'name' => $host->name], $this->templateUsages->hostsOf($draft))
                : [],
        );
    }

    /**
     * Core's actions and the actions of the Solutions the builder's tenant has activated. A flow that
     * already uses an action of a Solution that is switched off still opens: the node keeps its saved value.
     *
     * @return list<string>
     */
    public function availableActions(): array
    {
        return array_values(array_filter(
            $this->actionHandlers->ids(),
            fn (string $id): bool => $this->actionAvailability->isAvailable($this->actionHandlers->ownerOf($id)),
        ));
    }

    /**
     * The assistant's published templates with the outputs and parameters of their active version. A parameter's
     * `default` is the template's current value of its field, which the builder shows where the flow sets none.
     *
     * @return list<array{id: string, name: string, version: int, outputs: list<array{name: string, label: string|null}>, parameters: list<array{key: string, label: string|null, node_type: string, node_version: int, field: string, default: mixed}>}>
     */
    private function availableTemplates(string $assistantId): array
    {
        $templates = FlowDraft::query()
            ->where('assistant_id', $assistantId)
            ->where('kind', FlowKind::Template->value)
            ->orderBy('name')
            ->get(['flow_id', 'name']);

        $definitions = FlowDefinition::query()
            ->whereIn('flow_id', $templates->pluck('flow_id')->all())
            ->where('kind', FlowKind::Template->value)
            ->active()
            ->get()
            ->keyBy('flow_id');

        $options = [];

        foreach ($templates as $template) {
            $definition = $definitions->get($template->flow_id);

            if (! $definition instanceof FlowDefinition) {
                continue;
            }

            $options[] = [
                'id'         => $template->flow_id,
                'name'       => $template->name,
                'version'    => $definition->version,
                'outputs'    => $this->expander->outputsOf($definition),
                'parameters' => array_map(static function (array $parameter): array {
                    unset($parameter['node_id']);

                    return $parameter;
                }, $this->expander->parametersOf($definition)),
            ];
        }

        return $options;
    }
}
