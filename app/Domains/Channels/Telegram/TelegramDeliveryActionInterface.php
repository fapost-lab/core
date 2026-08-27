<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use Fapost\Foundation\Messaging\OutboundMessage;

interface TelegramDeliveryActionInterface
{
    public function supports(string $payloadType): bool;

    /**
     * @return array<string, mixed>
     */
    public function deliver(TelegramBotApiClient $client, OutboundMessage $message): array;
}
