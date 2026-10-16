<?php

declare(strict_types=1);

namespace Tests\Feature\Domains\Messaging;

use App\Domains\Channels\Contracts\ChannelRegistryInterface;
use App\Domains\Channels\Contracts\ChannelWebhookRegistryInterface;
use App\Domains\Conversation\Capture\ConversationCaptureFactory;
use App\Domains\Conversation\Contracts\ConversationLoggerInterface;
use App\Domains\Messaging\Exceptions\RateLimitExceededException;
use App\Domains\Messaging\MessageSender;
use App\Domains\Messaging\TenantMessagingRateLimit;
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
use Fapost\Foundation\Messaging\DeliveryResult;
use Fapost\Foundation\Messaging\MessagePayload;
use Fapost\Foundation\Messaging\OutboundMessage;
use Fapost\Foundation\Messaging\ProviderSenderInterface;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\DB;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\Feature\FeatureTestCase;
use Tests\Support\UsageGates;

/**
 * The per-chat send limit follows the tenant in whose context the send runs, and the setting is read afresh by
 * every job. The two-schema case needs PostgreSQL (SQLite has one database for every tenant) and is skipped
 * elsewhere; the rest runs on the default tenant's settings table.
 */
final class TenantMessagingRateLimitTest extends FeatureTestCase
{
    private RuntimeTenant $alpha;

    private RuntimeTenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = new RuntimeTenant('tenant-alpha', 'main', 'alpha');
        $this->beta  = new RuntimeTenant('tenant-beta', 'main', 'beta');
    }

    public function test_two_tenants_are_throttled_independently_in_one_process_and_across_jobs(): void
    {
        $this->requirePostgres();
        config(['tenancy.resolution' => 'host']);

        $alpha = Tenant::on('landlord')->findOrFail($this->provisionedTenant('rl-alpha'));
        $beta  = Tenant::on('landlord')->findOrFail($this->provisionedTenant('rl-beta'));
        $this->inTenant($alpha, fn () => $this->storeLimit(2));
        $this->inTenant($beta, fn () => $this->storeLimit(5));

        $counters = [];

        // One scope: the sender is built once and used for both tenants.
        $this->app->forgetScopedInstances();
        $sender = $this->sender($counters);
        $this->assertSame(
            [2, 5],
            [$this->inTenant($alpha, fn (): int => $this->sentBeforeLimit($sender, 'alpha')), $this->inTenant($beta, fn (): int => $this->sentBeforeLimit($sender, 'beta'))],
        );

        // Consecutive jobs on one worker: the scope is dropped between them.
        $counters = [];
        $this->app->forgetScopedInstances();
        $this->assertSame(2, $this->inTenant($alpha, fn (): int => $this->sentBeforeLimit($this->sender($counters), 'alpha')));
        $this->app->forgetScopedInstances();
        $this->assertSame(5, $this->inTenant($beta, fn (): int => $this->sentBeforeLimit($this->sender($counters), 'beta')));
    }

    public function test_the_memo_is_kept_per_tenant_id_inside_one_scope(): void
    {
        $limit = $this->app->make(TenantMessagingRateLimit::class);

        $this->assertSame(30, $this->inTenant($this->alpha, fn (): int => $limit->perMinute()));

        $this->storeLimit(5);

        $this->assertSame(5, $this->inTenant($this->beta, fn (): int => $limit->perMinute()), 'Another tenant is read on its own.');
        $this->assertSame(30, $this->inTenant($this->alpha, fn (): int => $limit->perMinute()), 'The first tenant stays memoised for the scope.');
    }

    public function test_a_changed_setting_takes_effect_for_the_next_job(): void
    {
        $counters = [];

        $this->assertSame(3, $this->inTenant($this->alpha, function () use (&$counters): int {
            $this->storeLimit(3);

            return $this->sentBeforeLimit($this->sender($counters), 'alpha');
        }));

        $this->storeLimit(7);
        $this->app->forgetScopedInstances();
        $counters = [];

        $this->assertSame(7, $this->inTenant($this->alpha, fn (): int => $this->sentBeforeLimit($this->sender($counters), 'alpha')));
    }

    public function test_the_configured_default_applies_without_a_stored_value_and_outside_a_tenant(): void
    {
        config(['messaging.rate_limit_per_minute' => 11]);

        DB::table('settings')->where('group', 'tenant')->where('name', 'messaging_rate_limit')->delete();

        $limit = $this->app->make(TenantMessagingRateLimit::class);

        $this->assertSame(11, $this->inTenant($this->alpha, fn (): int => $limit->perMinute()));

        $this->assertFalse($this->app->make(TenantContextInterface::class)->isResolved());
        $this->assertSame(11, $limit->perMinute());
    }

    private function storeLimit(int $perMinute): void
    {
        $settings                       = new TenantSettings();
        $settings->messaging_rate_limit = $perMinute;
        $settings->save();
    }

    /**
     * Sends to one chat until the limit refuses, and returns how many messages went out.
     */
    private function sentBeforeLimit(MessageSender $sender, string $channelId): int
    {
        $sent = 0;

        for ($index = 0; $index < 20; $index++) {
            try {
                $sender->send(new OutboundMessage(
                    idempotencyKey: "{$channelId}-{$index}",
                    tenantId: 'tenant',
                    channelId: $channelId,
                    channelType: 'telegram',
                    transportToken: 'bot-token',
                    chatId: 'chat-1',
                    payload: new MessagePayload(type: 'text', text: 'Hello'),
                ));
            } catch (RateLimitExceededException) {
                return $sent;
            }

            $sent++;
        }

        return $sent;
    }

    /**
     * The real sender with the real, scoped rate limit; Redis is an in-memory counter shared through $counters.
     *
     * @param  array<string, int>  $counters
     */
    private function sender(array &$counters): MessageSender
    {
        $provider = new class () implements ProviderSenderInterface {
            public function deliver(OutboundMessage $message): DeliveryResult
            {
                return new DeliveryResult(sent: true, providerMessageId: 'provider-message-1');
            }
        };

        $registry = $this->mock(ChannelRegistryInterface::class, function (MockInterface $mock) use ($provider): void {
            $mock->shouldReceive('sender')->andReturn($provider);
        });

        $connection = $this->mock(Connection::class, function (MockInterface $mock) use (&$counters): void {
            $mock->shouldReceive('set')->andReturn(true);
            $mock->shouldReceive('del');
            $mock->shouldReceive('pipeline')->andReturnUsing(function (Closure $callback) use (&$counters): array {
                $last = 0;
                $pipe = Mockery::mock();
                $pipe->shouldReceive('incr')->andReturnUsing(function (string $key) use (&$counters, &$last): void {
                    $counters[$key] = ($counters[$key] ?? 0) + 1;
                    $last           = $counters[$key];
                });
                $pipe->shouldReceive('expire');
                $callback($pipe);

                return [$last, true];
            });
        });

        $redis = $this->mock(RedisFactory::class, function (MockInterface $mock) use ($connection): void {
            $mock->shouldReceive('connection')->andReturn($connection);
        });

        return new MessageSender(
            $registry,
            $redis,
            $this->app->make(TenantMessagingRateLimit::class),
            $this->mock(ConversationLoggerInterface::class, function (MockInterface $mock): void {
                $mock->shouldReceive('log');
            }),
            new ConversationCaptureFactory(),
            UsageGates::gate(),
        );
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
