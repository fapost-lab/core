<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use App\Domains\Flow\Enums\FlowRunKind;

/**
 * Where a new flow session comes from, as {@see \App\Domains\Flow\Contracts\FlowEngineInterface::start()} records it:
 * the run kind, the trigger that fired it, and — for a run without a contact — the assistant it belongs to, if any.
 *
 * The assistant of a contactless run is only ever the one named here: the engine never takes the ambient current
 * assistant for it, so a run cannot be attached to whichever assistant a job happened to resolve. A run with a
 * contact keeps the current assistant, as every conversation does, and ignores {@see $assistantId}.
 */
final readonly class FlowRunOrigin
{
    public function __construct(
        public FlowRunKind $kind,
        public ?string $triggerId = null,
        public ?string $assistantId = null,
    ) {
    }

    public static function conversation(?string $triggerId = null): self
    {
        return new self(FlowRunKind::Conversation, $triggerId);
    }

    public static function automation(?string $triggerId = null, ?string $assistantId = null): self
    {
        return new self(FlowRunKind::Automation, $triggerId, $assistantId);
    }
}
