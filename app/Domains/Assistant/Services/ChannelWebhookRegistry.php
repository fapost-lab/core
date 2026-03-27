<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Services;

use App\Domains\Assistant\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Assistant\Models\Channel;
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
    public function __construct(
        private WebhookRegistryWriterInterface $writer,
    ) {
    }

    public function set(Channel $channel, TenantInterface $tenant): void
    {
        if ( ! $channel->is_active) {
            return;
        }

        $this->writer->write(
            $channel->webhook_public_hash,
            $tenant,
            (string) $channel->assistant_id,
            (string) $channel->getKey(),
            $channel->type->value,
            (string) $channel->getAttribute('secret_token'),
        );
    }

    public function remove(string $webhookPublicHash): void
    {
        $this->writer->delete($webhookPublicHash);
    }

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
