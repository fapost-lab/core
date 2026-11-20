<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Flow\Webhook\RedisWebhookWaitSlots;
use App\Domains\Flow\Webhook\WebhookWaitSlotsInterface;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * {@see RedisWebhookWaitSlots} against a real Redis: the installation and the tenant ceilings hold together, a slot
 * given back frees its place on both, and a slot nobody gave back lapses.
 */
#[Group('redis')]
final class RedisWebhookWaitSlotsRedisTest extends FeatureTestCase
{
    private string $tenantA;

    private string $tenantB;

    /** @var list<array{string, string}> */
    private array $taken = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenantA = 'tenant-' . Str::lower((string) Str::ulid());
        $this->tenantB = 'tenant-' . Str::lower((string) Str::ulid());
        Redis::connection()->del(RedisWebhookWaitSlots::INSTALLATION_KEY);
    }

    protected function tearDown(): void
    {
        $slots = $this->app->make(WebhookWaitSlotsInterface::class);

        foreach ($this->taken as [$tenant, $slot]) {
            $slots->release($tenant, $slot);
        }

        Redis::connection()->del(
            RedisWebhookWaitSlots::INSTALLATION_KEY,
            RedisWebhookWaitSlots::tenantKey($this->tenantA),
            RedisWebhookWaitSlots::tenantKey($this->tenantB),
        );

        parent::tearDown();
    }

    public function test_a_tenant_takes_no_more_than_its_ceiling_and_the_installation_no_more_than_its_own(): void
    {
        $slots = $this->app->make(WebhookWaitSlotsInterface::class);
        $this->assertInstanceOf(RedisWebhookWaitSlots::class, $slots);

        $this->assertNotNull($a1 = $this->take($this->tenantA, 3, 2));
        $this->assertNotNull($this->take($this->tenantA, 3, 2));
        $this->assertNull($this->take($this->tenantA, 3, 2), 'The tenant ceiling.');

        $this->assertNotNull($this->take($this->tenantB, 3, 2));
        $this->assertNull($this->take($this->tenantB, 3, 2), 'The installation ceiling, though tenant B has room.');

        // A slot given back frees its place on both levels.
        $slots->release($this->tenantA, $a1);
        $this->assertNotNull($this->take($this->tenantB, 3, 2));
    }

    public function test_a_slot_nobody_gave_back_lapses(): void
    {
        $past = (int) floor(microtime(true) * 1000) - 1000;

        // A slot a crashed request left behind, already past its time, on both levels.
        Redis::connection()->zadd(RedisWebhookWaitSlots::INSTALLATION_KEY, $past, 'leaked');
        Redis::connection()->zadd(RedisWebhookWaitSlots::tenantKey($this->tenantA), $past, 'leaked');

        $this->assertNotNull($this->take($this->tenantA, 1, 1));
        $this->assertSame(1, (int) Redis::connection()->zcard(RedisWebhookWaitSlots::INSTALLATION_KEY));
    }

    private function take(string $tenant, int $installationMax, int $tenantMax): ?string
    {
        $slot = $this->app->make(WebhookWaitSlotsInterface::class)->acquire($tenant, $installationMax, $tenantMax, 30);

        if (null !== $slot) {
            $this->taken[] = [$tenant, $slot];
        }

        return $slot;
    }
}
