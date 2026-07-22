<?php

declare(strict_types=1);

namespace App\Domains\Conversation\DTO;

use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Conversation\Enums\MessageContentType;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Enums\MessageSenderType;
use DateTimeImmutable;

/**
 * Normalized envelope for a single transcript message — the one shape every
 * capture site emits into {@see \App\Domains\Conversation\Contracts\ConversationLoggerInterface}.
 *
 * Storage-agnostic on purpose: it carries everything a driver (Postgres today,
 * ClickHouse later) needs to persist a row without reaching back into the
 * originating domain. Core-internal DTO — not a Foundation extension contract.
 */
final readonly class MessageLogEntry
{
    /**
     * @param  array<string, mixed>  $payload   Keyboards, callback {value, button_id}, location, contact card, captions.
     * @param  list<array<string, mixed>>  $media  Media descriptors: {media_file_id?, kind, mime?, file_name?, size?, provider_file_id?, status}.
     * @param  array<string, mixed>  $originRef  {flow_session_id, node_id} | {broadcast_id} | {staff_user_id}.
     */
    public function __construct(
        public string $tenantId,
        public string $assistantId,
        public string $contactId,
        public string $channelId,
        public string $platform,
        public MessageDirection $direction,
        public MessageSenderType $senderType,
        public MessageContentType $contentType,
        public ?string $text,
        public array $payload,
        public array $media,
        public ?string $providerMessageId,
        public ?string $replyToProviderMessageId,
        public MessageOrigin $origin,
        public array $originRef,
        public ?string $idempotencyKey,
        public DeliveryStatus $status,
        public DateTimeImmutable $occurredAt,
        public ?string $senderStaffUserId = null,
    ) {
    }

    public function ref(): ConversationRef
    {
        return new ConversationRef(
            tenantId: $this->tenantId,
            assistantId: $this->assistantId,
            contactId: $this->contactId,
            channelId: $this->channelId,
            platform: $this->platform,
        );
    }
}
