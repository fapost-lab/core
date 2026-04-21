<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Dto\SendPhotoDto;
use FAPost\Foundation\Messaging\OutboundMessage;

final class TelegramPhotoDeliveryAction implements TelegramDeliveryActionInterface
{
    public function supports(string $payloadType): bool
    {
        return 'photo' === $payloadType;
    }

    public function deliver(TelegramBotApiClient $client, OutboundMessage $message): array
    {
        return $client->sendPhoto(new SendPhotoDto(
            chatId: $message->chatId,
            photo: (string) ($message->payload->media['photo'] ?? ''),
            caption: '' !== $message->payload->text ? $message->payload->text : null,
        ));
    }
}
