<?php

declare(strict_types=1);

namespace App\Domains\Channels\Telegram;

use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Telegram\Contracts\TelegramBotIdentityStoreInterface;

/**
 * Keeps the bot name Telegram reports in the channel's `config` under `bot_username`, a managed setting of
 * {@see TelegramChannelProfile}.
 */
final class TelegramBotIdentityStore implements TelegramBotIdentityStoreInterface
{
    public function saveUsername(string $channelId, ?string $username): void
    {
        $connection = Channel::query()->getConnection();

        // Read and written under a row lock, so two writers of this name do not interleave. A form save does not take
        // the lock: one racing with this write may drop the name, which the re-registration it triggers restores. The
        // write goes through the query builder, past the model's events: the provider is not synchronized again
        // because the bot told its name.
        $connection->transaction(static function () use ($channelId, $username): void {
            $channel = Channel::query()->whereKey($channelId)->lockForUpdate()->first();

            if (! $channel instanceof Channel) {
                return;
            }

            $config                 = is_array($channel->config) ? $channel->config : [];
            $config['bot_username'] = $username;

            Channel::query()->whereKey($channelId)->update(['config' => json_encode($config, JSON_THROW_ON_ERROR)]);
        });
    }
}
