<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Assistant;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Assistant\Contracts\ChannelServiceInterface;
use App\Domains\Assistant\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Assistant\Enums\ChannelTypeEnum;
use App\Domains\Assistant\Models\Assistant;
use App\Domains\Assistant\Models\Channel;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Illuminate\Support\Str;
use Mockery;
use Tests\Feature\FeatureTestCase;

final class AssistantServiceTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        $this->app->forgetInstance(ChannelWebhookRegistryInterface::class);
        $this->app->forgetInstance(AssistantServiceInterface::class);
        parent::tearDown();
    }
    public function test_create_assistant(): void
    {
        $tenant = $this->tenant();

        $assistant = $this->runInTenant($tenant, fn (): Assistant => app(AssistantServiceInterface::class)->create($tenant, [
            'name'             => 'Support',
            'is_active'        => true,
            'fallback_message' => 'Hi',
            'settings'         => ['k' => 'v'],
        ]));

        $this->assertSame('Support', $assistant->name);
        $this->assertTrue($assistant->is_active);
        $this->assertSame('Hi', $assistant->fallback_message);
        $this->assertSame(['k' => 'v'], $assistant->settings);
    }

    public function test_create_assistant_has_ulid_format_id(): void
    {
        $tenant = $this->tenant();

        $assistant = $this->runInTenant($tenant, fn (): Assistant => app(AssistantServiceInterface::class)->create($tenant, [
            'name' => 'ULID Check',
        ]));

        $this->assertTrue(Str::isUuid($assistant->id));
    }

    public function test_deactivate_assistant_deactivates_channels(): void
    {
        $tenant = $this->tenant();

        $this->runInTenant($tenant, function () use ($tenant): void {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);

            app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 't',
                'secret_token' => 's',
                'config'       => [],
                'is_active'    => true,
            ]);

            app(AssistantServiceInterface::class)->deactivate($assistant->fresh());
        });

        $this->assertFalse(Assistant::query()->firstOrFail()->is_active);
        $this->assertFalse(Channel::query()->firstOrFail()->is_active);
    }

    public function test_activate_assistant_sets_active_flag(): void
    {
        $tenant = $this->tenant();

        $this->runInTenant($tenant, function () use ($tenant): void {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);

            app(AssistantServiceInterface::class)->deactivate($assistant->fresh());

            app(AssistantServiceInterface::class)->activate($assistant->fresh());
        });

        $this->assertTrue(Assistant::query()->firstOrFail()->is_active);
    }

    public function test_deactivate_assistant_removes_channel_webhook_registry_entries(): void
    {
        $spy = Mockery::spy(ChannelWebhookRegistryInterface::class);
        $this->app->instance(ChannelWebhookRegistryInterface::class, $spy);
        $this->app->forgetInstance(AssistantServiceInterface::class);

        $tenant = $this->tenant();

        $hash = $this->runInTenant($tenant, function () use ($tenant): string {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);

            app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 't',
                'secret_token' => 's',
                'config'       => [],
                'is_active'    => true,
            ]);

            $hash = Channel::query()->firstOrFail()->webhook_public_hash;

            app(AssistantServiceInterface::class)->deactivate($assistant->fresh());

            return $hash;
        });

        $spy->shouldHaveReceived('remove')->with($hash)->twice();
        $this->addToAssertionCount(1);
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
