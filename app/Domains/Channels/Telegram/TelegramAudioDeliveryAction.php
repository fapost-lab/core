<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Dto\SendAudioDto;
use Fapost\Foundation\Messaging\OutboundMessage;

final class TelegramAudioDeliveryAction implements TelegramDeliveryActionInterface
{
    public function supports(string $payloadType): bool
    {
        return 'audio' === $payloadType;
    }

    public function deliver(TelegramBotApiClient $client, OutboundMessage $message): array
    {
        return $client->sendAudio(
            new SendAudioDto(
                chatId: $message->chatId,
                audio: (string)($message->payload->media['audio'] ?? ''),
                caption: '' !== $message->payload->text ? $message->payload->text : null,
            )
        );
    }
}
