<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Assistant;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Channels\Models\Channel;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\FeatureTestCase;

final class ChannelServiceTest extends FeatureTestCase
{
    private WebhookRegistryWriterInterface $registrySpy;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SyncChannelWebhookJob::class]);
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
        Bus::assertDispatchedSync(SyncChannelWebhookJob::class);
    }

    public function test_create_channel_has_ulid_format_id(): void
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

        $this->assertTrue(Str::isUuid($channel->id));
    }

    public function test_create_accepts_dotted_config_fields_from_filament_form(): void
    {
        $tenant = $this->tenant();

        $channel = $this->runInTenant($tenant, function () use ($tenant): Channel {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);

            return app(ChannelServiceInterface::class)->create($assistant, [
                'type'                   => ChannelTypeEnum::Telegram->value,
                'token'                  => 'token-a',
                'secret_token'           => 'secret-a',
                'config.allowed_updates' => ['message', 'callback_query'],
                'config.max_connections' => 50,
                'config.parse_mode'      => 'HTML',
                'is_active'              => true,
            ]);
        });

        $this->assertSame(['message', 'callback_query'], $channel->config['allowed_updates'] ?? null);
        $this->assertSame(50, $channel->config['max_connections'] ?? null);
        $this->assertSame('HTML', $channel->config['parse_mode'] ?? null);
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
        $this->addToAssertionCount(2);
    }

    public function test_update_accepts_dotted_config_fields_from_filament_form(): void
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

            return app(ChannelServiceInterface::class)->update($channel, [
                'secret_token'           => 'secret-b',
                'config.allowed_updates' => ['message', 'callback_query'],
                'config.max_connections' => 80,
                'is_active'              => true,
            ]);
        });

        $this->assertSame(['message', 'callback_query'], $channel->config['allowed_updates'] ?? null);
        $this->assertSame(80, $channel->config['max_connections'] ?? null);
        $this->assertSame('secret-b', $channel->secret_token);
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
        $this->addToAssertionCount(2);
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
        $this->addToAssertionCount(1);
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
        $this->addToAssertionCount(1);
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
        $this->addToAssertionCount(1);
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
            $this->addToAssertionCount(1);
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
