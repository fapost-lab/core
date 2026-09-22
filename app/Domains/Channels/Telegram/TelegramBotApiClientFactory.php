<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

final class TelegramBotApiClientFactory
{
    /**
     * @param  string  $apiBaseUrl  Bot API origin (`services.telegram.api_base_url`); a local stub replaces it in load tests.
     */
    public function __construct(
        private readonly string $apiBaseUrl = 'https://api.telegram.org',
    ) {
    }

    public function make(string $token): TelegramBotApiClient
    {
        return new TelegramBotApiClient($token, mb_rtrim($this->apiBaseUrl, '/'));
    }
}
