<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Observers;

use App\Domains\Assistant\Models\Channel;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Jobs\Messaging\SyncChannelWebhookJob;

/**
 * Schedules provider-side webhook synchronization after channel lifecycle events.
 *
 * Network calls are intentionally deferred to {@see SyncChannelWebhookJob} so that channel
 * persistence does not depend on external provider availability inside model observers.
 */
final readonly class ChannelObserver
{
    public function __construct(
        private ChannelRegistryInterface $channelRegistry,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * Queue provider webhook registration for newly created active channels when supported.
     */
    public function created(Channel $channel): void
    {
        if ( ! $channel->is_active) {
            return;
        }

        $this->dispatch($channel, register: true);
    }

    /**
     * Queue provider webhook refresh only when transport-relevant fields change.
     *
     * Fires a deregister when the channel is deactivated, a register when relevant credentials
     * or config change. Skips unrelated updates (e.g. name changes) to avoid redundant
     * provider-side calls.
     */
    public function updated(Channel $channel): void
    {
        $transportFields = ['token', 'secret_token', 'config', 'webhook_public_hash'];

        if ($channel->wasChanged('is_active') && ! $channel->is_active) {
            $this->dispatch($channel, register: false);

            return;
        }

        if ( ! $channel->wasChanged($transportFields)) {
            return;
        }

        if ( ! $channel->is_active) {
            return;
        }

        $this->dispatch($channel, register: true);
    }

    /**
     * Queue provider webhook removal when a channel is deleted.
     */
    public function deleted(Channel $channel): void
    {
        $this->dispatch($channel, register: false);
    }

    /**
     * Dispatch an async webhook sync job when the channel integration exposes a registrar.
     */
    private function dispatch(Channel $channel, bool $register): void
    {
        if (null === $this->channelRegistry->webhookRegistrar($channel->type)) {
            return;
        }

        $tenant = $this->tenantContext->get();

        SyncChannelWebhookJob::dispatch(
            tenantId: $tenant->getId(),
            schema: $tenant->getSchemaName(),
            channelType: $channel->type->value,
            webhookPublicHash: $channel->webhook_public_hash,
            token: (string) $channel->token,
            secretToken: (string) $channel->secret_token,
            config: is_array($channel->config) ? $channel->config : [],
            register: $register,
        );
    }
}
