<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Contact\Models\Contact;
use App\Domains\Flow\Models\FlowDefinition;

/**
 * What {@see ContactFlowStarter::start()} starts: the contact and assistant, the flow version and its initial state,
 * the chain depth and once-only start key the conversation records, and the rules of the busy check.
 */
final readonly class ContactFlowStartRequest
{
    /**
     * @param  string                $guardTenantId      the tenant of the contact's session lock
     * @param  array<string, mixed>  $initialState
     * @param  int                   $busyWindowMinutes  the busy window of {@see ContactFlowStartCollision}; 0 = never busy by time
     * @param  string|null           $askingSessionId    the session whose node asks for the start, or that emitted the event
     */
    public function __construct(
        public string $guardTenantId,
        public Contact $contact,
        public string $assistantId,
        public FlowDefinition $definition,
        public array $initialState,
        public int $chainDepth,
        public string $startKey,
        public int $busyWindowMinutes,
        public ?string $askingSessionId,
    ) {
    }
}
