<?php

declare(strict_types=1);

namespace App\Domains\Conversation\DTO;

/**
 * What a flow's `request_operator` node asks for: whose thread, which session and node wait, who is told and how, and
 * the texts the contact gets when an operator connects and when they finish (already rendered in the contact's
 * language).
 */
final readonly class OperatorRequestDraft
{
    /**
     * @param  array{target: string, role: string|null, user_ids: list<string>}  $recipients  the node's staff
     *                                                                                       selection, as `notify` has it
     * @param  bool         $byEmail       also send the request by email (the bell always gets it)
     * @param  string|null  $operatorNote  the node's text for the operators; null gives the standard text
     */
    public function __construct(
        public string $tenantId,
        public string $assistantId,
        public string $contactId,
        public string $sessionId,
        public string $nodeId,
        public array $recipients,
        public bool $byEmail,
        public ?string $operatorNote,
        public ?string $connectedText,
        public ?string $disconnectedText,
    ) {
    }
}
