<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Assistant;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Assistant\Contracts\ChannelServiceInterface;
use App\Domains\Assistant\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Assistant\Enums\ChannelTypeEnum;
use App\Domains\Assistant\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Mockery;
use Tests\Feature\FeatureTestCase;

final class ChannelServiceTest extends FeatureTestCase
{
    private WebhookRegistryWriterInterface $registrySpy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registrySpy = Mockery::spy(WebhookRegistryWriterInterface::class);
        $this->app->instance(WebhookRegistryWriterInterface::class, $this->registrySpy);
        $this->app->forgetInstance(ChannelWebhookRegistryInterface::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_create_generates_webhook_hash(): void
    {
        $tenant = $this->tenant();

        $channel = $this->runInTenant($tenant, function () use ($tenant) {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, [
                'name' => 'A1',
            ]);

            return app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 'token-a',
                'secret_token' => 'secret-a',
                'config'       => ['parse_mode' => 'HTML'],
                'is_active'    => true,
            ]);
        });

        $this->assertSame(48, mb_strlen($channel->webhook_public_hash));
    }

    public function test_create_writes_to_redis_registry(): void
    {
        $tenant = $this->tenant();

        $channel = $this->runInTenant($tenant, function () use ($tenant) {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, [
                'name' => 'A1',
            ]);

            return app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 'token-a',
                'secret_token' => 'secret-a',
                'config'       => [],
                'is_active'    => true,
            ]);
        });

        $this->registrySpy->shouldHaveReceived('write')->with(
            $channel->webhook_public_hash,
            Mockery::on(fn (TenantInterface $t): bool => $t->getId() === $tenant->getId()),
            (string) $channel->assistant_id,
            (string) $channel->getKey(),
            ChannelTypeEnum::Telegram->value,
            'secret-a',
        )->once();

        $this->assertNotSame('', $channel->webhook_public_hash);
    }

    public function test_update_does_not_change_webhook_hash(): void
    {
        $tenant = $this->tenant();

        $hash = $this->runInTenant($tenant, function () use ($tenant): string {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);

            $channel = app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 'token-a',
                'secret_token' => 'secret-a',
                'config'       => [],
                'is_active'    => true,
            ]);

            $hash = $channel->webhook_public_hash;

            app(ChannelServiceInterface::class)->update($channel, [
                'token'        => 'token-b',
                'secret_token' => 'secret-b',
                'config'       => ['k' => 'v'],
                'is_active'    => true,
            ]);

            return $hash;
        });

        $fresh = Channel::query()->firstOrFail();

        $this->assertSame($hash, $fresh->webhook_public_hash);
    }

    public function test_update_overwrites_redis_entry(): void
    {
        $tenant = $this->tenant();

        $channel = $this->runInTenant($tenant, function () use ($tenant): Channel {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);

            $channel = app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 'token-a',
                'secret_token' => 'secret-a',
                'config'       => [],
                'is_active'    => true,
            ]);

            app(ChannelServiceInterface::class)->update($channel, [
                'secret_token' => 'secret-b',
                'is_active'    => true,
            ]);

            return $channel->fresh();
        });

        $this->registrySpy->shouldHaveReceived('write')->twice();
        $this->registrySpy->shouldHaveReceived('write')->with(
            $channel->webhook_public_hash,
            Mockery::on(fn (TenantInterface $t): bool => $t->getId() === $tenant->getId()),
            (string) $channel->assistant_id,
            (string) $channel->getKey(),
            ChannelTypeEnum::Telegram->value,
            'secret-b',
        )->once();
    }

    public function test_rotate_hash_generates_new_hash(): void
    {
        $tenant = $this->tenant();

        [$old, $rotated] = $this->runInTenant($tenant, function () use ($tenant): array {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);

            $channel = app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 'token-a',
                'secret_token' => 'secret-a',
                'config'       => [],
                'is_active'    => true,
            ]);

            $old     = $channel->webhook_public_hash;
            $rotated = app(ChannelServiceInterface::class)->rotateWebhookHash($channel);

            return [$old, $rotated];
        });

        $this->assertNotSame($old, $rotated->webhook_public_hash);
        $this->assertSame(48, mb_strlen($rotated->webhook_public_hash));
    }

    public function test_rotate_hash_updates_redis(): void
    {
        $tenant = $this->tenant();

        $oldHash = $this->runInTenant($tenant, function () use ($tenant): string {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);

            $channel = app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 'token-a',
                'secret_token' => 'secret-a',
                'config'       => [],
                'is_active'    => true,
            ]);

            $oldHash = $channel->webhook_public_hash;

            app(ChannelServiceInterface::class)->rotateWebhookHash($channel);

            return $oldHash;
        });

        $this->registrySpy->shouldHaveReceived('delete')->with($oldHash)->once();
        $this->registrySpy->shouldHaveReceived('write')->twice();
    }

    public function test_deactivate_removes_from_redis(): void
    {
        $tenant = $this->tenant();

        $hash = $this->runInTenant($tenant, function () use ($tenant): string {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);

            $channel = app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 'token-a',
                'secret_token' => 'secret-a',
                'config'       => [],
                'is_active'    => true,
            ]);

            $hash = $channel->webhook_public_hash;

            app(ChannelServiceInterface::class)->deactivate($channel);

            return $hash;
        });

        $this->registrySpy->shouldHaveReceived('delete')->with($hash)->once();
    }

    public function test_reactivate_channel_writes_back_to_redis(): void
    {
        $tenant = $this->tenant();

        $channel = $this->runInTenant($tenant, function () use ($tenant): Channel {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);

            $channel = app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 'token-a',
                'secret_token' => 'secret-a',
                'config'       => [],
                'is_active'    => true,
            ]);

            app(ChannelServiceInterface::class)->deactivate($channel);

            app(ChannelServiceInterface::class)->reactivate($channel->fresh());

            return $channel->fresh();
        });

        $this->registrySpy->shouldHaveReceived('write')->with(
            $channel->webhook_public_hash,
            Mockery::on(fn (TenantInterface $t): bool => $t->getId() === $tenant->getId()),
            (string) $channel->assistant_id,
            (string) $channel->getKey(),
            ChannelTypeEnum::Telegram->value,
            'secret-a',
        )->twice();
    }

    public function test_deleting_assistant_removes_channel_webhook_keys_from_redis(): void
    {
        $tenant = $this->tenant();

        $hash = $this->runInTenant($tenant, function () use ($tenant): string {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);

            $channel = app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 'token-a',
                'secret_token' => 'secret-a',
                'config'       => [],
                'is_active'    => true,
            ]);

            $hash = $channel->webhook_public_hash;
            $assistant->delete();

            return $hash;
        });

        $this->registrySpy->shouldHaveReceived('delete')->with($hash)->once();
    }

    public function test_warmup_rewrites_registry_for_active_channels(): void
    {
        $tenant = $this->tenant();

        $this->runInTenant($tenant, function () use ($tenant): void {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'Warm']);

            app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 'token-a',
                'secret_token' => 'secret-a',
                'config'       => [],
                'is_active'    => true,
            ]);

            $this->app->forgetInstance(ChannelWebhookRegistryInterface::class);
            $warmupSpy = Mockery::spy(WebhookRegistryWriterInterface::class);
            $this->app->instance(WebhookRegistryWriterInterface::class, $warmupSpy);
            $this->app->forgetInstance(ChannelWebhookRegistryInterface::class);

            app(ChannelWebhookRegistryInterface::class)->warmup($tenant);

            $warmupSpy->shouldHaveReceived('write')->once();
        });
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->firstOrFail();
    }

    /**
     * @template T
     *
     * @param  callable(Tenant): T  $callback
     * @return T
     */
    private function runInTenant(Tenant $tenant, callable $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback);
    }
}
