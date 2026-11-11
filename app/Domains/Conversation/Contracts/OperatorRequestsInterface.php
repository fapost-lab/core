<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Contracts;

use App\Domains\Conversation\DTO\OperatorRequest;
use App\Domains\Conversation\DTO\OperatorRequestDraft;
use App\Domains\Conversation\DTO\OperatorRequestPlacement;

/**
 * Operator requests on threads — what a flow's `request_operator` node asks for, kept beside
 * {@see ConversationOwnershipInterface}.
 *
 * A request is recorded on its thread (`operator_request_id` + `operator_request`), so it outlives the flow session
 * that placed it. Every change is a conditional update on the thread's handling and the request id, under a lock on
 * the thread's row so the body is never rewritten from a stale read. Accepting and finishing are part of
 * {@see ConversationOwnershipInterface::takeOver()} and {@see ConversationOwnershipInterface::finish()}. Each change
 * out of `pending` or `accepted` is announced as
 * {@see \App\Domains\Conversation\Events\OperatorRequestStateChanged} after it is committed.
 */
interface OperatorRequestsInterface
{
    /**
     * Puts the contact's thread at the assistant in the queue with a new request, or binds the asking session to the
     * request or operator the thread already has. Creates the thread when there is none yet. Queues the notification
     * of the operators when the result is {@see \App\Domains\Conversation\Enums\OperatorRequestPlacementOutcome::Placed}.
     */
    public function place(OperatorRequestDraft $draft): OperatorRequestPlacement;

    /**
     * Records the "request sent" message that carries the cancel button.
     *
     * @return bool false when the request stopped waiting in the meantime: nobody will remove that button but the
     *              caller
     */
    public function attachMessage(string $requestId, string $messageId, string $messageSessionId): bool;

    /**
     * The request, wherever it is recorded; null when no thread holds it any more.
     */
    public function find(string $requestId): ?OperatorRequest;

    /**
     * The contact cancelled a pending request: the thread goes back to the bot.
     *
     * @return bool whether this call cancelled it (false: accepted, timed out or cancelled already)
     */
    public function cancel(string $requestId): bool;

    /**
     * Cancels the pending request that `$sessionId` asked for or sent the cancel button of, on any of the contact's
     * threads at the assistant: the contact pressed that button, or a command ended that session.
     *
     * @return bool whether a request was cancelled
     */
    public function cancelForSession(string $tenantId, string $assistantId, string $contactId, string $sessionId): bool;

    /**
     * Nobody accepted a pending request in time: the thread goes back to the bot.
     *
     * @return bool whether this call expired it (false: accepted or cancelled first)
     */
    public function expire(string $requestId): bool;
}
