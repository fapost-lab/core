<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Conversation;

use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Conversation\Settings\ConversationSettings;
use App\Domains\Staff\Services\AclBootstrapService;
use App\Domains\Tenancy\Contracts\TenantContextInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Repositories\TenantRepository;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Services\TenantSwitcher;
use App\Domains\Tenancy\Settings\TenantSettings;
use App\Domains\Tenancy\ValueObjects\RuntimeTenant;
use Closure;
use Illuminate\Support\Facades\DB;
use Mockery;
use Psr\Log\NullLogger;
use Tests\Feature\FeatureTestCase;

/**
 * Logging and media download follow the tenant in context: its stored value, else the install default from config.
 * The two-schema case needs PostgreSQL and is skipped elsewhere.
 */
final class ConversationSettingsTest extends FeatureTestCase
{
    public function test_the_install_default_applies_when_nothing_is_stored(): void
    {
        config(['conversation.enabled' => true, 'conversation.media.fetch' => true]);
        $this->assertSame([true, true], $this->answers());

        config(['conversation.enabled' => false, 'conversation.media.fetch' => false]);
        $this->assertSame([false, false], $this->answers());
    }

    public function test_a_stored_value_wins_over_the_config_default(): void
    {
        config(['conversation.enabled' => true, 'conversation.media.fetch' => true]);
        $this->store(false, false);
        $this->assertSame([false, false], $this->answers());

        config(['conversation.enabled' => false, 'conversation.media.fetch' => false]);
        $this->store(true, true);
        $this->assertSame([true, true], $this->answers());
    }

    public function test_each_setting_falls_back_on_its_own(): void
    {
        config(['conversation.enabled' => true, 'conversation.media.fetch' => true]);
        $this->store(false, null);

        $this->assertSame([false, true], $this->answers());
    }

    public function test_without_a_tenant_in_context_the_default_applies(): void
    {
        config(['conversation.enabled' => false, 'conversation.media.fetch' => true]);
        $this->store(true, false);

        $this->app->make(TenantContextInterface::class)->reset();
        $this->app->forgetScopedInstances();
        $settings = $this->app->make(ConversationSettings::class);

        $this->assertFalse($this->app->make(TenantContextInterface::class)->isResolved());
        $this->assertSame([false, true], [$settings->loggingEnabled(), $settings->mediaFetchEnabled()]);
    }

    public function test_two_tenants_are_answered_independently_in_one_scope(): void
    {
        $this->requirePostgres();
        config(['tenancy.resolution' => 'host', 'conversation.enabled' => true, 'conversation.media.fetch' => true]);

        $alpha = Tenant::on('landlord')->findOrFail($this->provisionedTenant('cs-alpha'));
        $beta  = Tenant::on('landlord')->findOrFail($this->provisionedTenant('cs-beta'));
        $this->inTenant($alpha, fn () => $this->store(false, true));
        $this->inTenant($beta, fn () => $this->store(true, false));

        $this->app->forgetScopedInstances();
        $settings = $this->app->make(ConversationSettings::class);

        $this->assertSame([false, true], $this->inTenant($alpha, fn (): array => [$settings->loggingEnabled(), $settings->mediaFetchEnabled()]));
        $this->assertSame([true, false], $this->inTenant($beta, fn (): array => [$settings->loggingEnabled(), $settings->mediaFetchEnabled()]));
    }

    /**
     * @return array{0: bool, 1: bool}
     */
    private function answers(): array
    {
        $this->app->forgetScopedInstances();
        $settings = $this->app->make(ConversationSettings::class);

        return $this->inTenant(new RuntimeTenant('tenant-alpha', 'main', 'alpha'), fn (): array => [$settings->loggingEnabled(), $settings->mediaFetchEnabled()]);
    }

    private function store(?bool $logging, ?bool $media): void
    {
        $settings                           = new TenantSettings();
        $settings->conversation_logging     = $logging;
        $settings->conversation_media_fetch = $media;
        $settings->save();
    }

    private function inTenant(TenantInterface $tenant, Closure $callback): mixed
    {
        return $this->app->make(TenantSwitcher::class)->runForTenant($tenant, $callback);
    }

    private function requirePostgres(): void
    {
        if ('pgsql' !== DB::connection('landlord')->getDriverName()
            || 'pgsql' !== DB::connection((string) config('tenancy.tenant_connection'))->getDriverName()) {
            $this->markTestSkipped('Needs PostgreSQL on the landlord and tenant connections.');
        }
    }

    private function provisionedTenant(string $slug): string
    {
        $webhooks = Mockery::mock(ChannelWebhookRegistryInterface::class);
        $webhooks->shouldReceive('warmup');

        $service = new TenantProvisioningService(
            new TenantRepository(),
            $this->app->make(TenantDatabaseManagerInterface::class),
            $this->app->make(TenantSwitcher::class),
            new AclBootstrapService(),
            $webhooks,
            new TenantSlugPolicy(['webhook']),
            new NullLogger(),
        );

        return $service->provision($slug, "admin@{$slug}.test", 'S3cret-p@ss-value', 'Admin')->getId();
    }
}
