<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

final class TelegramBotApiClientFactory
{
    public function make(string $token): TelegramBotApiClient
    {
        return new TelegramBotApiClient($token);
    }
}
