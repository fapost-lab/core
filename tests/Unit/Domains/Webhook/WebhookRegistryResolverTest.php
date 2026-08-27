<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Tenancy\Contracts\WebhookRegistryReaderInterface;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;
use App\Domains\Webhook\Services\WebhookRegistryResolver;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class WebhookRegistryResolverTest extends TestCase
{
    private WebhookRegistryResolver $resolver;

    private MockInterface $reader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reader = Mockery::mock(WebhookRegistryReaderInterface::class);
        $this->app->instance(WebhookRegistryReaderInterface::class, $this->reader);

        $this->resolver = $this->app->make(WebhookRegistryResolver::class);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_entry_on_redis_hit(): void
    {
        Redis::shouldReceive('get')
            ->once()
            ->with('webhook:abc123')
            ->andReturn(json_encode([
                'tenant_id'    => 'tenant-1',
                'assistant_id' => 'assist-1',
                'channel_id'   => 'chan-1',
                'schema'       => 'main',
                'channel'      => 'telegram',
                'secret_token' => 'secret',
            ], JSON_THROW_ON_ERROR));

        $this->reader->shouldNotReceive('findByHash');

        $entry = $this->resolver->resolve('abc123');

        $this->assertSame('tenant-1', $entry->tenantId);
        $this->assertSame(PlatformEnum::Telegram, $entry->platform);
    }

    public function test_throws_when_hash_not_in_redis_or_landlord(): void
    {
        Redis::shouldReceive('get')->with('webhook:no-such-hash')->andReturn(null);
        Redis::shouldReceive('set')
            ->once()
            ->with('warming:no-such-hash', '1', 'EX', 5, 'NX')
            ->andReturn(true);
        Redis::shouldReceive('del')->once()->with('warming:no-such-hash');

        $this->reader->shouldReceive('findByHash')->with('no-such-hash')->andReturn(null);

        $this->expectException(WebhookRegistryException::class);

        $this->resolver->resolve('no-such-hash');
    }

    public function test_falls_back_to_landlord_and_self_heals_redis_on_cache_miss(): void
    {
        $row = (object) [
            'tenant_id'    => 'tenant-2',
            'assistant_id' => 'assist-2',
            'channel_id'   => 'chan-2',
            'schema'       => 'tenant_2',
            'platform'     => 'telegram',
            'secret_token' => 'secret-2',
        ];

        Redis::shouldReceive('get')->with('webhook:cold-hash')->andReturn(null)->once();
        Redis::shouldReceive('set')
            ->once()
            ->with('warming:cold-hash', '1', 'EX', 5, 'NX')
            ->andReturn(true);

        $this->reader->shouldReceive('findByHash')->with('cold-hash')->andReturn($row);

        // Self-heal: Redis should be repopulated
        Redis::shouldReceive('set')
            ->once()
            ->withArgs(fn (string $key): bool => 'webhook:cold-hash' === $key);

        Redis::shouldReceive('del')->once()->with('warming:cold-hash');

        $entry = $this->resolver->resolve('cold-hash');

        $this->assertSame('tenant-2', $entry->tenantId);
        $this->assertSame('chan-2', $entry->channelId);
        $this->assertSame(PlatformEnum::Telegram, $entry->platform);
    }

    public function test_non_leader_does_own_db_lookup_without_deleting_lock(): void
    {
        // Non-leader: Redis miss, lock already held by another worker
        Redis::shouldReceive('get')->with('webhook:popular-hash')->andReturn(null)->once();
        Redis::shouldReceive('set')
            ->with('warming:popular-hash', '1', 'EX', 5, 'NX')
            ->andReturn(false) // lock not acquired — someone else is leader
            ->once();

        $row = (object) [
            'tenant_id'    => 'tenant-3',
            'assistant_id' => 'assist-3',
            'channel_id'   => 'chan-3',
            'schema'       => 'main',
            'platform'     => 'telegram',
            'secret_token' => 'secret-3',
        ];

        $this->reader->shouldReceive('findByHash')->with('popular-hash')->andReturn($row);

        // Self-heal write
        Redis::shouldReceive('set')
            ->once()
            ->withArgs(fn (string $key): bool => 'webhook:popular-hash' === $key);

        // Non-leader must NOT delete the leader's lock
        Redis::shouldNotReceive('del');

        $entry = $this->resolver->resolve('popular-hash');

        $this->assertSame('tenant-3', $entry->tenantId);
    }
}
