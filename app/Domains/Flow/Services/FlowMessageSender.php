<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Conversation\Enums\MessageOrigin;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Media\Contracts\MediaDispatcherInterface;
use App\Domains\Media\DTO\DispatchResult;
use App\Domains\Media\Exceptions\MediaDeletedException;
use App\Domains\Media\Exceptions\MediaNotFoundException;
use App\Domains\Media\Models\MediaFile;
use Fapost\Foundation\Flow\Enums\KeyboardMode;
use Fapost\Foundation\Media\DTO\UploadContext;
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use Fapost\Foundation\Messaging\OutboundMessage;
use RuntimeException;

final readonly class FlowMessageSender implements MessageSenderInterface
{
    public function __construct(
        private OutboundMessageSenderInterface $sender,
        private MediaDispatcherInterface $mediaDispatcher,
        private ConversationLoggerInterface $conversationLogger,
        private ConversationCaptureFactory $captureFactory,
    ) {
    }

    public function send(string $tenantId, string $contactId, string $sessionId, array $payload): string
    {
        $session = FlowSession::query()
            ->select(['id', 'assistant_id'])
            ->find($sessionId);

        if (! $session instanceof FlowSession) {
            throw new RuntimeException("Flow session '{$sessionId}' was not found.");
        }

        $channelContact = ChannelContact::query()
            ->select('channel_contacts.*')
            ->join('channels', 'channels.id', '=', 'channel_contacts.channel_id')
            ->where('channel_contacts.contact_id', $contactId)
            ->where('channels.assistant_id', $session->assistant_id)
            ->where('channels.is_active', true)
            ->with(['channel', 'contact'])
            ->orderByDesc('channel_contacts.last_interaction_at')
            ->first();

        if (
            ! $channelContact instanceof ChannelContact
            || ! $channelContact->channel instanceof Channel
            || null === $channelContact->contact
        ) {
            throw new RuntimeException("No active delivery channel found for contact '{$contactId}'.");
        }

        $channel = $channelContact->channel;
        $chatId  = (string)$channelContact->contact->external_id;

        $contentType = SendMessageContentType::from((string)$payload['content_type']);

        if ($contentType->requiresMediaUrl()) {
            $dispatch                    = $this->resolveMediaDispatch($payload, $channel, $chatId, $tenantId);
            $payload['provider_file_id'] = $dispatch->providerFileId;

            if ($dispatch->alreadyDelivered) {
                // Upload-as-send (Telegram cache miss): the media dispatcher already
                // delivered the bytes and this path never reaches MessageSender, so
                // capture the transcript here rather than lose the outbound message.
                $message = $this->buildOutboundMessage($tenantId, $contactId, $sessionId, (string)$session->assistant_id, $channel, $chatId, $payload, $contentType);
                $this->captureOutbound($message, $dispatch->deliveredMessageId);

                return $dispatch->deliveredMessageId ?? 'default';
            }
        }

        $message = $this->buildOutboundMessage($tenantId, $contactId, $sessionId, (string)$session->assistant_id, $channel, $chatId, $payload, $contentType);

        // Delivered through MessageSender, which owns the outbound transcript capture.
        $result = $this->sender->send($message);

        if (! $result->sent) {
            throw new RuntimeException($result->error ?? 'Failed to send outbound flow message.');
        }

        return $result->providerMessageId ?? 'default';
    }

    /**
     * Assemble the outbound envelope, embedding the transcript-capture context
     * (contact/assistant identity, origin) in metadata — OutboundMessage carries
     * no identity of its own, so the builder passes it down for MessageSender /
     * the already-delivered capture below (spec §7.2).
     *
     * @param  array<string, mixed>  $payload
     */
    private function buildOutboundMessage(
        string $tenantId,
        string $contactId,
        string $sessionId,
        string $assistantId,
        Channel $channel,
        string $chatId,
        array $payload,
        SendMessageContentType $contentType,
    ): OutboundMessage {
        return new OutboundMessage(
            idempotencyKey: "{$sessionId}:{$payload['node_id']}:{$payload['idempotency_key']}",
            tenantId: $tenantId,
            channelId: (string)$channel->getKey(),
            channelType: $channel->type->value,
            transportToken: $channel->token,
            chatId: $chatId,
            payload: $this->toMessagePayload($payload, $contentType),
            metadata: [
                'flow_session_id' => $sessionId,
                'parse_mode'      => 'HTML',
                'contact_id'      => $contactId,
                'assistant_id'    => $assistantId,
                'origin'          => MessageOrigin::Flow->value,
                'origin_ref'      => [
                    'flow_session_id' => $sessionId,
                    'node_id'         => $payload['node_id'] ?? null,
                ],
            ],
        );
    }

    /**
     * Record an upload-as-send outbound message in the transcript. Used only for
     * the media path that bypasses MessageSender (which owns capture for every
     * other outbound). The binding is scoped so the tenant-aware logger is rebuilt
     * per job rather than shared across them.
     */
    private function captureOutbound(OutboundMessage $message, ?string $deliveredMessageId): void
    {
        $entry = $this->captureFactory->forOutbound(
            $message,
            new DeliveryResult(sent: true, providerMessageId: $deliveredMessageId),
        );

        if (null !== $entry) {
            $this->conversationLogger->log($entry);
        }
    }

    /**
     * Resolve the media file referenced by the node payload through the dispatcher.
     *
     * For upload-as-send providers (Telegram cache miss) the dispatcher returns
     * `alreadyDelivered = true` — the bytes were shipped to the recipient as part of
     * the upload and the sender pipeline must skip its own send.
     *
     * Queries MediaFile directly with tenantId to avoid a scoped-in-singleton
     * dependency issue: FlowMessageSender is a singleton while MediaService is scoped.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveMediaDispatch(array $payload, Channel $channel, string $chatId, string $tenantId): DispatchResult
    {
        $mediaFileId = is_string($payload['media_file_id'] ?? null) ? $payload['media_file_id'] : null;

        if (null === $mediaFileId) {
            throw new RuntimeException('Media send payload missing media_file_id.');
        }

        $media = MediaFile::query()
            ->withTrashed()
            ->where('tenant_id', $tenantId)
            ->whereKey($mediaFileId)
            ->first();

        if (! $media instanceof MediaFile) {
            throw MediaNotFoundException::forId($mediaFileId);
        }

        if (null !== $media->deleted_at) {
            throw MediaDeletedException::forId($mediaFileId);
        }

        $caption = is_string($payload['caption'] ?? null) ? $payload['caption'] : null;

        return $this->mediaDispatcher->ensureUploadedToChannel(
            media: $media,
            channel: $channel,
            context: new UploadContext(targetChatId: $chatId, caption: $caption),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function toMessagePayload(array $payload, SendMessageContentType $contentType): MessagePayload
    {
        return match ($contentType) {
            SendMessageContentType::Text => new MessagePayload(
                type: 'text',
                text: (string)($payload['text'] ?? ''),
            ),
            SendMessageContentType::TextWithKeyboard => new MessagePayload(
                type: 'keyboard',
                text: (string)($payload['text'] ?? ''),
                keyboard: $this->buildKeyboard(
                    buttons: is_array($payload['buttons'] ?? null) ? $payload['buttons'] : [],
                    keyboardMode: KeyboardMode::from((string)$payload['keyboard_mode']),
                    sessionId: (string)$payload['session_id'],
                ),
            ),
            SendMessageContentType::Image => new MessagePayload(
                type: 'photo',
                text: (string)($payload['caption'] ?? ''),
                media: ['photo' => (string)$payload['provider_file_id']],
            ),
            SendMessageContentType::Document => new MessagePayload(
                type: 'document',
                text: (string)($payload['caption'] ?? ''),
                media: ['document' => (string)$payload['provider_file_id']],
            ),
            SendMessageContentType::Video => new MessagePayload(
                type: 'video',
                text: (string)($payload['caption'] ?? ''),
                media: ['video' => (string)$payload['provider_file_id']],
            ),
            SendMessageContentType::Voice => new MessagePayload(
                type: 'voice',
                text: '',
                media: ['voice' => (string)$payload['provider_file_id']],
            ),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $buttons
     *
     * @return array<string, mixed>
     */
    private function buildKeyboard(array $buttons, KeyboardMode $keyboardMode, string $sessionId): array
    {
        $rows = [];

        usort($buttons, static function (array $left, array $right): int {
            $leftRow  = (int)($left['row'] ?? 0);
            $rightRow = (int)($right['row'] ?? 0);

            if ($leftRow !== $rightRow) {
                return $leftRow <=> $rightRow;
            }

            return ((int)($left['order'] ?? 0)) <=> ((int)($right['order'] ?? 0));
        });

        foreach ($buttons as $button) {
            $rowIndex = (int)($button['row'] ?? 0);
            $rows[$rowIndex] ??= [];

            $rows[$rowIndex][] = match ($keyboardMode) {
                KeyboardMode::Inline => [
                    'text'          => (string)($button['label'] ?? ''),
                    'callback_data' => $this->encodeCallbackData($sessionId, (string)($button['id'] ?? '')),
                ],
                // Special platform-native button types (request_contact, request_location)
                // are sent via ReplyKeyboard. The 'special' field is passed through directly
                // to the Telegram API so the platform sends the native share button.
                KeyboardMode::Reply => array_filter([
                    'text'             => (string)($button['label'] ?? ''),
                    'request_contact'  => ($button['special'] ?? null) === 'request_contact' ? true : null,
                    'request_location' => ($button['special'] ?? null) === 'request_location' ? true : null,
                ], static fn (mixed $v): bool => null !== $v),
            };
        }

        ksort($rows);
        $normalizedRows = array_values($rows);

        return match ($keyboardMode) {
            KeyboardMode::Inline => ['inline_keyboard' => $normalizedRows],
            KeyboardMode::Reply  => [
                'keyboard'          => $normalizedRows,
                'one_time_keyboard' => true,
                'resize_keyboard'   => true,
            ],
        };
    }

    private function encodeCallbackData(string $sessionId, string $buttonId): string
    {
        // Telegram callback_data limit is 64 bytes.
        // Two UUIDs without dashes = 32 + 32 = 64 chars exactly.
        return str_replace('-', '', $sessionId) . str_replace('-', '', $buttonId);
    }
}
