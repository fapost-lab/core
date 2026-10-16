<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Tenancy;

use App\Domains\Tenancy\Contracts\CoreBootstrapInterface;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Closure;
use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher as Events;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Mockery\MockInterface;
use Spatie\LaravelSettings\SettingsContainer;
use Spatie\LaravelSettings\SettingsEventSubscriber;
use Tests\Support\PerTenantSettingsRepository;
use Tests\Support\PerTenantSettingsStore;
use Tests\TestCase;

/**
 * TenantSettings is cached by spatie under a key that must carry the tenant, and its scoped instance
 * must not follow a switch into another tenant. The repository keeps one bucket per tenant in memory,
 * and the cache is the array store, so the whole thing runs without a database.
 */
final class TenantSettingsCacheIsolationTest extends TestCase
{
    private PerTenantSettingsStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mock(TenantDatabaseManagerInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('switchTo');
            $mock->shouldReceive('restore');
        });
        $this->mock(CoreBootstrapInterface::class, function (MockInterface $mock): void {
            $mock->shouldReceive('reset');
        });

        $this->store = new PerTenantSettingsStore();
        $this->store->seed('tenant-a', 'tenant', $this->payloads('de', 11));
        $this->store->seed('tenant-b', 'tenant', $this->payloads('fr', 22));
        $this->app->instance(PerTenantSettingsStore::class, $this->store);

        $this->enableSettingsCache();
    }

    public function test_two_tenants_read_only_their_own_settings_in_one_process(): void
    {
        $switcher = $this->app->make(TenantSwitcher::class);

        $first  = $switcher->runForTenant($this->tenant('a'), fn (): array => $this->snapshot());
        $second = $switcher->runForTenant($this->tenant('b'), fn (): array => $this->snapshot());

        $this->assertSame(['de', 11], $first);
        $this->assertSame(['fr', 22], $second);
    }

    public function test_second_read_of_each_tenant_comes_from_its_own_cache_entry(): void
    {
        $switcher = $this->app->make(TenantSwitcher::class);

        $switcher->runForTenant($this->tenant('a'), fn (): array => $this->snapshot());
        $switcher->runForTenant($this->tenant('b'), fn (): array => $this->snapshot());

        $keys = array_keys($this->arrayStoreContents());
        $this->assertCount(1, array_filter($keys, static fn (string $key): bool => str_contains($key, 'tenant.tenant-a.')));
        $this->assertCount(1, array_filter($keys, static fn (string $key): bool => str_contains($key, 'tenant.tenant-b.')));

        // Drop the database side: only the cache can still answer.
        $this->store->seed('tenant-a', 'tenant', []);
        $this->store->seed('tenant-b', 'tenant', []);

        $again = [
            $switcher->runForTenant($this->tenant('a'), fn (): array => $this->snapshot()),
            $switcher->runForTenant($this->tenant('b'), fn (): array => $this->snapshot()),
        ];

        $this->assertSame([['de', 11], ['fr', 22]], $again);
    }

    public function test_a_job_switching_from_one_tenant_to_another_never_sees_the_other_ones_settings(): void
    {
        $seen = $this->app->make(Dispatcher::class)->dispatchSync(new SwitchTenantsJob(
            $this->tenant('a'),
            $this->tenant('b'),
            fn (): array => $this->snapshot(),
        ));

        $this->assertSame([['de', 11], ['fr', 22]], $seen);
    }

    public function test_the_outer_tenant_sees_its_own_settings_again_after_a_nested_switch(): void
    {
        $switcher = $this->app->make(TenantSwitcher::class);
        $seen     = [];

        $switcher->runForTenant($this->tenant('a'), function () use ($switcher, &$seen): void {
            $seen[] = $this->snapshot();

            $switcher->runForTenant($this->tenant('b'), function () use (&$seen): void {
                $seen[] = $this->snapshot();
            });

            $seen[] = $this->snapshot();
        });

        $this->assertSame([['de', 11], ['fr', 22], ['de', 11]], $seen);
    }

    public function test_settings_bound_before_a_switch_stay_bound_through_it(): void
    {
        // Built once before the fake replaces it, as a test's setUp may do.
        $this->app->make(TenantSettings::class);
        $fake     = TenantSettings::fake($this->payloads('uk', 7));
        $switcher = $this->app->make(TenantSwitcher::class);

        $seen = [
            $switcher->runForTenant($this->tenant('a'), fn (): array => $this->snapshot()),
            $switcher->runForTenant($this->tenant('b'), fn (): array => $this->snapshot()),
        ];

        $this->assertSame([['uk', 7], ['uk', 7]], $seen);
        $this->assertSame($fake, $this->app->make(TenantSettings::class));
    }

    public function test_saving_in_one_tenant_changes_only_its_own_cached_entry(): void
    {
        $switcher = $this->app->make(TenantSwitcher::class);

        $switcher->runForTenant($this->tenant('b'), fn (): array => $this->snapshot());
        $switcher->runForTenant($this->tenant('a'), function (): void {
            $settings                       = $this->app->make(TenantSettings::class);
            $settings->messaging_rate_limit = 99;
            $settings->save();
        });

        // Both read from the cache: the repository is emptied so that nothing else can answer.
        $this->store->seed('tenant-a', 'tenant', []);
        $this->store->seed('tenant-b', 'tenant', []);

        $this->assertSame(['de', 99], $switcher->runForTenant($this->tenant('a'), fn (): array => $this->snapshot()));
        $this->assertSame(['fr', 22], $switcher->runForTenant($this->tenant('b'), fn (): array => $this->snapshot()));
    }

    public function test_without_a_tenant_the_cache_key_carries_the_landlord_prefix(): void
    {
        $this->assertFalse($this->app->make(TenantContextInterface::class)->isResolved());

        $this->snapshot();

        $keys = array_keys($this->arrayStoreContents());
        $this->assertCount(1, $keys);
        $this->assertStringContainsString('landlord.settings.', $keys[0]);
    }

    /**
     * The settings cache is built from config when the container registers the bindings and the event
     * subscriber, both before a test can set anything: turn it on and register them again.
     */
    private function enableSettingsCache(): void
    {
        config([
            'settings.cache.enabled'           => true,
            'settings.cache.store'             => 'array',
            'settings.repositories.per_tenant' => ['type' => PerTenantSettingsRepository::class],
            'settings.default_repository'      => 'per_tenant',
        ]);

        $this->app->make(SettingsContainer::class)->registerBindings();
        $this->app->make(Events::class)->subscribe(SettingsEventSubscriber::class);
    }

    /**
     * A complete set of stored values: saving refuses a settings class with properties missing.
     *
     * @return array<string, mixed>
     */
    private function payloads(string $language, int $rateLimit): array
    {
        return [
            'messaging_rate_limit'   => $rateLimit,
            'broadcast_chunk_size'   => 100,
            'broadcast_backpressure' => true,
            'flow_session_ttl'       => 86400,
            'max_retry_attempts'     => 3,
            'flow_fallback_message'  => 'An error occurred.',
            'webhook_timeout'        => 10,
            'webhook_rate_limit'     => 60,
            'content_base_language'  => 'en',
            'available_languages'    => ['en'],
            'fallback_language'      => $language,
        ];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function snapshot(): array
    {
        $settings = $this->app->make(TenantSettings::class);

        return [$settings->fallback_language, $settings->messaging_rate_limit];
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayStoreContents(): array
    {
        $store = Cache::store('array')->getStore();
        $this->assertInstanceOf(ArrayStore::class, $store);

        return $store->all();
    }

    private function tenant(string $name): RuntimeTenant
    {
        return new RuntimeTenant(id: "tenant-{$name}", schemaName: "tenant_{$name}");
    }
}

/**
 * Switches from one tenant to another inside a single queued job, as a job serving several tenants does.
 */
final class SwitchTenantsJob implements ShouldQueue
{
    use Dispatchable;

    /**
     * @param  Closure(): array{0: string, 1: int}  $read
     */
    public function __construct(
        private readonly RuntimeTenant $first,
        private readonly RuntimeTenant $second,
        private readonly Closure $read,
    ) {
    }

    /**
     * @return list<array{0: string, 1: int}>
     */
    public function handle(TenantSwitcher $switcher): array
    {
        return [
            $switcher->runForTenant($this->first, $this->read),
            $switcher->runForTenant($this->second, $this->read),
        ];
    }
}
