<?php

declare(strict_types=1);

namespace App\Domains\Channels\Services;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Services\RecordLimitWatch;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Fapost\Foundation\Quota\Contracts\RecordQuotaInterface;
use Fapost\Foundation\Quota\Exceptions\RecordLimitReachedException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

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
        private RecordLimitWatch $limitWatch,
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
        $countBefore = Channel::countForLimit();

        $this->recordQuota->assertCanCreate(Channel::LIMIT_KEY, $countBefore);

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

        $this->limitWatch->afterSaved(Channel::LIMIT_KEY, $countBefore);

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
     * The old Redis key is removed, and the new channel identity is written using current tenant context. A refusal
     * by the provider does not undo the rotation: the leaked hash stays revoked, and the channel waits, flagged, for
     * {@see reregisterWebhook()}.
     */
    public function rotateWebhookHash(Channel $channel): Channel
    {
        $tenant  = $this->tenantContext->get();
        $oldHash = $channel->webhook_public_hash;

        // The routing swap is queued after the commit ahead of the save, so it runs before the provider
        // synchronization that the save queues behind it: Redis before provider, as for any other write. The
        // observer leaves the routing of a rotation to this method.
        DB::transaction(function () use ($channel, $tenant, $oldHash): void {
            DB::afterCommit(function () use ($channel, $tenant, $oldHash): void {
                $this->registry->remove($oldHash);
                $this->registry->set($channel, $tenant);
            });

            $channel->forceFill(['webhook_public_hash' => $this->generateWebhookPublicHash()]);
            $channel->save();
        });

        return $channel->fresh();
    }

    /**
     * Registers the webhook at the provider again, with the channel's own hash and credentials: what saving the
     * form once more would do, without changing anything. Synchronous; the provider's answer is the channel's
     * stored webhook status on the returned copy. A refusal is reported, not thrown.
     */
    public function reregisterWebhook(Channel $channel): Channel
    {
        if (! $channel->is_active) {
            return $channel;
        }

        $tenant = $this->tenantContext->get();

        try {
            SyncChannelWebhookJob::dispatchSync(
                tenantId: $tenant->getId(),
                schema: $tenant->getSchemaName(),
                channelId: (string)$channel->getKey(),
                channelType: $channel->type->value,
                webhookPublicHash: $channel->webhook_public_hash,
                token: null,
                secretToken: null,
                config: [],
                register: true,
            );
        } catch (Throwable $exception) {
            report($exception);
        }

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
