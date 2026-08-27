<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Contracts;

use App\Domains\Conversation\DTO\ConversationRef;
use App\Domains\Conversation\Enums\ConversationOwner;

/**
 * Who answers a thread — read and written outside the message-append path.
 *
 * Kept apart from {@see ConversationStoreInterface} on purpose. That port is the
 * append-only message backend and is meant to move to a column store; ownership
 * is small, mutable, per-thread state that stays in the operational database
 * whatever happens to message storage.
 *
 * The routing pipeline consults {@see isHandledByStaff()} on every inbound
 * message, so implementations must keep it cheap — it sits directly in the hot
 * path between lock acquisition and flow execution.
 */
interface ConversationOwnershipInterface
{
    /**
     * True when an operator holds this thread and the flow engine must stand
     * down. False when no thread exists yet — a contact writing for the first
     * time is answered by the bot.
     */
    public function isHandledByStaff(ConversationRef $ref): bool;

    /**
     * Hand the thread to an operator, or give it back to the bot.
     *
     * @param  string|null  $staffUserId  Owning operator; null when returning to the bot.
     */
    public function assign(string $conversationId, ConversationOwner $owner, ?string $staffUserId = null): void;

    /**
     * Clear the unread counter after an operator has read the thread.
     */
    public function markRead(string $conversationId): void;
}
