<?php

declare(strict_types=1);

namespace App\Domains\Assistant\Services;

use App\Domains\Assistant\Contracts\ChannelServiceInterface;
use App\Domains\Assistant\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Assistant\Enums\ChannelTypeEnum;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Channel lifecycle service.
 *
 * Responsible for creating/updating channels and for coordinating Redis webhook routing via
 * {@see ChannelWebhookRegistryInterface}, including safe webhook hash rotation.
 */
final readonly class ChannelService implements ChannelServiceInterface
{
    public function __construct(
        private ChannelWebhookRegistryInterface $registry,
        private TenantContextInterface $tenantContext,
    ) {
    }

    /**
     * Create and persist a channel for the given assistant.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(Assistant $assistant, array $data): Channel
    {
        $channel = new Channel([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => $assistant->tenant_id,
            'type'         => ChannelTypeEnum::from((string) $data['type']),
            'token'        => (string) $data['token'],
            'secret_token' => (string) $data['secret_token'],
            'config'       => is_array($data['config'] ?? null) ? $data['config'] : [],
            'is_active'    => (bool) ($data['is_active'] ?? true),
        ]);
        $channel->save();

        return $channel->fresh();
    }

    /**
     * Update a channel using a whitelist of allowed fields.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Channel $channel, array $data): Channel
    {
        $allowed = Arr::only($data, [
            'type',
            'token',
            'secret_token',
            'config',
            'is_active',
        ]);

        if (array_key_exists('type', $allowed)) {
            $channel->type = ChannelTypeEnum::from((string) $allowed['type']);
            unset($allowed['type']);
        }

        if (array_key_exists('config', $allowed)) {
            $channel->config = is_array($allowed['config']) ? $allowed['config'] : [];
            unset($allowed['config']);
        }

        $channel->fill($allowed);
        $channel->save();

        return $channel->fresh();
    }

    /**
     * Rotate `webhook_public_hash` and update Redis routing accordingly.
     *
     * The old Redis key is removed, and the new channel identity is written using current tenant context.
     */
    public function rotateWebhookHash(Channel $channel): Channel
    {
        $tenant  = $this->tenantContext->get();
        $oldHash = $channel->webhook_public_hash;

        $channel->forceFill(['webhook_public_hash' => $this->generateWebhookPublicHash()]);
        $channel->save();

        $this->registry->remove($oldHash);
        $this->registry->set($channel->fresh(), $tenant);

        return $channel->fresh();
    }

    /**
     * Deactivate the channel and persist the inactive state.
     *
     * Redis routing is updated via observers/channel lifecycle hooks (not here).
     */
    public function deactivate(Channel $channel): void
    {
        $channel->is_active = false;
        $channel->save();
    }

    /**
     * Reactivate the channel and persist the active state.
     *
     * Redis routing is repopulated via observers/channel lifecycle hooks.
     */
    public function reactivate(Channel $channel): void
    {
        $channel->is_active = true;
        $channel->save();
    }

    private function generateWebhookPublicHash(): string
    {
        return Str::random(48);
    }
}
