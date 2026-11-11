<?php

declare(strict_types=1);

namespace App\Domains\Conversation\DTO;

use App\Domains\Conversation\Enums\OperatorRequestState;
use App\Domains\Conversation\Models\Conversation;

/**
 * An operator request as it is recorded on its thread: `conversations.operator_request_id` and the body in
 * `conversations.operator_request`.
 *
 * The texts for the contact are stored already rendered, in the contact's language at the time of the request: by the
 * time an operator connects or finishes, the flow session that asked may no longer exist. The body is rewritten whole,
 * always under a conditional update on the thread's handling and the request id, never by a condition on a JSON field.
 */
final readonly class OperatorRequest
{
    /**
     * @param  string       $platform          the thread's channel type
     * @param  string       $sessionId         the flow session waiting for the request (the node's session)
     * @param  string       $nodeId            the `request_operator` node waiting in that session
     * @param  string|null  $messageId         the channel's id of the "request sent" message carrying the cancel button
     * @param  string|null  $messageSessionId  the session that sent that message (it may differ after a rebind)
     * @param  string|null  $acceptedBy        the operator who accepted the request
     */
    public function __construct(
        public string $id,
        public string $conversationId,
        public string $tenantId,
        public string $assistantId,
        public string $contactId,
        public string $platform,
        public OperatorRequestState $state,
        public string $sessionId,
        public string $nodeId,
        public ?string $messageId = null,
        public ?string $messageSessionId = null,
        public ?string $connectedText = null,
        public ?string $disconnectedText = null,
        public ?string $acceptedBy = null,
    ) {
    }

    /**
     * The request recorded on the thread, or null when it has none (or an unreadable one).
     */
    public static function fromConversation(Conversation $conversation): ?self
    {
        $id     = $conversation->operator_request_id;
        $record = $conversation->operator_request;

        if (! is_string($id) || '' === $id || ! is_array($record)) {
            return null;
        }

        $state     = OperatorRequestState::tryFrom(is_string($record['state'] ?? null) ? $record['state'] : '');
        $sessionId = $record['session_id'] ?? null;
        $nodeId    = $record['node_id'] ?? null;

        if (null === $state || ! is_string($sessionId) || ! is_string($nodeId)) {
            return null;
        }

        return new self(
            id: $id,
            conversationId: (string) $conversation->getKey(),
            tenantId: (string) $conversation->tenant_id,
            assistantId: (string) $conversation->assistant_id,
            contactId: (string) $conversation->contact_id,
            platform: (string) $conversation->platform,
            state: $state,
            sessionId: $sessionId,
            nodeId: $nodeId,
            messageId: self::stringOrNull($record['message_id'] ?? null),
            messageSessionId: self::stringOrNull($record['message_session_id'] ?? null),
            connectedText: self::stringOrNull($record['connected_text'] ?? null),
            disconnectedText: self::stringOrNull($record['disconnected_text'] ?? null),
            acceptedBy: self::stringOrNull($record['accepted_by'] ?? null),
        );
    }

    /**
     * The body stored in `conversations.operator_request`.
     *
     * @return array{state: string, session_id: string, node_id: string, message_id: string|null, message_session_id: string|null, connected_text: string|null, disconnected_text: string|null, accepted_by: string|null}
     */
    public function toRecord(): array
    {
        return [
            'state'              => $this->state->value,
            'session_id'         => $this->sessionId,
            'node_id'            => $this->nodeId,
            'message_id'         => $this->messageId,
            'message_session_id' => $this->messageSessionId,
            'connected_text'     => $this->connectedText,
            'disconnected_text'  => $this->disconnectedText,
            'accepted_by'        => $this->acceptedBy,
        ];
    }

    /**
     * The body as the JSON a query-builder update writes.
     */
    public function toJson(): string
    {
        return (string) json_encode($this->toRecord(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public function withState(OperatorRequestState $state, ?string $acceptedBy = null): self
    {
        return new self(
            $this->id,
            $this->conversationId,
            $this->tenantId,
            $this->assistantId,
            $this->contactId,
            $this->platform,
            $state,
            $this->sessionId,
            $this->nodeId,
            $this->messageId,
            $this->messageSessionId,
            $this->connectedText,
            $this->disconnectedText,
            $acceptedBy ?? $this->acceptedBy,
        );
    }

    public function withMessage(string $messageId, string $messageSessionId): self
    {
        return new self(
            $this->id,
            $this->conversationId,
            $this->tenantId,
            $this->assistantId,
            $this->contactId,
            $this->platform,
            $this->state,
            $this->sessionId,
            $this->nodeId,
            $messageId,
            $messageSessionId,
            $this->connectedText,
            $this->disconnectedText,
            $this->acceptedBy,
        );
    }

    /**
     * The same request, now waited for by another session's node, with that node's texts.
     */
    public function boundTo(string $sessionId, string $nodeId, ?string $connectedText, ?string $disconnectedText): self
    {
        return new self(
            $this->id,
            $this->conversationId,
            $this->tenantId,
            $this->assistantId,
            $this->contactId,
            $this->platform,
            $this->state,
            $sessionId,
            $nodeId,
            $this->messageId,
            $this->messageSessionId,
            $connectedText,
            $disconnectedText,
            $this->acceptedBy,
        );
    }

    /**
     * Whether `$sessionId` asked for this request or sent its cancel button.
     */
    public function belongsToSession(string $sessionId): bool
    {
        return $this->sessionId === $sessionId || $this->messageSessionId === $sessionId;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }
}
