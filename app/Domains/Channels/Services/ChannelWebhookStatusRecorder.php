<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use App\Domains\Channels\Contracts\ChannelWebhookStatusRecorderInterface;
use App\Domains\Channels\Enums\ChannelWebhookStatus;
use App\Domains\Channels\Models\Channel;

/**
 * Writes the webhook registration outcome with a query on the key, as the bot identity store does: no model events,
 * so the observer does not synchronize again, and `updated_at` stays what the user's last edit made it.
 */
final readonly class ChannelWebhookStatusRecorder implements ChannelWebhookStatusRecorderInterface
{
    public function markRegistered(string $channelId): void
    {
        $this->write($channelId, ChannelWebhookStatus::Registered);
    }

    public function markFailed(string $channelId): void
    {
        $this->write($channelId, ChannelWebhookStatus::Failed);
    }

    public function clear(string $channelId): void
    {
        $this->write($channelId, null);
    }

    private function write(string $channelId, ?ChannelWebhookStatus $status): void
    {
        Channel::query()
            ->whereKey($channelId)
            ->toBase()
            ->update([
                'webhook_status'    => $status?->value,
                'webhook_status_at' => null === $status ? null : now(),
            ]);
    }
}
