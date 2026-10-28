<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\ChannelWebhookRemoverInterface;
use Fapost\Foundation\Channel\WebhookRegistrationPayload;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Takes down, at the provider, the webhook of every active channel of the current tenant.
 *
 * Runs inside the tenant's context, while the bot tokens still exist in its schema, when the tenant is removed
 * completely. A provider that refuses or cannot be reached never stops the removal: the failure is logged by channel
 * id and exception class (the message of an HTTP error can carry the request URL, and with it the token), and the
 * webhook is left for the provider to drop when its bot token is revoked. An inactive channel was taken down when it
 * was deactivated. Safe to repeat.
 */
final readonly class ChannelProviderWebhookRemover implements ChannelWebhookRemoverInterface
{
    public function __construct(private ChannelRegistryInterface $channels)
    {
    }

    /**
     * @return int how many webhooks were taken down
     */
    public function removeAll(string $tenantId): int
    {
        $removed = 0;

        Channel::query()->active()->orderBy('id')->each(function (Channel $channel) use ($tenantId, &$removed): void {
            $registrar = $this->channels->webhookRegistrar($channel->type);

            if (null === $registrar) {
                return;
            }

            try {
                $registrar->deregister(new WebhookRegistrationPayload(
                    channelId: (string)$channel->getKey(),
                    token: $channel->token,
                    secretToken: $channel->secret_token,
                    webhookPublicHash: $channel->webhook_public_hash,
                    config: is_array($channel->config) ? $channel->config : [],
                ));

                $removed++;
            } catch (Throwable $exception) {
                Log::warning('tenant.decommission.webhook_not_removed', [
                    'tenant_id'  => $tenantId,
                    'channel_id' => (string)$channel->getKey(),
                    'type'       => $channel->type,
                    'exception'  => $exception::class,
                ]);
            }
        });

        return $removed;
    }
}
