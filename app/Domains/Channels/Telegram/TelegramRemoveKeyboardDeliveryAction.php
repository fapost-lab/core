<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use Fapost\Foundation\Messaging\OutboundMessage;

/**
 * Removes the inline keyboard from a previously sent message via editMessageReplyMarkup.
 */
final class TelegramRemoveKeyboardDeliveryAction implements TelegramDeliveryActionInterface
{
    public function supports(string $payloadType): bool
    {
        return 'remove_keyboard' === $payloadType;
    }

    public function deliver(TelegramBotApiClient $client, OutboundMessage $message): array
    {
        $messageId = (int)($message->metadata['edit_message_id'] ?? 0);

        if (0 === $messageId) {
            return [];
        }

        return $client->editMessageReplyMarkup(
            chatId: $message->chatId,
            messageId: $messageId,
        );
    }
}
