<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Observers;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Illuminate\Support\Facades\DB;

/**
 * Executes provider-side webhook synchronization after channel lifecycle events.
 *
 * Network calls are deferred until after commit via {@see DB::afterCommit()} so that provider
 * registration sees committed channel state, but still runs within the current request.
 */
final readonly class ChannelObserver
{
    public function __construct(
        private ChannelRegistryInterface $channelRegistry,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * Run provider webhook synchronization after channel persistence.
     *
     * Registers newly created active channels, deregisters on deactivation, registers on
     * reactivation, and refreshes provider state when transport-relevant fields change.
     * Ignores no-op saves and unrelated updates.
     */
    public function saved(Channel $channel): void
    {
        $transportFields = ['token', 'secret_token', 'config', 'webhook_public_hash'];

        if ($channel->wasRecentlyCreated) {
            if ( ! $channel->is_active) {
                return;
            }

            $this->dispatch($channel, register: true);

            return;
        }

        if ($channel->wasChanged('is_active')) {
            $this->dispatch($channel, register: $channel->is_active);

            return;
        }

        if ( ! $channel->is_active) {
            return;
        }

        if ( ! $channel->wasChanged($transportFields)) {
            return;
        }

        $this->dispatch($channel, register: true);
    }

    /**
     * Run provider webhook removal when a channel is deleted.
     */
    public function deleted(Channel $channel): void
    {
        $this->dispatch($channel, register: false);
    }

    /**
     * Dispatch a sync webhook job after commit when the channel integration exposes a registrar.
     */
    private function dispatch(Channel $channel, bool $register): void
    {
        if (null === $this->channelRegistry->webhookRegistrar($channel->type)) {
            return;
        }

        $tenant = $this->tenantContext->get();

        DB::afterCommit(static function () use ($channel, $register, $tenant): void {
            SyncChannelWebhookJob::dispatchSync(
                tenantId: $tenant->getId(),
                schema: $tenant->getSchemaName(),
                channelId: (string)$channel->getKey(),
                channelType: $channel->type->value,
                webhookPublicHash: $channel->webhook_public_hash,
                token: $channel->token,
                secretToken: $channel->secret_token,
                config: is_array($channel->config) ? $channel->config : [],
                register: $register,
            );
        });
    }
}
