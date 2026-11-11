<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Flow\Models\FlowDefinition;

interface FlowDefinitionRepositoryInterface
{
    /**
     * The active definition a session may be started on. Never a template's: a template runs only as a copy inside
     * the flows that place it, so every start path (default flow, trigger, command, subflow) finds nothing for it.
     */
    public function findLatestActiveByFlowId(string $flowId): ?FlowDefinition;

    /**
     * The active definition of a flow or a template, for authoring screens that show what is published.
     */
    public function findLatestPublishedByFlowId(string $flowId): ?FlowDefinition;

    public function findById(string $id): FlowDefinition;
}
