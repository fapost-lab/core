<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
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
        private RecordQuotaInterface $recordQuota,
    ) {
    }

    /**
     * Create and persist a channel for the given assistant.
     *
     * This is the only place a channel is created: the current tenant's channel limit is checked here.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws RecordLimitReachedException when the tenant is at its channel limit
     */
    public function create(Assistant $assistant, array $data): Channel
    {
        $this->recordQuota->assertCanCreate(Channel::LIMIT_KEY, Channel::countForLimit());

        $normalized = $this->normalizeInput($data);

        $channel = new Channel([
            'assistant_id' => $assistant->getKey(),
            'tenant_id'    => $assistant->tenant_id,
            'type'         => ChannelTypeEnum::from((string)$normalized['type']),
            'token'        => (string)$normalized['token'],
            'secret_token' => (string)$normalized['secret_token'],
            'config'       => is_array($normalized['config'] ?? null) ? $normalized['config'] : [],
            'is_active'    => (bool)($normalized['is_active'] ?? true),
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
        $allowed = Arr::only($this->normalizeInput($data), [
            'type',
            'token',
            'secret_token',
            'config',
            'is_active',
        ]);

        if (array_key_exists('type', $allowed)) {
            $channel->type = ChannelTypeEnum::from((string)$allowed['type']);
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

    /**
     * Normalize channel form payload so service accepts both nested `config` arrays and dotted `config.*` UI keys.
     *
     * @param  array<string, mixed>  $data
     *
     * @return array<string, mixed>
     */
    private function normalizeInput(array $data): array
    {
        $normalized = $data;
        $config     = is_array($data['config'] ?? null) ? $data['config'] : [];
        $hasConfig  = array_key_exists('config', $data);

        foreach ($data as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'config.')) {
                continue;
            }

            Arr::set($config, Str::after($key, 'config.'), $value);
            $hasConfig = true;
        }

        if ($hasConfig) {
            $normalized['config'] = $config;
        }

        return $normalized;
    }

    private function generateWebhookPublicHash(): string
    {
        return Str::random(48);
    }
}
