<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Messaging;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Messaging\Observers\ChannelObserver;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use FAPost\Foundation\Channel\WebhookRegistrarInterface;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;
use Tests\TestCase;

final class ChannelObserverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
    }

    public function test_saved_dispatches_register_job_sync_for_new_active_supported_channel(): void
    {
        $observer                    = $this->observer(hasRegistrar: true);
        $channel                     = $this->channel(isActive: true);
        $channel->wasRecentlyCreated = true;
        $observer->saved($channel);

        Bus::assertDispatchedSync(SyncChannelWebhookJob::class, fn (SyncChannelWebhookJob $job): bool => 'telegram' === $job->channelType
                && 'hash-1' === $job->webhookPublicHash
                && 'token-1' === $job->token
                && 'secret-1' === $job->secretToken
                && 'tenant-1' === $job->tenantId
                && true === $job->register);
    }

    public function test_saved_does_not_dispatch_for_new_inactive_channel(): void
    {
        $observer                    = $this->observer(hasRegistrar: true);
        $channel                     = $this->channel(isActive: false);
        $channel->wasRecentlyCreated = true;
        $observer->saved($channel);

        Bus::assertNothingDispatched();
    }

    public function test_deleted_dispatches_deregister_job_sync_for_supported_channel(): void
    {
        $observer = $this->observer(hasRegistrar: true);
        $observer->deleted($this->channel(isActive: true));

        Bus::assertDispatchedSync(SyncChannelWebhookJob::class, fn (SyncChannelWebhookJob $job): bool => false === $job->register);
    }

    public function test_saved_dispatches_register_job_sync_when_token_changes(): void
    {
        $channel  = $this->channelWithChanges(changed: ['token'], isActive: true);
        $observer = $this->observer(hasRegistrar: true);
        $observer->saved($channel);

        Bus::assertDispatchedSync(SyncChannelWebhookJob::class, fn (SyncChannelWebhookJob $job): bool => true === $job->register);
    }

    public function test_saved_dispatches_register_job_sync_when_config_changes(): void
    {
        $channel  = $this->channelWithChanges(changed: ['config'], isActive: true);
        $observer = $this->observer(hasRegistrar: true);
        $observer->saved($channel);

        Bus::assertDispatchedSync(
            SyncChannelWebhookJob::class,
            fn (SyncChannelWebhookJob $job): bool => true === $job->register
                && ['allowed_updates' => ['message']] === $job->config,
        );
    }

    public function test_saved_dispatches_deregister_job_sync_when_channel_is_deactivated(): void
    {
        $channel  = $this->channelWithChanges(changed: ['is_active'], isActive: false);
        $observer = $this->observer(hasRegistrar: true);
        $observer->saved($channel);

        Bus::assertDispatchedSync(SyncChannelWebhookJob::class, fn (SyncChannelWebhookJob $job): bool => false === $job->register);
    }

    public function test_saved_dispatches_register_job_sync_when_channel_is_reactivated(): void
    {
        $channel  = $this->channelWithChanges(changed: ['is_active'], isActive: true);
        $observer = $this->observer(hasRegistrar: true);
        $observer->saved($channel);

        Bus::assertDispatchedSync(SyncChannelWebhookJob::class, fn (SyncChannelWebhookJob $job): bool => true === $job->register);
    }

    public function test_saved_does_not_dispatch_when_no_transport_fields_changed(): void
    {
        $channel  = $this->channelWithChanges(changed: ['name'], isActive: true);
        $observer = $this->observer(hasRegistrar: false);
        $observer->saved($channel);

        Bus::assertNothingDispatched();
    }

    public function test_saved_does_not_dispatch_for_inactive_channel_with_transport_changes(): void
    {
        $channel  = $this->channelWithChanges(changed: ['token'], isActive: false);
        $observer = $this->observer(hasRegistrar: false);
        $observer->saved($channel);

        Bus::assertNothingDispatched();
    }

    public function test_unsupported_channel_does_not_dispatch_job(): void
    {
        $channel  = $this->channelWithChanges(changed: ['token'], isActive: true);
        $observer = $this->observer(hasRegistrar: false);
        $observer->saved($channel);

        Bus::assertNothingDispatched();
    }

    private function observer(bool $hasRegistrar): ChannelObserver
    {
        $registrar = $hasRegistrar
            ? $this->mock(WebhookRegistrarInterface::class)
            : null;

        $registry = $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($registrar): void {
            $mock->shouldReceive('webhookRegistrar')->andReturn($registrar);
        });

        $tenant = $this->mock(TenantInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('getId')->andReturn('tenant-1');
            $mock->shouldReceive('getSchemaName')->andReturn('tenant_test');
        });

        $tenantContext = $this->mock(TenantContextInterface::class, function (MockInterface $mock) use ($tenant): void {
            $mock->shouldReceive('get')->andReturn($tenant);
        });

        return new ChannelObserver($registry, $tenantContext);
    }

    private function channel(bool $isActive = true): Channel
    {
        $channel = new Channel();
        $channel->forceFill([
            'type'                => ChannelTypeEnum::Telegram,
            'token'               => 'token-1',
            'secret_token'        => 'secret-1',
            'config'              => ['allowed_updates' => ['message']],
            'webhook_public_hash' => 'hash-1',
            'is_active'           => $isActive,
        ]);

        return $channel;
    }

    /**
     * Build a channel model that reports exactly the given fields as changed.
     *
     * Uses forceFill() so that encrypted casts are applied correctly.
     * Sets the "before" state as original, then forceFill's the "after" state
     * for changed fields so Eloquent dirty-tracking reports them as changed.
     *
     * @param  list<string>  $changed
     */
    private function channelWithChanges(array $changed, bool $isActive): Channel
    {
        // "After" (current) values — what the model looks like after the update.
        $after = [
            'type'                => ChannelTypeEnum::Telegram->value,
            'token'               => 'token-1',
            'secret_token'        => 'secret-1',
            'config'              => ['allowed_updates' => ['message']],
            'webhook_public_hash' => 'hash-1',
            'is_active'           => $isActive,
        ];

        // "Before" (original) sentinel values for fields that should appear changed.
        $beforeSentinels = [
            'token'               => 'token-old',
            'secret_token'        => 'secret-old',
            'config'              => [],
            'webhook_public_hash' => 'hash-old',
            'is_active'           => ! $isActive,
        ];

        // Build the before state: same as after, except changed fields use sentinels.
        $before = $after;
        foreach ($changed as $field) {
            if (array_key_exists($field, $beforeSentinels)) {
                $before[$field] = $beforeSentinels[$field];
            }
        }

        $channel = new Channel();
        $channel->forceFill($before);   // applies casts; sets the "before" attribute values
        $channel->syncOriginal();        // saves current (encrypted) state as the original snapshot

        // Now apply the "after" values for changed fields — makes them dirty.
        foreach ($changed as $field) {
            if (array_key_exists($field, $after) && $before[$field] !== $after[$field]) {
                $channel->forceFill([$field => $after[$field]]);
            }
        }

        $channel->syncChanges(); // copies dirty fields into $changes for wasChanged()

        return $channel;
    }
}
