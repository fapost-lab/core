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
use Fapost\Foundation\DTO\IncomingMedia;
use Fapost\Foundation\DTO\IncomingMessage;
use Fapost\Foundation\Messaging\Button;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\OutboundMessage;

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
        $contentType = MessageContentType::fromInbound($message->type);
        $text        = $message->text;
        $payload     = $message->payload;

        // A button press carries the callback data as its text, which is routing data rather than something the
        // contact said. The transcript shows the button's title instead and keeps the data in the payload.
        if (MessageContentType::Callback === $contentType) {
            $label = $payload['button_label'] ?? null;

            if (null !== $text) {
                $payload['callback_data'] = $text;
            }

            $text = is_string($label) && '' !== $label ? $label : null;
            unset($payload['button_label']);
        }

        return new MessageLogEntry(
            tenantId: $tenantId,
            assistantId: $assistantId,
            contactId: $contactId,
            channelId: $channelId,
            platform: $message->platform,
            direction: MessageDirection::Inbound,
            senderType: MessageSenderType::Contact,
            contentType: $contentType,
            text: $text,
            payload: $payload,
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
        $payload   = $this->outboundPayload($message);

        // An operator reply is still an outbound message on the same funnel, but
        // the transcript must attribute it to the person, not to the assistant.
        // Derived from origin rather than a separate metadata key so senders
        // cannot claim staff authorship without also declaring a staff origin.
        $isStaffReply = MessageOrigin::Staff === $origin;
        $staffUserId  = $isStaffReply ? $this->stringOrNull($originRef['staff_user_id'] ?? null) : null;

        return new MessageLogEntry(
            tenantId: $message->tenantId,
            assistantId: $assistantId,
            contactId: $contactId,
            channelId: $message->channelId,
            platform: $message->channelType,
            direction: MessageDirection::Outbound,
            senderType: $isStaffReply ? MessageSenderType::Staff : MessageSenderType::Assistant,
            contentType: MessageContentType::fromOutbound($message->payload->type),
            text: $message->payload->text,
            payload: $payload,
            media: [],
            providerMessageId: $result->providerMessageId,
            replyToProviderMessageId: null,
            origin: $origin,
            originRef: $originRef,
            idempotencyKey: $message->idempotencyKey,
            status: DeliveryStatus::Sent,
            occurredAt: CarbonImmutable::now('UTC'),
            senderStaffUserId: $staffUserId,
        );
    }

    /**
     * The transcript payload of an outbound message: its buttons as labels with their action (the callback payload
     * is an internal token and stays out), or, for a message that still carries raw provider markup, that markup.
     *
     * @return array<string, mixed>
     */
    private function outboundPayload(OutboundMessage $message): array
    {
        // `?? null`: a payload serialized before foundation 0.26 leaves `buttons` uninitialized after unserialize.
        $buttons = $message->payload->buttons ?? null;

        if (null !== $buttons) {
            return ['buttons' => array_map(
                static fn (array $row): array => array_map(
                    static fn (Button $button): array => ['label' => $button->label, 'action' => $button->action->value],
                    $row,
                ),
                $buttons->rows,
            )];
        }

        $keyboard = $message->payload->keyboard;

        return null !== $keyboard ? ['keyboard' => $keyboard] : [];
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
