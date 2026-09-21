<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Flow\Concurrency\LockScope;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Throwable;

/**
 * Shared setup for the tests under this directory, which exercise the Flow
 * session lock against a real Redis instance rather than a mocked
 * {@see Connection} — see the mock-based suites under
 * tests/Unit/Domains/Flow/Concurrency and tests/Unit/Infrastructure/Flow for
 * the coverage this complements.
 *
 * A class that `use`s this trait and declares no setUp()/tearDown() of its
 * own gets Redis reachability checked and its tracked lock keys cleaned up
 * automatically. A class that needs extra setUp() logic (e.g. registering a
 * node handler) must call {@see pingRedisOrFail()} itself, since a
 * class-declared setUp() shadows the trait's.
 */
trait InteractsWithRedisLocks
{
    /**
     * @var list<string>
     */
    private array $trackedLockKeys = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->pingRedisOrFail();
    }

    protected function tearDown(): void
    {
        $this->cleanUpTrackedLockKeys();

        parent::tearDown();
    }

    /**
     * Fails — never skips — when Redis cannot be reached. This suite exists
     * to catch what the mocked unit tests structurally cannot, so a Redis
     * outage must show up as a failure, not a silently green skip.
     */
    protected function pingRedisOrFail(): void
    {
        $host = (string) config('database.redis.default.host');
        $port = (string) config('database.redis.default.port');

        try {
            $pong = $this->redisConnection()->ping();
        } catch (Throwable $exception) {
            $this->fail(
                "Redis is required for the redis-group test suite but is unreachable at {$host}:{$port} "
                . "({$exception->getMessage()})."
            );
        }

        if (! $pong) {
            $this->fail("Redis is required for the redis-group test suite but did not answer PING at {$host}:{$port}.");
        }
    }

    /**
     * A fresh, uniquely identified scope so concurrent test methods never
     * collide on the same Redis key. Its key is tracked for teardown cleanup.
     */
    protected function uniqueLockScope(): LockScope
    {
        $scope = new LockScope(
            tenantId: 'redis-test-' . uniqid('t-', true),
            contactId: 'redis-test-' . uniqid('c-', true),
            assistantId: 'redis-test-' . uniqid('a-', true),
        );

        $this->trackedLockKeys[] = $scope->key();

        return $scope;
    }

    /**
     * Track a key created outside {@see uniqueLockScope()} (e.g. derived from
     * model-backed ids) so tearDown deletes it too.
     */
    protected function trackLockKey(string $key): void
    {
        $this->trackedLockKeys[] = $key;
    }

    protected function redisConnection(): Connection
    {
        return Redis::connection();
    }

    protected function redisTtl(string $key): int
    {
        return (int) $this->redisConnection()->ttl($key);
    }

    protected function redisGet(string $key): ?string
    {
        $value = $this->redisConnection()->get($key);

        return null === $value ? null : (string) $value;
    }

    /**
     * Deletes only the keys this test created — never FLUSHDB/FLUSHALL, since
     * this Redis instance may be shared with other concurrent work.
     */
    protected function cleanUpTrackedLockKeys(): void
    {
        foreach (array_unique($this->trackedLockKeys) as $key) {
            $this->redisConnection()->del($key);
        }

        $this->trackedLockKeys = [];
    }
}
