<?php

declare(strict_types=1);

namespace App\Domains\Flow\Templates;

use App\Domains\Flow\DTOs\FlowValidationErrorDto;

/**
 * The result of {@see TemplateExpander::expand()}: the expanded graph, the templates it now carries a copy of, and
 * what stopped a reference from expanding. A graph with errors must not be published.
 */
final readonly class TemplateExpansion
{
    /**
     * @param  array<int, mixed>             $nodes
     * @param  array<int, mixed>             $edges
     * @param  list<string>                  $templateFlowIds
     * @param  list<FlowValidationErrorDto>  $errors
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public array $templateFlowIds,
        public array $errors,
    ) {
    }
}
