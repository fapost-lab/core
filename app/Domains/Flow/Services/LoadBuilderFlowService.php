<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Flow\Contracts\FlowDefinitionRepositoryInterface;
use App\Domains\Flow\Contracts\FlowDraftRepositoryInterface;
use App\Domains\Flow\DTOs\BuilderFlowDto;

final readonly class LoadBuilderFlowService
{
    public function __construct(
        private FlowDraftRepositoryInterface $drafts,
        private FlowDefinitionRepositoryInterface $definitions,
    ) {
    }

    public function execute(string $flowId): BuilderFlowDto
    {
        $draft     = $this->drafts->findByFlowId($flowId);
        $published = $this->definitions->findLatestActiveByFlowId($flowId);

        return BuilderFlowDto::fromDraftAndDefinition($draft, $published);
    }
}
