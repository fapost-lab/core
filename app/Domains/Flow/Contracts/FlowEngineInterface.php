<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowDefinition;
use App\Domains\Flow\Models\FlowSession;
use FAPost\Foundation\DTO\IncomingMessage;

interface FlowEngineInterface
{
    /**
     * @param  array<string, mixed>  $initialState  Merged into session state after system bootstrap keys are set
     */
    public function start(
        FlowDefinition $definition,
        Contact $contact,
        array $initialState = [],
    ): FlowSession;

    /**
     * Loads the frozen graph via {@see FlowSession::$flow_definition_id} → {@see FlowDefinition::$id}
     * (version snapshot row). Runtime navigation uses {@see FlowSession::$current_node_id} only.
     */
    public function resume(
        FlowSession $session,
        IncomingMessage $message,
    ): FlowSession;
}
