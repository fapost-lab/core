<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Messaging;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Contracts\ChannelWebhookStatusRecorderInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Webhook\Enums\IngressDriver;
use App\Domains\Webhook\Services\WebhookUrlGenerator;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Fapost\Foundation\Channel\WebhookRegistrarInterface;
use Fapost\Foundation\Channel\WebhookRegistrationPayload;
use Mockery\MockInterface;
use RuntimeException;
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

        $writer = $this->registryWriter(expected: 'https://app.example.com');

        $job->handle($registry, $this->tenantSwitcher(), $this->urlGenerator(), $writer, $this->recorder(registered: true));
    }

    /**
     * The recorded host must describe what the provider accepted, so it is written
     * against the gateway base only when the channel was actually pointed there.
     */
    public function test_handle_records_the_gateway_host_when_the_channel_is_routed_to_it(): void
    {
        $registrar = $this->mock(WebhookRegistrarInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('register')->once();
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
            register: true,
        );

        $job->handle(
            $registry,
            $this->tenantSwitcher(),
            $this->urlGenerator(driver: IngressDriver::Gateway),
            $this->registryWriter(expected: 'https://webhook.example.com'),
            $this->recorder(registered: true),
        );
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

        $job->handle(
            $registry,
            $this->tenantSwitcher(),
            $this->urlGenerator(),
            $this->registryWriter(expected: null),
            $this->recorder(cleared: true),
        );
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

        $writer = $this->mock(WebhookRegistryWriterInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordIngress')->never();
        });

        $job->handle($registry, $this->tenantSwitcher(), $this->urlGenerator(), $writer, $this->recorder());

        $this->addToAssertionCount(1);
    }

    public function test_a_refused_registration_is_recorded_as_failed_and_still_thrown(): void
    {
        $registrar = $this->mock(WebhookRegistrarInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('register')->once()->andThrow(new RuntimeException('provider says no'));
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
            register: true,
        );

        $writer = $this->mock(WebhookRegistryWriterInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordIngress')->never();
        });

        try {
            $job->handle($registry, $this->tenantSwitcher(), $this->urlGenerator(), $writer, $this->recorder(failed: true));
            $this->fail('The refusal must reach the queue.');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider says no', $exception->getMessage());
        }
    }

    private function recorder(bool $registered = false, bool $failed = false, bool $cleared = false): ChannelWebhookStatusRecorderInterface
    {
        return $this->mock(
            ChannelWebhookStatusRecorderInterface::class,
            function (MockInterface $mock) use ($registered, $failed, $cleared): void {
                $mock->shouldReceive('markRegistered')->times($registered ? 1 : 0)->with('channel-1');
                $mock->shouldReceive('markFailed')->times($failed ? 1 : 0)->with('channel-1');
                $mock->shouldReceive('clear')->times($cleared ? 1 : 0)->with('channel-1');
            }
        );
    }

    private function urlGenerator(IngressDriver $driver = IngressDriver::Laravel): WebhookUrlGenerator
    {
        return new WebhookUrlGenerator(
            baseUrl: 'https://app.example.com',
            gatewayUrl: 'https://webhook.example.com',
            driver: $driver,
            gatewayPlatforms: ['telegram'],
        );
    }

    private function registryWriter(?string $expected): WebhookRegistryWriterInterface
    {
        return $this->mock(
            WebhookRegistryWriterInterface::class,
            function (MockInterface $mock) use ($expected): void {
                $mock->shouldReceive('recordIngress')->once()->with('hash-1', $expected);
            }
        );
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
            $mock->shouldReceive('clearPermissionsCollection')->twice();
        });

        return new TenantSwitcher($tenantContext, $databaseManager, $permissionRegistrar);
    }
}
