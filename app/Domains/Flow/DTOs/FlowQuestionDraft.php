<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use DateTimeInterface;

/**
 * What an `ask_person` node asks: the step that asks (session, node, the engine's execution key), the addressee, the
 * buttons (id and value, in the order of their indexes), until when, and the text a late press gets.
 */
final readonly class FlowQuestionDraft
{
    /**
     * @param  list<array{id: string, value: string}>  $buttons
     */
    public function __construct(
        public string $tenantId,
        public string $sessionId,
        public string $nodeId,
        public string $executionKey,
        public string $contactId,
        public ?string $assistantId,
        public array $buttons,
        public DateTimeInterface $expiresAt,
        public ?string $lateAnswerText,
    ) {
    }
}
