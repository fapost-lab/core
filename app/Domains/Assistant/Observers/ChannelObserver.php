<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Observers;

use App\Domains\Assistant\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Assistant\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantContextInterface;

/**
 * Syncs Redis after channel persistence.
 *
 * When {@see Channel::$webhook_public_hash} changes, {@see updated()} skips registry writes so that
 * {@see \App\Domains\Assistant\Services\ChannelService::rotateWebhookHash()} remains the only path that removes the old hash and writes the new one.
 * Any other code path that mutates {@see Channel::$webhook_public_hash} without going through rotate will leak the old Redis key.
 */
final readonly class ChannelObserver
{
    public function __construct(
        private ChannelWebhookRegistryInterface $registry,
        private TenantContextInterface $tenantContext,
    ) {
    }

    public function created(Channel $channel): void
    {
        if ( ! $channel->is_active) {
            return;
        }

        $this->registry->set($channel, $this->tenantContext->get());
    }

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

    public function deleted(Channel $channel): void
    {
        $this->registry->remove($channel->webhook_public_hash);
    }
}
