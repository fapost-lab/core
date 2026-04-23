<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Dto\SendVideoDto;
use FAPost\Foundation\Messaging\OutboundMessage;

final class TelegramVideoDeliveryAction implements TelegramDeliveryActionInterface
{
    public function supports(string $payloadType): bool
    {
        return 'video' === $payloadType;
    }

    public function deliver(TelegramBotApiClient $client, OutboundMessage $message): array
    {
        return $client->sendVideo(new SendVideoDto(
            chatId: $message->chatId,
            video: (string) ($message->payload->media['video'] ?? ''),
            caption: '' !== $message->payload->text ? $message->payload->text : null,
        ));
    }
}
