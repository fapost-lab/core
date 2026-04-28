<?php

declare(strict_types=1);

namespace App\Domains\Channels\Observers;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantContextInterface;

/**
 * Syncs Redis after channel persistence.
 *
 * When {@see Channel::$webhook_public_hash} changes, {@see updated()} skips registry writes so that
 * {@see \App\Domains\Channels\Services\ChannelService::rotateWebhookHash()} remains the only path that removes the old
 * hash and writes the new one. Any other code path that mutates {@see Channel::$webhook_public_hash} without going
 * through rotate will leak the old Redis key.
 */
final readonly class ChannelObserver
{
    /**
     * @param  ChannelWebhookRegistryInterface  $registry       Redis write-through routing registry.
     * @param  TenantContextInterface           $tenantContext  Current platform tenant context.
     */
    public function __construct(
        private ChannelWebhookRegistryInterface $registry,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * Observer hook: after channel creation, write Redis routing if the channel is active.
     */
    public function created(Channel $channel): void
    {
        if ( ! $channel->is_active) {
            return;
        }

        $this->registry->set($channel, $this->tenantContext->get());
    }

    /**
     * Observer hook: after channel update, sync Redis routing on relevant hash/active-state changes.
     */
    public function updated(Channel $channel): void
    {
        if ($channel->wasChanged('webhook_public_hash')) {
            return;
        }

        if ( ! $channel->is_active) {
            $this->registry->remove($channel->webhook_public_hash);

            return;
        }

        $this->registry->set($channel, $this->tenantContext->get());
    }

    /**
     * Observer hook: after deletion, remove the Redis routing entry.
     */
    public function deleted(Channel $channel): void
    {
        $this->registry->remove($channel->webhook_public_hash);
    }
}
