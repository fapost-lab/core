<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Webhook;

use App\Domains\Contact\Enums\PlatformEnum;
use App\Domains\Webhook\Exceptions\WebhookRegistryException;
use App\Domains\Webhook\Services\WebhookRegistryResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

final class WebhookRegistryResolverTest extends TestCase
{
    private WebhookRegistryResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = $this->app->make(WebhookRegistryResolver::class);
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

        DB::shouldReceive('connection')
            ->with('landlord')
            ->andReturnSelf();
        DB::shouldReceive('table')
            ->with('webhook_registry')
            ->andReturnSelf();
        DB::shouldReceive('where')
            ->with('webhook_public_hash', 'no-such-hash')
            ->andReturnSelf();
        DB::shouldReceive('first')->andReturn(null);

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

        DB::shouldReceive('connection')->with('landlord')->andReturnSelf();
        DB::shouldReceive('table')->with('webhook_registry')->andReturnSelf();
        DB::shouldReceive('where')->with('webhook_public_hash', 'cold-hash')->andReturnSelf();
        DB::shouldReceive('first')->andReturn($row);

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

    public function test_non_leader_retries_redis_and_returns_after_warmup(): void
    {
        // Non-leader: lock already taken
        Redis::shouldReceive('get')
            ->with('webhook:popular-hash')
            ->andReturn(null, null, json_encode([
                'tenant_id'    => 'tenant-3',
                'assistant_id' => 'assist-3',
                'channel_id'   => 'chan-3',
                'schema'       => 'main',
                'channel'      => 'telegram',
                'secret_token' => 'secret-3',
            ], JSON_THROW_ON_ERROR));

        // Lock NX fails — someone else is leader
        Redis::shouldReceive('set')
            ->with('warming:popular-hash', '1', 'EX', 5, 'NX')
            ->andReturn(false)
            ->once();

        $entry = $this->resolver->resolve('popular-hash');

        $this->assertSame('tenant-3', $entry->tenantId);
    }
}
