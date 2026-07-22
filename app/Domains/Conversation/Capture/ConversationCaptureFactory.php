<?php

declare(strict_types=1);

namespace App\Domains\Conversation\Capture;

use App\Domains\Conversation\DTO\MessageLogEntry;
use App\Domains\Conversation\Enums\DeliveryStatus;
use App\Domains\Conversation\Enums\MessageContentType;
use App\Domains\Conversation\Enums\MessageDirection;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Conversation\Enums\MessageSenderType;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use FAPost\Foundation\DTO\IncomingMedia;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\Messaging\DeliveryResult;
use FAPost\Foundation\Messaging\OutboundMessage;

/**
 * Builds normalized {@see MessageLogEntry} envelopes from raw pipeline inputs so
 * capture sites (jobs, sender) stay thin. Mapping logic — content-type
 * normalization, media descriptor shaping — lives here, not in the orchestrating
 * job (project rule: jobs orchestrate, services hold logic).
 */
final class ConversationCaptureFactory
{
    /**
     * Build the transcript entry for an inbound message from a contact.
     *
     * `$occurredAt` MUST come from a source stable across webhook retries (the
     * ingress receive time), not `now()` — the dedup unique index includes
     * `created_at`, so a regenerated timestamp on a retried IncomingMessageJob
     * would insert a duplicate row and double-count the aggregate.
     */
    public function forInbound(
        string $tenantId,
        string $assistantId,
        string $contactId,
        string $channelId,
        IncomingMessage $message,
        string $idempotencyKey,
        ?DateTimeImmutable $occurredAt = null,
    ): MessageLogEntry {
        return new MessageLogEntry(
            tenantId: $tenantId,
            assistantId: $assistantId,
            contactId: $contactId,
            channelId: $channelId,
            platform: $message->platform,
            direction: MessageDirection::Inbound,
            senderType: MessageSenderType::Contact,
            contentType: MessageContentType::fromInbound($message->type),
            text: $message->text,
            payload: $message->payload,
            media: $this->mapInboundMedia($message->media),
            providerMessageId: null,
            replyToProviderMessageId: null,
            origin: MessageOrigin::Flow,
            originRef: [],
            idempotencyKey: $idempotencyKey,
            status: DeliveryStatus::Received,
            occurredAt: $occurredAt ?? CarbonImmutable::now('UTC'),
        );
    }

    /**
     * Build the transcript entry for a delivered outbound message. Returns null
     * when the required capture context is missing from the message metadata
     * (e.g. internal system messages that are not part of a contact transcript) —
     * OutboundMessage carries no contact/assistant identity of its own, so the
     * builder must supply it (spec §7.2).
     */
    public function forOutbound(OutboundMessage $message, DeliveryResult $result): ?MessageLogEntry
    {
        $metadata    = $message->metadata;
        $contactId   = $this->stringOrNull($metadata['contact_id'] ?? null);
        $assistantId = $this->stringOrNull($metadata['assistant_id'] ?? null);

        if (null === $contactId || null === $assistantId) {
            return null;
        }

        $origin    = MessageOrigin::tryFrom((string) ($metadata['origin'] ?? '')) ?? MessageOrigin::Flow;
        $originRef = is_array($metadata['origin_ref'] ?? null) ? $metadata['origin_ref'] : [];
        $keyboard  = $message->payload->keyboard;

        return new MessageLogEntry(
            tenantId: $message->tenantId,
            assistantId: $assistantId,
            contactId: $contactId,
            channelId: $message->channelId,
            platform: $message->channelType,
            direction: MessageDirection::Outbound,
            senderType: MessageSenderType::Assistant,
            contentType: MessageContentType::fromOutbound($message->payload->type),
            text: $message->payload->text,
            payload: null !== $keyboard ? ['keyboard' => $keyboard] : [],
            media: [],
            providerMessageId: $result->providerMessageId,
            replyToProviderMessageId: null,
            origin: $origin,
            originRef: $originRef,
            idempotencyKey: $message->idempotencyKey,
            status: DeliveryStatus::Sent,
            occurredAt: CarbonImmutable::now('UTC'),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && '' !== $value ? $value : null;
    }

    /**
     * @param  list<IncomingMedia>  $media
     * @return list<array<string, mixed>>
     */
    private function mapInboundMedia(array $media): array
    {
        $descriptors = [];

        foreach ($media as $item) {
            $descriptors[] = [
                'provider_file_id' => $item->providerFileId,
                'kind'             => $item->kind->value,
                'mime'             => $item->mimeType,
                'file_name'        => $item->fileName,
                'size'             => $item->size,
                'status'           => 'pending',
            ];
        }

        return $descriptors;
    }
}
