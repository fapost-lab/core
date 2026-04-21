<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Messaging;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use FAPost\Foundation\Channel\WebhookRegistrarInterface;
use FAPost\Foundation\Channel\WebhookRegistrationPayload;
use Mockery\MockInterface;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SyncChannelWebhookJobTest extends TestCase
{
    public function test_handle_registers_provider_webhook_when_requested(): void
    {
        $registrar = $this->mock(WebhookRegistrarInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('register')->once()->withArgs(
                fn (WebhookRegistrationPayload $payload): bool => 'token-1' === $payload->token
                    && 'channel-1' === $payload->channelId
                    && 'secret-1' === $payload->secretToken
                    && 'hash-1' === $payload->webhookPublicHash
                    && ['message'] === ($payload->config['allowed_updates'] ?? null)
            );
        });

        $registry = $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($registrar): void {
            $mock->shouldReceive('webhookRegistrar')->once()->with('telegram')->andReturn($registrar);
        });

        $job = new SyncChannelWebhookJob(
            tenantId: 'tenant-1',
            schema: 'tenant_test',
            channelId: 'channel-1',
            channelType: 'telegram',
            webhookPublicHash: 'hash-1',
            token: 'token-1',
            secretToken: 'secret-1',
            config: ['allowed_updates' => ['message']],
            register: true,
        );

        $job->handle($registry, $this->tenantSwitcher());
    }

    public function test_handle_deregisters_provider_webhook_when_requested(): void
    {
        $registrar = $this->mock(WebhookRegistrarInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('deregister')->once()->withArgs(
                fn (WebhookRegistrationPayload $payload): bool => 'token-1' === $payload->token
                    && 'channel-1' === $payload->channelId
                    && 'hash-1' === $payload->webhookPublicHash
            );
        });

        $registry = $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($registrar): void {
            $mock->shouldReceive('webhookRegistrar')->once()->with('telegram')->andReturn($registrar);
        });

        $job = new SyncChannelWebhookJob(
            tenantId: 'tenant-1',
            schema: 'tenant_test',
            channelId: 'channel-1',
            channelType: 'telegram',
            webhookPublicHash: 'hash-1',
            token: 'token-1',
            secretToken: 'secret-1',
            config: [],
            register: false,
        );

        $job->handle($registry, $this->tenantSwitcher());
    }

    public function test_handle_returns_silently_when_no_registrar_exists(): void
    {
        $registry = $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('webhookRegistrar')->once()->with('telegram')->andReturn(null);
        });

        $job = new SyncChannelWebhookJob(
            tenantId: 'tenant-1',
            schema: 'tenant_test',
            channelId: 'channel-1',
            channelType: 'telegram',
            webhookPublicHash: 'hash-1',
            token: 'token-1',
            secretToken: 'secret-1',
            config: [],
            register: true,
        );

        $job->handle($registry, $this->tenantSwitcher());

        $this->addToAssertionCount(1);
    }

    private function tenantSwitcher(): TenantSwitcher
    {
        $tenantContext = $this->mock(TenantContextInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('isResolved')->once()->andReturn(false);
            $mock->shouldReceive('set')->once();
            $mock->shouldReceive('reset')->once();
        });

        $databaseManager = $this->mock(TenantDatabaseManagerInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('switchTo')->once();
            $mock->shouldReceive('restore')->once();
        });

        $permissionRegistrar = $this->mock(PermissionRegistrar::class, function (MockInterface $mock): void {
            $mock->shouldReceive('forgetCachedPermissions')->twice();
        });

        return new TenantSwitcher($tenantContext, $databaseManager, $permissionRegistrar);
    }
}
