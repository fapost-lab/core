<?php

declare(strict_types=1);

namespace App\Domains\Flow\Services;

use App\Domains\Channels\Models\Channel;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Flow\Contracts\MessageSenderInterface;
use App\Domains\Flow\Enums\SendMessageContentType;
use App\Domains\Flow\Models\FlowSession;
use App\Domains\Media\Contracts\MediaDispatcherInterface;
use App\Domains\Media\DTO\DispatchResult;
use App\Domains\Media\Exceptions\MediaDeletedException;
use App\Domains\Media\Exceptions\MediaNotFoundException;
use App\Domains\Media\Models\MediaFile;
use FAPost\Foundation\Flow\Enums\KeyboardMode;
use FAPost\Foundation\Media\DTO\UploadContext;
use FAPost\Foundation\Messaging\MessagePayload;
use FAPost\Foundation\Messaging\MessageSenderInterface as OutboundMessageSenderInterface;
use FAPost\Foundation\Messaging\OutboundMessage;
use RuntimeException;

final readonly class FlowMessageSender implements MessageSenderInterface
{
    public function __construct(
        private OutboundMessageSenderInterface $sender,
        private MediaDispatcherInterface $mediaDispatcher,
    ) {
    }

    public function send(string $tenantId, string $contactId, string $sessionId, array $payload): string
    {
        $session = FlowSession::query()
            ->select(['id', 'assistant_id'])
            ->find($sessionId);

        if ( ! $session instanceof FlowSession) {
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
            $dispatch = $this->resolveMediaDispatch($payload, $channel, $chatId, $tenantId);

            if ($dispatch->alreadyDelivered) {
                return $dispatch->deliveredMessageId ?? 'default';
            }

            $payload['provider_file_id'] = $dispatch->providerFileId;
        }

        $message = new OutboundMessage(
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
            ],
        );

        $result = $this->sender->send($message);

        if ( ! $result->sent) {
            throw new RuntimeException($result->error ?? 'Failed to send outbound flow message.');
        }

        return $result->providerMessageId ?? 'default';
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

        if ( ! $media instanceof MediaFile) {
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
                KeyboardMode::Reply => [
                    'text' => (string)($button['label'] ?? ''),
                ],
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
