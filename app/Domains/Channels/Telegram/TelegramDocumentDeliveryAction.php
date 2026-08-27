<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Telegram\Dto\SendDocumentDto;
use Fapost\Foundation\Messaging\OutboundMessage;

final class TelegramDocumentDeliveryAction implements TelegramDeliveryActionInterface
{
    public function supports(string $payloadType): bool
    {
        return 'document' === $payloadType;
    }

    public function deliver(TelegramBotApiClient $client, OutboundMessage $message): array
    {
        return $client->sendDocument(
            new SendDocumentDto(
                chatId: $message->chatId,
                document: (string)($message->payload->media['document'] ?? ''),
                caption: '' !== $message->payload->text ? $message->payload->text : null,
            )
        );
    }
}
