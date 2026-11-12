<?php

declare(strict_types=1);

namespace App\Domains\Flow\Templates;

use App\Domains\Flow\Enums\FlowKind;
use App\Domains\Flow\Models\FlowDraft;

/**
 * Which flows use a template: those whose active definition carries a copy of it (the usage index) and those whose
 * draft places it but has not been published since. The second half matters for deleting: the index alone would let
 * a template go that a draft's next publish still needs.
 */
final readonly class TemplateUsageFinder
{
    public function __construct(
        private TemplateUsageRepository $usages,
        private TemplateExpander $expander,
    ) {
    }

    /**
     * The flows of the template's assistant that use it, ordered by name.
     *
     * @return list<FlowDraft>
     */
    public function hostsOf(FlowDraft $template): array
    {
        return $this->hostsByTemplate(
            (string) $template->tenant_id,
            (string) $template->assistant_id,
            [$template->flow_id],
        )[$template->flow_id] ?? [];
    }

    /**
     * How many flows use each template, for a page of templates: two queries whatever the number of templates.
     *
     * @param  list<string>  $templateFlowIds
     * @return array<string, int>  by template flow id, 0 for an unused template
     */
    public function usageCounts(string $tenantId, string $assistantId, array $templateFlowIds): array
    {
        $hosts = $this->hostsByTemplate($tenantId, $assistantId, $templateFlowIds, ['id', 'flow_id', 'nodes']);

        return array_combine(
            $templateFlowIds,
            array_map(static fn (string $id): int => count($hosts[$id] ?? []), $templateFlowIds),
        );
    }

    public function isUsed(FlowDraft $template): bool
    {
        return [] !== $this->hostsOf($template);
    }

    /**
     * The rule in one place: a flow uses a template when its active definition carries a copy (the usage index) or
     * its draft places it.
     *
     * @param  list<string>  $templateFlowIds
     * @param  list<string>  $columns
     * @return array<string, list<FlowDraft>>  hosts ordered by name, by template flow id
     */
    private function hostsByTemplate(string $tenantId, string $assistantId, array $templateFlowIds, array $columns = ['*']): array
    {
        if ([] === $templateFlowIds) {
            return [];
        }

        $published = [];

        foreach ($this->usages->hostsOfMany($templateFlowIds) as $templateFlowId => $hostFlowIds) {
            $published[$templateFlowId] = array_flip($hostFlowIds);
        }

        $drafts = FlowDraft::query()
            ->where('tenant_id', $tenantId)
            ->where('assistant_id', $assistantId)
            ->where('kind', FlowKind::Flow->value)
            ->orderBy('name')
            ->get($columns);

        $wanted = array_flip($templateFlowIds);
        $result = [];

        foreach ($drafts as $host) {
            $placed = array_flip($this->expander->referencedTemplates(is_array($host->nodes) ? $host->nodes : []));

            foreach ($templateFlowIds as $templateFlowId) {
                if (isset($published[$templateFlowId][$host->flow_id]) || isset($placed[$templateFlowId])) {
                    $result[$templateFlowId][] = $host;
                }
            }
        }

        return array_intersect_key($result, $wanted);
    }
}
