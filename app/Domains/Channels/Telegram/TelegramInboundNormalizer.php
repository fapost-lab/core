<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Exceptions\UnsupportedUpdateTypeException;
use FAPost\Foundation\DTO\IncomingMessage;
use FAPost\Foundation\DTO\IncomingMessageType;

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
    private function normalizeMessage(array $update): IncomingMessage
    {
        /** @var array<string, mixed> $message */
        $message = $update['message'];
        /** @var array<string, mixed> $from */
        $from = $message['from'] ?? [];

        return new IncomingMessage(
            updateId: (string) ($update['update_id'] ?? ''),
            externalUserId: (string) ($from['id'] ?? ''),
            externalChatId: (string) ($message['chat']['id'] ?? ''),
            text: is_string($message['text'] ?? null) ? $message['text'] : null,
            type: IncomingMessageType::Text,
            platform: 'telegram',
            payload: [
                'chat_id'    => (string) ($message['chat']['id'] ?? ''),
                'message_id' => (string) ($message['message_id'] ?? ''),
                'update_id'  => (string) ($update['update_id'] ?? ''),
            ],
        );
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
            updateId: (string) ($update['update_id'] ?? ''),
            externalUserId: (string) ($from['id'] ?? ''),
            externalChatId: (string) ($message['chat']['id'] ?? ''),
            text: is_string($callback['data'] ?? null) ? $callback['data'] : null,
            type: IncomingMessageType::CallbackQuery,
            platform: 'telegram',
            payload: [
                'chat_id'    => (string) ($message['chat']['id'] ?? ''),
                'message_id' => (string) ($message['message_id'] ?? ''),
                'update_id'  => (string) ($update['update_id'] ?? ''),
            ],
        );
    }
}
