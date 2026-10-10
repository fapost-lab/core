<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Messaging;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Contracts\ChannelWebhookStatusRecorderInterface;
use App\Domains\Channels\Models\Channel;
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
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\FeatureTestCase;

/**
 * A queued register job names the channel and carries no credentials: the queue payload is stored
 * in `jobs` and `failed_jobs`, so the token and the webhook secret are loaded when the job runs.
 */
final class SyncChannelWebhookJobCredentialsTest extends FeatureTestCase
{
    private const string TENANT_ID = '00000000-0000-0000-0000-000000000001';

    public function test_a_job_without_credentials_loads_them_from_the_channel(): void
    {
        $channel = $this->channel();
        $job     = $this->job($channel);

        $this->assertStringNotContainsString('live-bot-token', serialize($job));
        $this->assertStringNotContainsString('live-secret-token', serialize($job));

        $registrar = $this->mock(WebhookRegistrarInterface::class, function (MockInterface $mock) use ($channel): void {
            $mock->shouldReceive('register')->once()->withArgs(
                fn (WebhookRegistrationPayload $payload): bool => 'live-bot-token' === $payload->token
                    && 'live-secret-token' === $payload->secretToken
                    && (string) $channel->getKey() === $payload->channelId,
            );
        });

        $writer = $this->mock(WebhookRegistryWriterInterface::class, function (MockInterface $mock) use ($channel): void {
            $mock->shouldReceive('recordIngress')->once()->with($channel->webhook_public_hash, 'https://app.example.com');
        });

        $job->handle($this->registry($registrar), $this->tenantSwitcher(), $this->urlGenerator(), $writer, $this->app->make(ChannelWebhookStatusRecorderInterface::class));
    }

    public function test_a_job_without_credentials_also_takes_the_hash_and_config_from_the_channel(): void
    {
        $channel = $this->channel();
        $job     = new SyncChannelWebhookJob(
            tenantId: self::TENANT_ID,
            schema: 'tenant_test',
            channelId: (string) $channel->getKey(),
            channelType: 'telegram',
            webhookPublicHash: 'stale-hash',
            token: null,
            secretToken: null,
            config: ['allowed_updates' => ['stale']],
            register: true,
        );

        $registrar = $this->mock(WebhookRegistrarInterface::class, function (MockInterface $mock) use ($channel): void {
            $mock->shouldReceive('register')->once()->withArgs(
                fn (WebhookRegistrationPayload $payload): bool => $channel->webhook_public_hash === $payload->webhookPublicHash
                    && [] === $payload->config,
            );
        });

        $writer = $this->mock(WebhookRegistryWriterInterface::class, function (MockInterface $mock) use ($channel): void {
            $mock->shouldReceive('recordIngress')->once()->with($channel->webhook_public_hash, 'https://app.example.com');
        });

        $job->handle($this->registry($registrar), $this->tenantSwitcher(), $this->urlGenerator(), $writer, $this->app->make(ChannelWebhookStatusRecorderInterface::class));
    }

    public function test_a_job_without_credentials_does_nothing_when_the_channel_is_gone(): void
    {
        $channel = $this->channel();
        $job     = $this->job($channel);

        Channel::withoutEvents(static fn (): ?bool => $channel->forceDelete());

        $registrar = $this->mock(WebhookRegistrarInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('register')->never();
        });

        $writer = $this->mock(WebhookRegistryWriterInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('recordIngress')->never();
        });

        $job->handle($this->registry($registrar), $this->tenantSwitcher(), $this->urlGenerator(), $writer, $this->app->make(ChannelWebhookStatusRecorderInterface::class));

        $this->addToAssertionCount(1);
    }

    private function channel(): Channel
    {
        $assistant = Assistant::factory()->create(['tenant_id' => self::TENANT_ID]);

        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->id,
            'tenant_id'    => self::TENANT_ID,
            'token'        => 'live-bot-token',
            'secret_token' => 'live-secret-token',
        ]));
    }

    private function job(Channel $channel): SyncChannelWebhookJob
    {
        return new SyncChannelWebhookJob(
            tenantId: self::TENANT_ID,
            schema: 'tenant_test',
            channelId: (string) $channel->getKey(),
            channelType: 'telegram',
            webhookPublicHash: $channel->webhook_public_hash,
            token: null,
            secretToken: null,
            config: [],
            register: true,
        );
    }

    private function registry(WebhookRegistrarInterface $registrar): ChannelRegistryInterface
    {
        return $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($registrar): void {
            $mock->shouldReceive('webhookRegistrar')->once()->with('telegram')->andReturn($registrar);
        });
    }

    private function urlGenerator(): WebhookUrlGenerator
    {
        return new WebhookUrlGenerator(
            baseUrl: 'https://app.example.com',
            gatewayUrl: 'https://webhook.example.com',
            driver: IngressDriver::Laravel,
            gatewayPlatforms: ['telegram'],
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
