<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Contact;

use App\Domains\Assistant\Contracts\AssistantServiceInterface;
use App\Domains\Channels\Contracts\ChannelServiceInterface;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Channels\Enums\ChannelTypeEnum;
use App\Domains\Contact\Contracts\ContactServiceInterface;
use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Contact\Models\ChannelContact;
use App\Domains\Contact\Models\Contact;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Jobs\Messaging\SyncChannelWebhookJob;
use Closure;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Tests\Feature\FeatureTestCase;

final class ContactServiceTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([SyncChannelWebhookJob::class]);
        $registry = Mockery::mock(ChannelWebhookRegistryInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('set')->andReturnNull();
            $mock->shouldReceive('remove')->andReturnNull();
        });

        $this->app->instance(ChannelWebhookRegistryInterface::class, $registry);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        $this->app->forgetInstance(ChannelWebhookRegistryInterface::class);

        parent::tearDown();
    }

    public function test_find_or_create_persists_single_contact_per_tenant_platform_external_id(): void
    {
        $tenant = $this->tenant();

        [$first, $second] = $this->runInTenant($tenant, function () use ($tenant): array {
            $service = app(ContactServiceInterface::class);

            $a = $service->findOrCreate(
                $tenant->getId(),
                PlatformEnum::Telegram,
                'ext-1',
                ['username' => 'u1'],
            );
            $b = $service->findOrCreate(
                $tenant->getId(),
                PlatformEnum::Telegram,
                'ext-1',
                ['username' => 'ignored'],
            );

            return [$a, $b];
        });

        $this->assertSame($first->id, $second->id);
        $this->assertSame(['username' => 'u1'], $first->fresh()->meta);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_find_or_create_separates_contacts_by_platform_for_same_external_id(): void
    {
        $tenant = $this->tenant();

        $this->runInTenant($tenant, function () use ($tenant): void {
            $service = app(ContactServiceInterface::class);

            $tg = $service->findOrCreate($tenant->getId(), PlatformEnum::Telegram, 'same-id', []);
            $wa = $service->findOrCreate($tenant->getId(), PlatformEnum::WhatsApp, 'same-id', []);

            $this->assertNotSame($tg->id, $wa->id);
        });

        $this->assertSame(2, Contact::query()->count());
    }

    public function test_find_or_create_channel_contact_creates_one_row_per_pair(): void
    {
        $tenant = $this->tenant();

        $channelId = $this->runInTenant($tenant, function () use ($tenant): string {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);
            $channel   = app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 't',
                'secret_token' => 's',
                'config'       => [],
                'is_active'    => true,
            ]);

            $service = app(ContactServiceInterface::class);
            $contact = $service->findOrCreate($tenant->getId(), PlatformEnum::Telegram, 'u-1', []);

            $service->findOrCreateChannelContact($contact, $channel->id);
            $service->findOrCreateChannelContact($contact, $channel->id);

            return $channel->id;
        });

        $this->assertSame(1, ChannelContact::query()->where('channel_id', $channelId)->count());
    }

    public function test_find_or_create_channel_contact_updates_last_interaction_at_on_each_call(): void
    {
        $tenant = $this->tenant();

        [$firstAt, $secondAt] = $this->runInTenant($tenant, function () use ($tenant): array {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);
            $channel   = app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 't',
                'secret_token' => 's',
                'config'       => [],
                'is_active'    => true,
            ]);

            $service = app(ContactServiceInterface::class);
            $contact = $service->findOrCreate($tenant->getId(), PlatformEnum::Telegram, 'u-2', []);

            $service->findOrCreateChannelContact($contact, $channel->id);
            $firstAt = $contact->channelContacts()->firstOrFail()->last_interaction_at;

            $this->travel(5)->seconds();

            $service->findOrCreateChannelContact($contact->fresh(), $channel->id);
            $secondAt = $contact->channelContacts()->firstOrFail()->fresh()->last_interaction_at;

            return [$firstAt, $secondAt];
        });

        $this->assertNotNull($firstAt);
        $this->assertNotNull($secondAt);
        $this->assertTrue($secondAt->greaterThan($firstAt));
    }

    public function test_repeated_service_calls_remain_idempotent_for_contact_and_channel_contact(): void
    {
        $tenant = $this->tenant();

        $this->runInTenant($tenant, function () use ($tenant): void {
            $assistant = app(AssistantServiceInterface::class)->create($tenant, ['name' => 'A1']);
            $channel   = app(ChannelServiceInterface::class)->create($assistant, [
                'type'         => ChannelTypeEnum::Telegram->value,
                'token'        => 't',
                'secret_token' => 's',
                'config'       => [],
                'is_active'    => true,
            ]);

            $service = app(ContactServiceInterface::class);

            $c1 = $service->findOrCreate($tenant->getId(), PlatformEnum::Telegram, 'stable', ['k' => 1]);
            $c2 = $service->findOrCreate($tenant->getId(), PlatformEnum::Telegram, 'stable', ['k' => 2]);
            $this->assertSame($c1->id, $c2->id);
            $this->assertSame(['k' => 1], $c1->fresh()->meta);

            $cc1 = $service->findOrCreateChannelContact($c1, $channel->id);
            $cc2 = $service->findOrCreateChannelContact($c1->fresh(), $channel->id);
            $this->assertSame($cc1->id, $cc2->id);
        });
    }

    public function test_update_language_updates_contact_language(): void
    {
        $tenant = $this->tenant();

        $this->runInTenant($tenant, function () use ($tenant): void {
            $service = app(ContactServiceInterface::class);
            $contact = $service->findOrCreate($tenant->getId(), PlatformEnum::Telegram, 'lang-user', []);

            $updated = $service->updateLanguage($contact->id, 'es');

            $this->assertSame('es', $updated->language);
            $this->assertSame('es', $contact->fresh()->language);
        });
    }

    private function tenant(): Tenant
    {
        return Tenant::query()->firstOrFail();
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function runInTenant(Tenant $tenant, Closure $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback);
    }
}
