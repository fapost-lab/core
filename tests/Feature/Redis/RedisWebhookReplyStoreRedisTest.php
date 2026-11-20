<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Flow\Webhook\RedisWebhookReplyStore;
use App\Domains\Flow\Webhook\WebhookReply;
use App\Domains\Flow\Webhook\WebhookReplyStoreInterface;
use App\Domains\Flow\Webhook\WebhookRunEnd;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\FeatureTestCase;

/**
 * {@see RedisWebhookReplyStore} against a real Redis: the first answer of a run is kept, wakes the waiting request even
 * when it came first, and expires; a wait with no answer gives up.
 */
#[Group('redis')]
final class RedisWebhookReplyStoreRedisTest extends FeatureTestCase
{
    private string $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = 'tenant-' . Str::lower((string) Str::ulid());
    }

    protected function tearDown(): void
    {
        foreach (['wh:1', 'wh:2'] as $fireKey) {
            Redis::connection()->del(
                RedisWebhookReplyStore::key($this->tenant, 'trigger-a', $fireKey),
                RedisWebhookReplyStore::wakeKey($this->tenant, 'trigger-a', $fireKey),
            );
        }

        parent::tearDown();
    }

    public function test_the_first_answer_is_kept_and_wakes_a_wait_that_starts_after_it(): void
    {
        $store = $this->app->make(WebhookReplyStoreInterface::class);
        $this->assertInstanceOf(RedisWebhookReplyStore::class, $store);

        $this->assertTrue($store->keep($this->tenant, 'TRIGGER-A', 'wh:1', new WebhookReply(201, '{"a":1}', WebhookReply::TYPE_JSON)));
        $this->assertFalse($store->keep($this->tenant, 'trigger-a', 'wh:1', WebhookReply::forRunEnd(WebhookRunEnd::Completed)));

        $started = microtime(true);
        $reply   = $store->await($this->tenant, 'trigger-a', 'wh:1', 5);

        $this->assertLessThan(2.0, microtime(true) - $started, 'An answer delivered first leaves its wake-up behind.');
        $this->assertNotNull($reply);
        $this->assertSame(201, $reply->status);
        $this->assertSame('{"a":1}', $reply->body);
        $this->assertSame(WebhookReply::TYPE_JSON, $reply->type);

        // Still kept for a repeated delivery, for at most a minute.
        $this->assertSame(201, $store->find($this->tenant, 'trigger-a', 'wh:1')?->status);
        $ttl = Redis::connection()->ttl(RedisWebhookReplyStore::key($this->tenant, 'trigger-a', 'wh:1'));
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(WebhookReplyStoreInterface::TTL_SECONDS, $ttl);
    }

    public function test_a_wait_without_an_answer_gives_up_after_its_seconds(): void
    {
        $store = $this->app->make(WebhookReplyStoreInterface::class);

        $started = microtime(true);

        $this->assertNull($store->await($this->tenant, 'trigger-a', 'wh:2', 1));
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $started);
        $this->assertNull($store->find($this->tenant, 'trigger-a', 'wh:2'));
    }
}
