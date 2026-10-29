<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domains\Assistant\Models\Assistant;
use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Models\Channel;
use App\Domains\Channels\Services\ChannelProviderWebhookRemover;
use App\Domains\Media\Services\TenantMediaPurger;
use App\Domains\Media\Storage\TenantMediaDisk;
use App\Domains\Tenancy\Contracts\TenantCacheForgetterInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use App\Domains\Tenancy\Exceptions\TenantNotActiveException;
use App\Domains\Tenancy\Infrastructure\CoreTenantDecommissioner;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\Services\HostTenantResolver;
use App\Domains\Tenancy\Services\SupportAccessTokenStore;
use App\Domains\Tenancy\Services\TenantCaches;
use App\Domains\Tenancy\Services\TenantDecommissioner;
use App\Domains\Tenancy\Services\TenantOwnCacheForgetter;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Settings\TenantSettings;
use Fapost\Foundation\Channel\WebhookRegistrarInterface;
use Fapost\Foundation\Channel\WebhookRegistrationPayload;
use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Fapost\Foundation\Tenancy\Contracts\TenantDecommissionerInterface;
use Fapost\Foundation\Tenancy\Enums\DecommissionFailure;
use Fapost\Foundation\Tenancy\Enums\DecommissionReason;
use Fapost\Foundation\Tenancy\Events\TenantDecommissioned;
use Fapost\Foundation\Tenancy\Exceptions\TenantDecommissionFailedException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Stringable;
use Tests\Feature\FeatureTestCase;
use Tests\Support\FailingTenantRepository;
use Tests\Support\FakeTenantAccessMode;
use Tests\Support\FakeTenantSchemas;

/**
 * The decommission contract against the real landlord tables: the provider webhooks, the routing, the support tokens,
 * the media the installation holds, the schema and the row go, in that order and safely to repeat, and the event is
 * dispatched once. The schema port is a fake that remembers names, so the channels of the test tenant stand in for
 * the doomed tenant's own.
 */
final class CoreTenantDecommissionerTest extends FeatureTestCase
{
    /** @var list<string> */
    private array $redisKeys = [];

    /** @var list<string> */
    private array $mediaDirectories = [];

    private FakeTenantSchemas $schemas;

    private ?LoggerInterface $logger = null;

    /** @var list<TenantCacheForgetterInterface> */
    private array $extraForgetters = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemas = new FakeTenantSchemas();
    }

    protected function tearDown(): void
    {
        if ([] !== $this->redisKeys) {
            Redis::del(...$this->redisKeys);
        }

        foreach ($this->mediaDirectories as $directory) {
            File::deleteDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_the_container_resolves_the_contract_to_core_implementation(): void
    {
        $this->assertInstanceOf(CoreTenantDecommissioner::class, $this->app->make(TenantDecommissionerInterface::class));
    }

    public function test_it_removes_everything_of_an_active_tenant_and_nothing_of_another(): void
    {
        $doomed = $this->tenant('doomed');
        $kept   = $this->tenant('keep-me');
        $this->seedLeftovers($doomed);
        $this->seedLeftovers($kept);
        Event::fake([TenantDecommissioned::class]);
        Log::spy();

        $result = $this->decommissioner()->decommission($doomed->getKey(), DecommissionReason::OwnerRequest);

        $this->assertFalse($result->alreadyGone);
        $this->assertFalse($result->mediaKeptInClientStorage);
        $this->assertSame($doomed->getKey(), $result->tenantId);
        $this->assertSame('doomed', $result->slug);

        $this->assertGone($doomed);
        $this->assertKept($kept);

        Event::assertDispatchedTimes(TenantDecommissioned::class, 1);
        Event::assertDispatched(
            TenantDecommissioned::class,
            static fn (TenantDecommissioned $event): bool => $event->tenantId === $doomed->getKey()
                && 'doomed' === $event->slug
                && DecommissionReason::OwnerRequest === $event->reason,
        );
        Log::shouldHaveReceived('info')->once()->with('tenant.decommissioned', [
            'tenant_id' => $doomed->getKey(),
            'slug'      => 'doomed',
            'reason'    => 'owner_request',
        ]);
    }

    public function test_a_stopped_tenant_is_removed_too(): void
    {
        $this->app->instance(TenantAccessModeInterface::class, FakeTenantAccessMode::stopped());
        $doomed = $this->tenant('doomed');
        $this->seedLeftovers($doomed);
        Event::fake([TenantDecommissioned::class]);

        $result = $this->decommissioner()->decommission($doomed->getKey(), DecommissionReason::Retention);

        $this->assertFalse($result->alreadyGone);
        $this->assertGone($doomed);
        Event::assertDispatched(
            TenantDecommissioned::class,
            static fn (TenantDecommissioned $event): bool => DecommissionReason::Retention === $event->reason,
        );
    }

    public function test_an_inactive_and_a_suspended_tenant_can_be_removed(): void
    {
        foreach ([TenantStatus::Inactive, TenantStatus::Suspended] as $status) {
            $tenant = $this->tenant('t-' . $status->value, $status);

            $this->assertFalse($this->decommissioner()->decommission($tenant->getKey(), DecommissionReason::Retention)->alreadyGone);
            $this->assertNull(Tenant::on('landlord')->find($tenant->getKey()));
        }
    }

    public function test_an_unknown_tenant_is_already_gone_and_announces_nothing(): void
    {
        Event::fake([TenantDecommissioned::class]);
        Log::spy();

        foreach ([(string) Str::ulid()->toRfc4122(), 'not-an-id'] as $id) {
            $result = $this->decommissioner()->decommission($id, DecommissionReason::Retention);

            $this->assertTrue($result->alreadyGone);
            $this->assertNull($result->slug);
            $this->assertFalse($result->mediaKeptInClientStorage);
        }

        Event::assertNotDispatched(TenantDecommissioned::class);
        Log::shouldNotHaveReceived('info');
    }

    public function test_a_pending_tenant_is_refused_and_nothing_is_deleted(): void
    {
        $pending = $this->tenant('pending-one', TenantStatus::Pending);
        $this->seedLeftovers($pending);
        Event::fake([TenantDecommissioned::class]);

        try {
            $this->decommissioner()->decommission($pending->getKey(), DecommissionReason::Retention);
            $this->fail('A pending tenant must be refused.');
        } catch (TenantDecommissionFailedException $exception) {
            $this->assertSame(DecommissionFailure::Pending, $exception->reason);
            $this->assertSame($pending->getKey(), $exception->tenantId);
        }

        $this->assertKept($pending);
        Event::assertNotDispatched(TenantDecommissioned::class);
    }

    public function test_media_in_a_client_bucket_is_not_touched_and_is_reported(): void
    {
        $doomed = $this->tenant('doomed', config: ['media' => ['storage' => ['driver' => 's3', 'bucket' => 'client-bucket']]]);
        $this->seedLeftovers($doomed);

        $result = $this->decommissioner()->decommission($doomed->getKey(), DecommissionReason::OwnerRequest);

        $this->assertTrue($result->mediaKeptInClientStorage);
        $this->assertGone($doomed);
    }

    public function test_the_provider_webhook_of_every_active_channel_is_taken_down_before_the_schema(): void
    {
        $doomed                   = $this->tenant('doomed');
        $this->schemas->schemas[] = $doomed->schema_name;
        [$registrar, $registry]   = $this->recordingRegistrar();
        $this->app->instance(ChannelRegistryInterface::class, $registry);

        $assistant = Channel::withoutEvents(fn (): Assistant => Assistant::factory()->create());
        $active    = $this->channel($assistant, 'token-a', true);
        $this->channel($assistant, 'token-b', false);

        $order                   = [];
        $schema                  = $doomed->schema_name;
        $registrar->onDeregister = function () use (&$order, $schema): void {
            $order[] = ['deregister', in_array($schema, $this->schemas->schemas, true)];
        };

        $this->decommissioner()->decommission($doomed->getKey(), DecommissionReason::OwnerRequest);

        $this->assertSame([['deregister', true]], $order, 'The schema must still exist when the provider is called.');
        $this->assertCount(1, $registrar->deregistered);
        $this->assertSame('token-a', $registrar->deregistered[0]->token);
        $this->assertSame((string) $active->getKey(), $registrar->deregistered[0]->channelId);
        $this->assertNotContains($doomed->schema_name, $this->schemas->schemas);
    }

    public function test_a_provider_failure_is_logged_and_does_not_stop_the_removal(): void
    {
        $doomed                   = $this->tenant('doomed');
        $this->schemas->schemas[] = $doomed->schema_name;
        [$registrar, $registry]   = $this->recordingRegistrar();
        $registrar->failWith      = new RuntimeException('https://api.telegram.org/botSECRET/deleteWebhook refused');
        $this->app->instance(ChannelRegistryInterface::class, $registry);
        $this->channel(Channel::withoutEvents(fn (): Assistant => Assistant::factory()->create()), 'token-a', true);
        Event::fake([TenantDecommissioned::class]);
        Log::spy();

        $this->decommissioner()->decommission($doomed->getKey(), DecommissionReason::OwnerRequest);

        $this->assertNull(Tenant::on('landlord')->find($doomed->getKey()));
        Event::assertDispatchedTimes(TenantDecommissioned::class, 1);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            static fn (string $message, array $context): bool => 'tenant.decommission.webhook_not_removed' === $message
                && RuntimeException::class === $context['exception']
                && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'SECRET'),
        );
    }

    public function test_a_repeat_after_a_failure_half_way_finishes_the_rest(): void
    {
        $doomed                   = $this->tenant('doomed');
        $this->schemas->schemas[] = $doomed->schema_name;
        $this->seedLeftovers($doomed);
        Event::fake([TenantDecommissioned::class]);

        $repository = new FailingTenantRepository();
        $repository->before('delete', static function (): void {
            throw new RuntimeException('landlord went away');
        });

        try {
            $this->decommissioner($repository)->decommission($doomed->getKey(), DecommissionReason::OwnerRequest);
            $this->fail('The failure must surface.');
        } catch (TenantDecommissionFailedException $exception) {
            $this->assertSame(DecommissionFailure::Failed, $exception->reason);
            $this->assertSame('landlord went away', $exception->getPrevious()?->getMessage());
        }

        // Everything before the row has gone, the event included; the row is what tells the repeat there is more to do.
        $this->assertNotNull(Tenant::on('landlord')->find($doomed->getKey()));
        $this->assertNotContains($doomed->schema_name, $this->schemas->schemas);
        Event::assertDispatchedTimes(TenantDecommissioned::class, 1);

        $repository->before('delete', static function (): void {
        });

        $result = $this->decommissioner($repository)->decommission($doomed->getKey(), DecommissionReason::OwnerRequest);

        $this->assertFalse($result->alreadyGone);
        $this->assertGone($doomed);
        Event::assertDispatchedTimes(TenantDecommissioned::class, 2);

        $again = $this->decommissioner()->decommission($doomed->getKey(), DecommissionReason::OwnerRequest);
        $this->assertTrue($again->alreadyGone);
        Event::assertDispatchedTimes(TenantDecommissioned::class, 2);
    }

    public function test_a_listener_that_fails_leaves_the_tenant_for_a_retry_that_dispatches_the_event_again(): void
    {
        $doomed                   = $this->tenant('doomed');
        $this->schemas->schemas[] = $doomed->schema_name;
        $this->seedLeftovers($doomed);
        $this->logger = new class () extends AbstractLogger {
            /** @var list<string> */
            public array $messages = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->messages[] = (string) $message;
            }
        };

        $calls = 0;
        Event::listen(TenantDecommissioned::class, static function () use (&$calls): void {
            if (1 === ++$calls) {
                throw new RuntimeException('operator data store is down');
            }
        });

        try {
            $this->decommissioner()->decommission($doomed->getKey(), DecommissionReason::Retention);
            $this->fail('The listener failure must surface.');
        } catch (TenantDecommissionFailedException $exception) {
            $this->assertSame(DecommissionFailure::Failed, $exception->reason);
        }

        // The row stays, so the next attempt finds the tenant (schema already gone) and announces it again.
        $this->assertSame(1, $calls);
        $this->assertNotNull(Tenant::on('landlord')->find($doomed->getKey()));
        $this->assertNotContains($doomed->schema_name, $this->schemas->schemas);

        $result = $this->decommissioner()->decommission($doomed->getKey(), DecommissionReason::Retention);

        $this->assertFalse($result->alreadyGone);
        $this->assertSame(2, $calls, 'The event is delivered again on the retry.');
        $this->assertGone($doomed);
        $this->assertSame(['tenant.decommissioned'], $this->logger->messages, 'The line is written once, by the attempt that finished.');
    }

    public function test_the_tenant_stops_being_served_first_and_stays_unserved_after_a_failed_run(): void
    {
        $doomed = $this->tenant('doomed');
        $this->seedLeftovers($doomed);
        config(['tenancy.resolution' => 'host']);
        $resolver = $this->app->make(HostTenantResolver::class);
        $host     = fn (): Request => Request::create('http://doomed.' . config('tenancy.base_domain') . '/admin/login');

        $this->assertSame($doomed->getKey(), $resolver->resolve($host())->getId());

        $repository = new FailingTenantRepository();
        $repository->before('delete', static function (): void {
            throw new RuntimeException('landlord went away');
        });

        $this->expectException(TenantDecommissionFailedException::class);

        try {
            $this->decommissioner($repository)->decommission($doomed->getKey(), DecommissionReason::OwnerRequest);
        } finally {
            $this->assertSame(TenantStatus::Inactive, Tenant::on('landlord')->findOrFail($doomed->getKey())->status);

            try {
                $resolver->resolve($host());
                $this->fail('A tenant being removed must not be served.');
            } catch (TenantNotActiveException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_suspended_tenant_is_flipped_to_inactive_and_a_pending_one_is_not_touched(): void
    {
        $suspended = $this->tenant('susp', TenantStatus::Suspended);
        $pending   = $this->tenant('pend', TenantStatus::Pending);
        $repo      = $this->app->make(TenantRepositoryInterface::class);

        $this->assertTrue($repo->stopServing($suspended->getKey()));
        $this->assertSame(TenantStatus::Inactive, $suspended->fresh()->status);
        $this->assertFalse($repo->stopServing($pending->getKey()));
        $this->assertSame(TenantStatus::Pending, $pending->fresh()->status);
    }

    public function test_the_cache_entries_kept_for_the_tenant_are_forgotten(): void
    {
        $doomed = $this->tenant('doomed');
        $key    = config('permission.cache.key') . '.tenant.' . $doomed->getKey();
        Cache::forever($key, 'permissions');

        $forgotten               = [];
        $this->extraForgetters[] = new class ($forgotten) implements TenantCacheForgetterInterface {
            /** @param list<string> $log */
            public function __construct(public array &$log)
            {
            }

            public function forget(TenantInterface $tenant): void
            {
                $this->log[] = $tenant->getId();
            }
        };

        $this->schemas->schemas[] = $doomed->schema_name;
        $this->decommissioner()->decommission($doomed->getKey(), DecommissionReason::OwnerRequest);

        $this->assertNull(Cache::get($key));
        $this->assertSame([$doomed->getKey()], $forgotten);
    }

    public function test_the_settings_cache_of_the_tenant_is_forgotten_inside_its_context(): void
    {
        $doomed                   = $this->tenant('doomed');
        $this->schemas->schemas[] = $doomed->schema_name;
        config(['settings.cache.enabled' => true]);
        $key = 'tenant.' . $doomed->getKey() . '.settings.' . TenantSettings::cacheKey();
        Cache::forever($key, 'serialized');

        // The prefix follows the tenant context, which the decommissioner enters for the tenant being removed.
        $this->decommissioner()->decommission($doomed->getKey(), DecommissionReason::OwnerRequest);

        $this->assertNull(Cache::get($key));
    }

    public function test_support_tokens_are_removed_through_their_store(): void
    {
        $doomed = $this->tenant('doomed');
        $this->seedLeftovers($doomed);

        $this->assertSame(1, $this->app->make(SupportAccessTokenStore::class)->deleteForTenant($doomed->getKey()));
        $this->assertSame(0, $this->app->make(SupportAccessTokenStore::class)->deleteForTenant($doomed->getKey()));
    }

    private function decommissioner(?TenantRepositoryInterface $repository = null): CoreTenantDecommissioner
    {
        $repository ??= $this->app->make(TenantRepositoryInterface::class);

        $switcher = new TenantSwitcher(
            $this->app->make(TenantContextInterface::class),
            $this->schemas,
            $this->app->make(PermissionRegistrar::class),
        );

        $removal = new TenantDecommissioner(
            $repository,
            $this->schemas,
            $this->app->make(WebhookRegistryWriterInterface::class),
            $this->app->make(SupportAccessTokenStore::class),
            $switcher,
            new ChannelProviderWebhookRemover($this->app->make(ChannelRegistryInterface::class)),
            new TenantMediaPurger($this->app->make(TenantMediaDisk::class)),
            new TenantCaches([...$this->extraForgetters, $this->app->make(TenantOwnCacheForgetter::class)]),
            $this->app->make('events'),
            $this->logger ?? $this->app->make(LoggerInterface::class),
        );

        return new CoreTenantDecommissioner($repository, $removal);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function tenant(string $slug, TenantStatus $status = TenantStatus::Active, array $config = []): Tenant
    {
        return Tenant::on('landlord')->create([
            'slug'        => $slug,
            'schema_name' => 'tenant_' . str_replace('-', '_', $slug),
            'status'      => $status,
            'config'      => $config,
        ]);
    }

    private function seedLeftovers(Tenant $tenant): void
    {
        $id   = $tenant->getKey();
        $hash = 'hash-' . $tenant->slug;

        $this->app->make(WebhookRegistryWriterInterface::class)->write($hash, $tenant, 'a1', 'c1', 'telegram', 'secret');
        $this->redisKeys[] = 'webhook:' . $hash;

        DB::connection('landlord')->table('support_access_tokens')->insert([
            'id'             => (string) Str::ulid()->toRfc4122(),
            'tenant_id'      => $id,
            'token_hash'     => hash('sha256', $id),
            'operator_ref'   => 'op',
            'operator_name'  => 'Operator',
            'operator_email' => 'op@example.test',
            'expires_at'     => now()->addMinutes(5),
            'created_at'     => now(),
        ]);

        $disk = $this->app->make(TenantMediaDisk::class)->installation();
        $disk->put("tenants/{$id}/image/2026-10/ab/blob.jpg", 'bytes');
        $disk->put("tenants/{$id}/document/2026-10/cd/blob.pdf", 'bytes');
        $this->mediaDirectories[] = storage_path("app/tenants/{$id}");
    }

    private function assertGone(Tenant $tenant): void
    {
        $id   = $tenant->getKey();
        $hash = 'hash-' . $tenant->slug;

        $this->assertNull(Tenant::on('landlord')->find($id));
        $this->assertSame(0, DB::connection('landlord')->table('webhook_registry')->where('tenant_id', $id)->count());
        $this->assertSame(0, Redis::exists('webhook:' . $hash));
        $this->assertSame(0, DB::connection('landlord')->table('support_access_tokens')->where('tenant_id', $id)->count());

        $disk = $this->app->make(TenantMediaDisk::class)->installation();
        $this->assertFalse($disk->exists("tenants/{$id}/image/2026-10/ab/blob.jpg"));
        $this->assertFalse($disk->exists("tenants/{$id}/document/2026-10/cd/blob.pdf"));
        $this->assertNotContains($tenant->schema_name, $this->schemas->schemas);
    }

    private function assertKept(Tenant $tenant): void
    {
        $id = $tenant->getKey();

        $this->assertNotNull(Tenant::on('landlord')->find($id));
        $this->assertSame(1, DB::connection('landlord')->table('webhook_registry')->where('tenant_id', $id)->count());
        $this->assertSame(1, Redis::exists('webhook:hash-' . $tenant->slug));
        $this->assertSame(1, DB::connection('landlord')->table('support_access_tokens')->where('tenant_id', $id)->count());
        $this->assertTrue($this->app->make(TenantMediaDisk::class)->installation()->exists("tenants/{$id}/image/2026-10/ab/blob.jpg"));
    }

    private function channel(Assistant $assistant, string $token, bool $active): Channel
    {
        return Channel::withoutEvents(fn (): Channel => Channel::factory()->create([
            'assistant_id' => $assistant->getKey(),
            'token'        => $token,
            'is_active'    => $active,
        ]));
    }

    /**
     * @return array{0: object, 1: ChannelRegistryInterface}
     */
    private function recordingRegistrar(): array
    {
        $registrar = new class () implements WebhookRegistrarInterface {
            /** @var list<WebhookRegistrationPayload> */
            public array $deregistered = [];

            public ?RuntimeException $failWith = null;

            /** @var (callable(): void)|null */
            public $onDeregister;

            public function register(WebhookRegistrationPayload $payload): void
            {
            }

            public function deregister(WebhookRegistrationPayload $payload): void
            {
                if (null !== $this->onDeregister) {
                    ($this->onDeregister)();
                }

                if (null !== $this->failWith) {
                    throw $this->failWith;
                }

                $this->deregistered[] = $payload;
            }
        };

        $registry = Mockery::mock(ChannelRegistryInterface::class);
        $registry->shouldReceive('webhookRegistrar')->andReturn($registrar);

        return [$registrar, $registry];
    }
}
