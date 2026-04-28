<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;

/**
 * Write-through cache for webhook routing (DB is source of truth).
 *
 * Redis keys are not TTL'd; stale keys must be removed via {@see remove()} or a full {@see warmup()}
 * after Redis flush or data repair.
 */
final readonly class ChannelWebhookRegistry implements ChannelWebhookRegistryInterface
{
    /**
     * @param  WebhookRegistryWriterInterface  $writer  DB-backed writer (used for Redis write-through).
     */
    public function __construct(
        private WebhookRegistryWriterInterface $writer,
    ) {
    }

    /**
     * Persist a channel routing entry into Redis via write-through.
     */
    public function set(Channel $channel, TenantInterface $tenant): void
    {
        if ( ! $channel->is_active) {
            return;
        }

        $this->writer->write(
            $channel->webhook_public_hash,
            $tenant,
            (string)$channel->assistant_id,
            (string)$channel->getKey(),
            $channel->type->value,
            (string)$channel->getAttribute('secret_token'),
        );
    }

    /**
     * Remove an entry from Redis routing cache by webhook public hash.
     */
    public function remove(string $webhookPublicHash): void
    {
        $this->writer->delete($webhookPublicHash);
    }

    /**
     * Repopulate Redis routing cache for all active channels in the given tenant.
     */
    public function warmup(TenantInterface $tenant): void
    {
        Channel::query()
            ->active()
            ->with('assistant')
            ->orderBy('id')
            ->each(function (Channel $channel) use ($tenant): void {
                $this->set($channel, $tenant);
            });
    }
}
