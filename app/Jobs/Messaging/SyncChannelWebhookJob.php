<?php

declare(strict_types=1);

namespace App\Jobs\Messaging;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Contracts\ChannelWebhookStatusRecorderInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use App\Domains\Webhook\Services\WebhookUrlGenerator;
use Fapost\Foundation\Channel\WebhookRegistrationPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Synchronizes provider-side webhooks outside of model persistence hooks.
 *
 * Only a deregister job (a delete, where the row is gone by the time it runs, or a deactivation) carries
 * a snapshot of the credentials. It is dispatched synchronously, which keeps it out of `jobs`; inside a queue worker a
 * failure of a synchronous job is still recorded in `failed_jobs` with its payload, snapshot included.
 * A register job (the observer's and the ingress migrator's) names the channel and passes null
 * credentials: it loads the credentials, hash and config from the channel when it runs
 * ({@see self::$token}), so no secret is serialized into any payload.
 *
 * What the provider answered to a registration is stored on the channel, and a refusal is still thrown, so a queued
 * run keeps the queue's retry and `failed_jobs` handling. A synchronous caller (the channel observer) catches it: a
 * provider refusal never fails the write that caused it.
 */
final class SyncChannelWebhookJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  string                $tenantId           Tenant identifier for context restoration.
     * @param  string                $schema             Tenant schema name for context restoration.
     * @param  string                $channelType        Published channel identifier.
     * @param  string                $webhookPublicHash  Public routing hash used in inbound webhook URLs.
     * @param  string|null           $token              Provider transport token (decrypted at dispatch time), or null
     *                                                   to load it from the channel when the job runs. Pass null
     *                                                   whenever the job is queued.
     * @param  string|null           $secretToken        Provider-side webhook signature secret, with the same
     *                                                   null-means-load rule as the token.
     * @param  array<string, mixed>  $config             Transport-specific channel configuration snapshot.
     * @param  bool                  $register           True to create/update the provider webhook, false to delete
     *                                                   it.
     */
    public function __construct(
        public readonly string $tenantId,
        public readonly string $schema,
        public readonly string $channelId,
        public readonly string $channelType,
        public readonly string $webhookPublicHash,
        public readonly ?string $token,
        public readonly ?string $secretToken,
        public readonly array $config,
        public readonly bool $register,
    ) {
        $this->onQueue('messaging.system');
        $this->afterCommit();
    }

    /**
     * Resolve the channel registrar and apply the requested provider webhook action.
     */
    public function handle(
        ChannelRegistryInterface $channelRegistry,
        TenantSwitcher $switcher,
        WebhookUrlGenerator $urlGenerator,
        WebhookRegistryWriterInterface $registryWriter,
        ChannelWebhookStatusRecorderInterface $statusRecorder,
    ): void {
        $tenant = new RuntimeTenant(id: $this->tenantId, schemaName: $this->schema);

        $switcher->runForTenant($tenant, function () use ($channelRegistry, $urlGenerator, $registryWriter, $statusRecorder): void {
            $registrar = $channelRegistry->webhookRegistrar($this->channelType);

            if (null === $registrar) {
                return;
            }

            $token       = $this->token;
            $secretToken = $this->secretToken;
            $hash        = $this->webhookPublicHash;
            $config      = $this->config;

            if (null === $token || null === $secretToken) {
                $channel = Channel::query()->find($this->channelId);

                // Deleted since the job was queued: there is nothing left to register.
                if (null === $channel) {
                    return;
                }

                $token       = $channel->token;
                $secretToken = $channel->secret_token;
                $hash        = $channel->webhook_public_hash;
                $config      = is_array($channel->config) ? $channel->config : [];
            }

            $payload = new WebhookRegistrationPayload(
                channelId: $this->channelId,
                token: $token,
                secretToken: $secretToken,
                webhookPublicHash: $hash,
                config: $config,
            );

            if ($this->register) {
                try {
                    $registrar->register($payload);
                } catch (Throwable $exception) {
                    $statusRecorder->markFailed($this->channelId);

                    throw $exception;
                }

                $statusRecorder->markRegistered($this->channelId);

                // Recorded only after the provider accepted the URL: until then there
                // is no fact to record, and a failed registration must not look like a
                // completed migration to the ingress drift report.
                $registryWriter->recordIngress(
                    $hash,
                    $urlGenerator->baseFor($this->channelType),
                );

                return;
            }

            $registrar->deregister($payload);

            // Deregistered and taken down: nothing is registered any more. A deleted channel has no row, which the
            // recorder lets pass.
            $statusRecorder->clear($this->channelId);

            $registryWriter->recordIngress($this->webhookPublicHash, null);
        });
    }
}
