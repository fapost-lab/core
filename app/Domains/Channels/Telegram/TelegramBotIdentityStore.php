<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Telegram\Contracts\TelegramBotIdentityStoreInterface;

final class TelegramBotIdentityStore implements TelegramBotIdentityStoreInterface
{
    public function saveUsername(string $channelId, ?string $username): void
    {
        Channel::query()
            ->whereKey($channelId)
            ->update([
                'telegram_bot_username' => $username,
            ]);
    }
}
