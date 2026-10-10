<?php

declare(strict_types=1);

namespace App\Domains\Channels\Observers;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\ChannelWebhookSyncOutcome;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Single owner of channel lifecycle side effects: Redis routing registry sync and
 * provider-side webhook (de)registration.
 *
 * Both effects are deferred via {@see DB::afterCommit()} so they only observe
 * committed channel state, and they run in a deterministic order: the Redis
 * routing entry is written/removed first (created/updated/deleted fire before
 * saved), then the provider sync job is dispatched. A rollback therefore leaves
 * neither a stale Redis entry nor a provider registration pointing at a
 * non-existent channel.
 *
 * When {@see Channel::$webhook_public_hash} changes, {@see updated()} skips registry writes so that
 * {@see \App\Domains\Channels\Services\ChannelService::rotateWebhookHash()} remains the only path that removes the old
 * hash and writes the new one. Any other code path that mutates {@see Channel::$webhook_public_hash} without going
 * through rotate will leak the old Redis key. Provider sync still runs on rotation
 * (the hash is a transport field) so the provider points at the new webhook URL.
 *
 * The provider is called synchronously, and its refusal never fails the write: the job has already stored a register
 * outcome on the channel, and the refusal is reported and noted in {@see ChannelWebhookSyncOutcome}. An exception
 * escaping an afterCommit callback would abort the callbacks queued behind it (the other channels of a deactivated
 * assistant) and a cascade (deleting an assistant) halfway.
 */
final readonly class ChannelObserver
{
    /**
     * @param  ChannelWebhookRegistryInterface  $registry         Redis write-through routing registry.
     * @param  ChannelRegistryInterface         $channelRegistry  Channel integration registry (provider adapters).
     * @param  TenantContextInterface           $tenantContext    Current platform tenant context.
     * @param  Container                        $container        Resolves the scoped sync outcome when a refusal is recorded; an observer outlives every job.
     */
    public function __construct(
        private ChannelWebhookRegistryInterface $registry,
        private ChannelRegistryInterface $channelRegistry,
        private TenantContextInterface $tenantContext,
        private Container $container,
    ) {
    }

    /**
     * Observer hook: after channel creation, write the Redis routing entry for active channels.
     */
    public function created(Channel $channel): void
    {
        if (! $channel->is_active) {
            return;
        }

        $tenant = $this->tenantContext->get();

        DB::afterCommit(function () use ($channel, $tenant): void {
            $this->registry->set($channel, $tenant);
        });
    }

    /**
     * Observer hook: after channel update, sync the Redis routing entry on relevant hash/active-state changes.
     */
    public function updated(Channel $channel): void
    {
        if ($channel->wasChanged('webhook_public_hash')) {
            return;
        }

        if (! $channel->is_active) {
            $hash = $channel->webhook_public_hash;

            DB::afterCommit(function () use ($hash): void {
                $this->registry->remove($hash);
            });

            return;
        }

        $tenant = $this->tenantContext->get();

        DB::afterCommit(function () use ($channel, $tenant): void {
            $this->registry->set($channel, $tenant);
        });
    }

    /**
     * Observer hook: run provider webhook synchronization after channel persistence.
     *
     * Registers newly created active channels, deregisters on deactivation, registers on
     * reactivation, and refreshes provider state when transport-relevant fields change.
     * Ignores no-op saves and unrelated updates. Fires after {@see created()}/{@see updated()}
     * so the afterCommit callback queue keeps the registry-before-provider order.
     */
    public function saved(Channel $channel): void
    {
        $transportFields = ['token', 'secret_token', 'config', 'webhook_public_hash'];

        if ($channel->wasRecentlyCreated) {
            if (! $channel->is_active) {
                return;
            }

            $this->dispatchProviderSync($channel, register: true);

            return;
        }

        if ($channel->wasChanged('is_active')) {
            $this->dispatchProviderSync($channel, register: $channel->is_active);

            return;
        }

        if (! $channel->is_active) {
            return;
        }

        if (! $channel->wasChanged($transportFields)) {
            return;
        }

        $this->dispatchProviderSync($channel, register: true);
    }

    /**
     * Observer hook: after deletion, remove the Redis routing entry, then deregister at the provider.
     */
    public function deleted(Channel $channel): void
    {
        $hash = $channel->webhook_public_hash;

        DB::afterCommit(function () use ($hash): void {
            $this->registry->remove($hash);
        });

        $this->dispatchProviderSync($channel, register: false);
    }

    /**
     * Dispatch a provider sync job after commit when the channel integration exposes a registrar.
     */
    private function dispatchProviderSync(Channel $channel, bool $register): void
    {
        if (null === $this->channelRegistry->webhookRegistrar($channel->type)) {
            return;
        }

        $tenant = $this->tenantContext->get();

        // Only a deregister (delete or deactivation) carries the credentials, a delete's row being gone by the time the job runs. A register job
        // loads them from the channel, so they never sit in a payload that a failed job would record.
        DB::afterCommit(function () use ($channel, $register, $tenant): void {
            try {
                SyncChannelWebhookJob::dispatchSync(
                    tenantId: $tenant->getId(),
                    schema: $tenant->getSchemaName(),
                    channelId: (string)$channel->getKey(),
                    channelType: $channel->type->value,
                    webhookPublicHash: $channel->webhook_public_hash,
                    token: $register ? null : $channel->token,
                    secretToken: $register ? null : $channel->secret_token,
                    config: is_array($channel->config) ? $channel->config : [],
                    register: $register,
                );
            } catch (Throwable $exception) {
                report($exception);

                $this->container->make(ChannelWebhookSyncOutcome::class)->recordFailure((string)$channel->getKey(), $register);
            }
        });
    }
}
