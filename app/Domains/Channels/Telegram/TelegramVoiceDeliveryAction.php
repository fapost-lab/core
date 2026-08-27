<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Dto\SendVoiceDto;
use FAPost\Foundation\Messaging\OutboundMessage;

final class TelegramVoiceDeliveryAction implements TelegramDeliveryActionInterface
{
    public function supports(string $payloadType): bool
    {
        return 'voice' === $payloadType;
    }

    public function deliver(TelegramBotApiClient $client, OutboundMessage $message): array
    {
        return $client->sendVoice(
            new SendVoiceDto(
                chatId: $message->chatId,
                voice: (string)($message->payload->media['voice'] ?? ''),
                caption: '' !== $message->payload->text ? $message->payload->text : null,
            )
        );
    }
}
