<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Exceptions\UnsupportedUpdateTypeException;
use FAPost\Foundation\DTO\IncomingMedia;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;
use FAPost\Foundation\Media\Enums\MediaKind;

final class TelegramInboundNormalizer
{
    /**
     * @param  array<string, mixed>  $update
     */
    public function normalize(array $update): IncomingMessage
    {
        // callback_query must take precedence: when a user presses an inline button,
        // Telegram sends both the original message and the callback_query in the same update.
        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            return $this->normalizeCallbackQuery($update);
        }

        if (isset($update['message']) && is_array($update['message'])) {
            return $this->normalizeMessage($update);
        }

        throw new UnsupportedUpdateTypeException('Unsupported Telegram update type.');
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function normalizeCallbackQuery(array $update): IncomingMessage
    {
        /** @var array<string, mixed> $callback */
        $callback = $update['callback_query'];
        /** @var array<string, mixed> $from */
        $from = $callback['from'] ?? [];
        /** @var array<string, mixed> $message */
        $message = $callback['message'] ?? [];

        return new IncomingMessage(
            updateId: (string)($update['update_id'] ?? ''),
            externalUserId: (string)($from['id'] ?? ''),
            externalChatId: (string)($message['chat']['id'] ?? ''),
            text: is_string($callback['data'] ?? null) ? $callback['data'] : null,
            type: IncomingMessageType::CallbackQuery,
            platform: 'telegram',
            payload: array_filter([
                'chat_id'    => (string)($message['chat']['id'] ?? ''),
                'message_id' => (string)($message['message_id'] ?? ''),
                'update_id'  => (string)($update['update_id'] ?? ''),
                'first_name' => ($from['first_name'] ?? null) !== null ? (string)$from['first_name'] : null,
                'last_name'  => ($from['last_name'] ?? null) !== null ? (string)$from['last_name'] : null,
                'username'   => ($from['username'] ?? null) !== null ? (string)$from['username'] : null,
            ], static fn (mixed $v): bool => null !== $v),
        );
    }

    /**
     * @param  array<string, mixed>  $update
     */
    private function normalizeMessage(array $update): IncomingMessage
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        /** @var array<string, mixed> $from */
        $from = $message['from'] ?? [];

        [$type, $media] = $this->extractMedia($message);
        $caption        = is_string($message['caption'] ?? null) ? $message['caption'] : null;
        $text           = is_string($message['text'] ?? null) ? $message['text'] : $caption;

        // Contact / location updates carry no media but have their own dedicated
        // sub-objects; they are mutually exclusive with media and with each other.
        $structured = $this->extractStructuredPayload($message);

        if (null !== $structured) {
            [$type, $structuredPayload] = $structured;
        } else {
            $structuredPayload = [];
        }

        return new IncomingMessage(
            updateId: (string)($update['update_id'] ?? ''),
            externalUserId: (string)($from['id'] ?? ''),
            externalChatId: (string)($message['chat']['id'] ?? ''),
            text: $text,
            type: $type,
            platform: 'telegram',
            payload: array_filter([
                'chat_id'        => (string)($message['chat']['id'] ?? ''),
                'message_id'     => (string)($message['message_id'] ?? ''),
                'update_id'      => (string)($update['update_id'] ?? ''),
                'first_name'     => ($from['first_name'] ?? null) !== null ? (string)$from['first_name'] : null,
                'last_name'      => ($from['last_name'] ?? null) !== null ? (string)$from['last_name'] : null,
                'username'       => ($from['username'] ?? null) !== null ? (string)$from['username'] : null,
                'media_group_id' => isset($message['media_group_id']) ? (string)$message['media_group_id'] : null,
                ...$structuredPayload,
            ], static fn (mixed $v): bool => null !== $v),
            media: $media,
        );
    }

    /**
     * Pull out non-media structured sub-objects (contact, location). Returns a
     * `[type, payloadPatch]` pair on hit and `null` otherwise — leaving the
     * caller to keep the type inferred from {@see extractMedia()}.
     *
     * @param  array<string, mixed>  $message
     *
     * @return array{0: IncomingMessageType, 1: array<string, mixed>}|null
     */
    private function extractStructuredPayload(array $message): ?array
    {
        if (isset($message['contact']) && is_array($message['contact'])) {
            $contact = $message['contact'];

            return [
                IncomingMessageType::Contact,
                ['contact' => array_filter([
                    'phone_number' => isset($contact['phone_number']) ? (string)$contact['phone_number'] : null,
                    'first_name'   => isset($contact['first_name']) ? (string)$contact['first_name'] : null,
                    'last_name'    => isset($contact['last_name']) ? (string)$contact['last_name'] : null,
                    'user_id'      => isset($contact['user_id']) ? (string)$contact['user_id'] : null,
                    'vcard'        => isset($contact['vcard']) ? (string)$contact['vcard'] : null,
                ], static fn (mixed $v): bool => null !== $v)],
            ];
        }

        if (isset($message['location']) && is_array($message['location'])) {
            $location = $message['location'];

            return [
                IncomingMessageType::Location,
                ['location' => array_filter([
                    'latitude'  => isset($location['latitude']) ? (float)$location['latitude'] : null,
                    'longitude' => isset($location['longitude']) ? (float)$location['longitude'] : null,
                    'accuracy'  => isset($location['horizontal_accuracy']) ? (float)$location['horizontal_accuracy'] : null,
                ], static fn (mixed $v): bool => null !== $v)],
            ];
        }

        return null;
    }

    /**
     * Detect media attachments on a Telegram message.
     *
     * A single Telegram message carries at most one attachment of one kind: photos arrive
     * as size variants of the same image (we pick the largest), and document/video/voice/
     * audio are mutually exclusive. Multi-media albums use `media_group_id` and arrive as
     * separate updates that must be aggregated downstream — so this normalizer always
     * returns a 0- or 1-element list, but the contract is array to stay consistent with
     * channels that deliver many files in one message.
     *
     * @param  array<string, mixed>  $message
     *
     * @return array{0: IncomingMessageType, 1: array<int, IncomingMedia>}
     */
    private function extractMedia(array $message): array
    {
        if (isset($message['photo']) && is_array($message['photo']) && [] !== $message['photo']) {
            $largest = end($message['photo']);

            if (is_array($largest) && isset($largest['file_id'])) {
                return [
                    IncomingMessageType::Photo,
                    [
                        new IncomingMedia(
                            providerFileId: (string)$largest['file_id'],
                            kind: MediaKind::Image,
                            mimeType: 'image/jpeg',
                            fileName: null,
                            size: isset($largest['file_size']) ? (int)$largest['file_size'] : null,
                        ),
                    ],
                ];
            }
        }

        $singletonMap = [
            'document' => [IncomingMessageType::Document, MediaKind::Document],
            'video'    => [IncomingMessageType::Video, MediaKind::Video],
            'voice'    => [IncomingMessageType::Voice, MediaKind::Audio],
            'audio'    => [IncomingMessageType::Audio, MediaKind::Audio],
        ];

        foreach ($singletonMap as $field => [$type, $kind]) {
            if (! isset($message[$field]) || ! is_array($message[$field])) {
                continue;
            }

            $payload = $message[$field];

            if (! isset($payload['file_id'])) {
                continue;
            }

            return [
                $type,
                [
                    new IncomingMedia(
                        providerFileId: (string)$payload['file_id'],
                        kind: $kind,
                        mimeType: is_string($payload['mime_type'] ?? null) ? $payload['mime_type'] : null,
                        fileName: is_string($payload['file_name'] ?? null) ? $payload['file_name'] : null,
                        size: isset($payload['file_size']) ? (int)$payload['file_size'] : null,
                    ),
                ],
            ];
        }

        return [IncomingMessageType::Text, []];
    }
}
