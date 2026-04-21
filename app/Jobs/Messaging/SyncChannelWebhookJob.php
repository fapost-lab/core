<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use FAPost\Foundation\Channel\WebhookRegistrationPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Synchronizes provider-side webhooks outside of model persistence hooks.
 *
 * The job carries a snapshot of the transport credentials needed by the provider so that
 * deletes and post-commit updates do not depend on reloading a mutable database row.
 */
final class SyncChannelWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string  $tenantId           Tenant identifier for context restoration.
     * @param  string  $schema             Tenant schema name for context restoration.
     * @param  string  $channelType        Published channel identifier.
     * @param  string  $webhookPublicHash  Public routing hash used in inbound webhook URLs.
     * @param  string  $token              Provider transport token (decrypted at dispatch time).
     * @param  string  $secretToken        Provider-side webhook signature secret (decrypted at dispatch time).
     * @param  array<string, mixed>  $config  Transport-specific channel configuration snapshot.
     * @param  bool  $register  True to create/update the provider webhook, false to delete it.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $schema,
        public readonly string $channelId,
        public readonly string $channelType,
        public readonly string $webhookPublicHash,
        public readonly string $token,
        public readonly string $secretToken,
        public readonly array $config,
        public readonly bool $register,
    ) {
        $this->onQueue('messaging.system');
        $this->afterCommit();
    }

    /**
     * Resolve the channel registrar and apply the requested provider webhook action.
     */
    public function handle(ChannelRegistryInterface $channelRegistry, TenantSwitcher $switcher): void
    {
        $tenant = new RuntimeTenant(id: $this->tenantId, schemaName: $this->schema);

        $switcher->runForTenant($tenant, function () use ($channelRegistry): void {
            $registrar = $channelRegistry->webhookRegistrar($this->channelType);

            if (null === $registrar) {
                return;
            }

            $payload = new WebhookRegistrationPayload(
                channelId: $this->channelId,
                token: $this->token,
                secretToken: $this->secretToken,
                webhookPublicHash: $this->webhookPublicHash,
                config: $this->config,
            );

            if ($this->register) {
                $registrar->register($payload);

                return;
            }

            $registrar->deregister($payload);
        });
    }
}
