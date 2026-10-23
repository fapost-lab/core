<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Contracts;

use App\Domains\Conversation\DTO\ConversationRef;

/**
 * Who handles a thread — read and written outside the message-append path.
 *
 * Kept apart from {@see ConversationStoreInterface} on purpose. That port is the
 * append-only message backend and is meant to move to a column store; ownership
 * is small, mutable, per-thread state that stays in the operational database
 * whatever happens to message storage.
 *
 * Every write is a conditional update on what the caller saw, so two operators
 * clicking at once (or a stale tab) never override each other silently; each
 * returns whether it changed the thread.
 *
 * The routing pipeline consults {@see isHandledByStaff()} on every inbound
 * message, so implementations must keep it cheap — it sits directly in the hot
 * path between lock acquisition and flow execution.
 */
interface ConversationOwnershipInterface
{
    /**
     * True when the flow engine must stand down: an operator holds this thread, or one was requested and nobody has
     * accepted it yet (queued). False when no thread exists yet — a contact writing for the first time is answered
     * by the bot.
     */
    public function isHandledByStaff(ConversationRef $ref): bool;

    /**
     * Accept a thread nobody holds: one the bot answers or one waiting in the queue. Conditional: a thread an
     * operator already holds stays theirs.
     *
     * @return bool whether this call took the thread over
     */
    public function takeOver(string $conversationId, string $staffUserId): bool;

    /**
     * Give a thread back to the assistant. Only the operator who holds it can: anyone else changes nothing.
     *
     * @return bool whether the thread went back to the bot
     */
    public function finish(string $conversationId, string $staffUserId): bool;

    /**
     * Hand a thread its holder has to another operator. Conditional on `$fromUserId` still holding it.
     *
     * @return bool whether the thread moved
     */
    public function transfer(string $conversationId, string $fromUserId, string $toUserId): bool;

    /**
     * Take a thread another operator holds (an administrator's escalation). Conditional on `$expectedOwnerId` still
     * being the holder the administrator saw.
     *
     * @return bool whether the thread was seized
     */
    public function seize(string $conversationId, string $expectedOwnerId, string $adminUserId): bool;

    /**
     * Clear the unread counter after an operator has read the thread.
     */
    public function markRead(string $conversationId): void;
}
