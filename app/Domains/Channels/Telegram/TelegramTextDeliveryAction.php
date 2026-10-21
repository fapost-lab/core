<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Dto\SendMessageDto;
use Fapost\Foundation\Messaging\OutboundMessage;

final class TelegramTextDeliveryAction implements TelegramDeliveryActionInterface
{
    public function supports(string $payloadType): bool
    {
        return in_array($payloadType, ['text', 'keyboard'], true);
    }

    public function deliver(TelegramBotApiClient $client, OutboundMessage $message): array
    {
        return $client->sendMessage(
            new SendMessageDto(
                chatId: $message->chatId,
                text: $message->payload->text,
                parseMode: isset($message->metadata['parse_mode']) ? (string)$message->metadata['parse_mode'] : null,
                replyMarkup: $this->replyMarkup($message),
            )
        );
    }

    /**
     * The neutral buttons when the message has them; otherwise the raw markup older senders still set.
     *
     * @return array<string, mixed>|null
     */
    private function replyMarkup(OutboundMessage $message): ?array
    {
        // `?? null`: a payload serialized before foundation 0.26 leaves `buttons` uninitialized after unserialize.
        $buttons = $message->payload->buttons ?? null;

        if (null !== $buttons) {
            return TelegramReplyMarkup::fromButtons($buttons);
        }

        return is_array($message->payload->keyboard) ? $message->payload->keyboard : null;
    }
}
