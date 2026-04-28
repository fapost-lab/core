<?php

declare(strict_types=1);

namespace App\Domains\Channels\Contracts;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Models\Channel;

interface ChannelServiceInterface
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(Assistant $assistant, array $data): Channel;

    /**
     * @param  array<string, mixed>  $data
     *
     * When the persisted channel ends with {@see Channel::$is_active} {@code true}, webhook routing is refreshed for
     * that channel via the persistence observer (same outcome as {@see reactivate} for an inactive channel).
     */
    public function update(Channel $channel, array $data): Channel;

    /**
     * Rotate the public webhook hash and update the Redis routing cache.
     */
    public function rotateWebhookHash(Channel $channel): Channel;

    /**
     * Deactivate a channel (sets {@see Channel::$is_active} to false).
     */
    public function deactivate(Channel $channel): void;

    /**
     * Sets {@see Channel::$is_active} to {@code true} and saves; Redis is repopulated for active channels by the
     * channel observer.
     */
    public function reactivate(Channel $channel): void;
}
