<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram\Contracts;

interface TelegramBotIdentityStoreInterface
{
    public function saveUsername(string $channelId, ?string $username): void;
}
