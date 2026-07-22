<?php

declare(strict_types=1);

namespace App\Domains\Conversation\DTO;

/**
 * Identity of a conversation thread: one thread per (tenant, assistant, contact,
 * channel). A contact writing to the same assistant through two channels owns two
 * independent threads — `channelId` is part of the thread identity, not a message
 * attribute (spec §13).
 */
final readonly class ConversationRef
{
    public function __construct(
        public string $tenantId,
        public string $assistantId,
        public string $contactId,
        public string $channelId,
        public string $platform,
    ) {
    }
}
