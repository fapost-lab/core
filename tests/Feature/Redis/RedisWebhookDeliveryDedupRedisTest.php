<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Flow\Webhook\RedisWebhookDeliveryDedup;
use App\Domains\Flow\Webhook\WebhookDeliveryDedupInterface;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * {@see RedisWebhookDeliveryDedup} against a real Redis: a delivery is claimed once a day, per tenant and trigger,
 * and a released claim can be taken again.
 */
#[Group('redis')]
final class RedisWebhookDeliveryDedupRedisTest extends FeatureTestCase
{
    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = 'tenant-' . Str::lower((string) Str::ulid());
    }

    protected function tearDown(): void
    {
        foreach (['trigger-a', 'trigger-b'] as $trigger) {
            Redis::connection()->del(RedisWebhookDeliveryDedup::key($this->tenant, $trigger, 'wh:1'));
        }

        parent::tearDown();
    }

    public function test_a_delivery_is_claimed_once_within_the_window_and_only_for_its_trigger(): void
    {
        $dedup = $this->app->make(WebhookDeliveryDedupInterface::class);

        $this->assertInstanceOf(RedisWebhookDeliveryDedup::class, $dedup);
        $this->assertTrue($dedup->claim($this->tenant, 'trigger-a', 'wh:1'));
        $this->assertFalse($dedup->claim($this->tenant, 'trigger-a', 'wh:1'));
        $this->assertTrue($dedup->claim($this->tenant, 'trigger-b', 'wh:1'));

        $ttl = Redis::connection()->ttl(RedisWebhookDeliveryDedup::key($this->tenant, 'trigger-a', 'wh:1'));
        $this->assertGreaterThan(WebhookDeliveryDedupInterface::WINDOW_SECONDS - 60, $ttl);
    }

    public function test_a_released_claim_can_be_taken_again(): void
    {
        $dedup = $this->app->make(WebhookDeliveryDedupInterface::class);

        $this->assertTrue($dedup->claim($this->tenant, 'trigger-a', 'wh:1'));
        $dedup->release($this->tenant, 'trigger-a', 'wh:1');
        $this->assertTrue($dedup->claim($this->tenant, 'trigger-a', 'wh:1'));
    }
}
