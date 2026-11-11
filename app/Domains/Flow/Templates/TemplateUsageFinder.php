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
        $published = array_flip($this->usages->hostsOf($template->flow_id));

        $hosts = FlowDraft::query()
            ->where('tenant_id', $template->tenant_id)
            ->where('assistant_id', $template->assistant_id)
            ->where('kind', FlowKind::Flow->value)
            ->orderBy('name')
            ->get();

        return array_values($hosts
            ->filter(fn (FlowDraft $host): bool => isset($published[$host->flow_id])
                || in_array($template->flow_id, $this->expander->referencedTemplates(is_array($host->nodes) ? $host->nodes : []), true))
            ->all());
    }

    public function isUsed(FlowDraft $template): bool
    {
        return [] !== $this->hostsOf($template);
    }
}
