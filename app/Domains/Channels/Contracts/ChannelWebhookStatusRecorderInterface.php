<?php

declare(strict_types=1);

namespace App\Domains\Channels\Contracts;

/**
 * Stores the outcome of the last provider webhook registration on the channel.
 *
 * Writes go straight to the row, outside the model's events: recording an outcome is not a change of the channel and
 * must not start another provider synchronization. Each method is a no-op when the channel no longer exists.
 */
interface ChannelWebhookStatusRecorderInterface
{
    public function markRegistered(string $channelId): void;

    public function markFailed(string $channelId): void;

    /**
     * Back to unknown: the webhook was taken down, so there is no registration to describe.
     */
    public function clear(string $channelId): void;
}
