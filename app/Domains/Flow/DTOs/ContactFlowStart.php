<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

/**
 * A start of a flow as a contact's conversation, as a `start_flow` node queues it: which flow, for whom, with which
 * starting variables, under which start key (unique: the start happens once), at which event chain depth, with the
 * node's busy window, and the session that asked.
 */
final readonly class ContactFlowStart
{
    /**
     * @param  array<string, mixed>  $input  the started conversation's `flow.*` variables
     */
    public function __construct(
        public string $tenantId,
        public string $flowId,
        public string $contactId,
        public array $input,
        public string $startKey,
        public int $chainDepth,
        public int $busyWindowMinutes,
        public ?string $askingSessionId,
    ) {
    }
}
