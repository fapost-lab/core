<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Flow\Concurrency\LockHeartbeat;
use App\Domains\Flow\Concurrency\SessionLockManager;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * {@see SessionLockManager} exercised against a real Redis instance — no
 * mocked {@see \Illuminate\Redis\Connections\Connection}. The mocked
 * equivalents live in tests/Unit/Domains/Flow/Concurrency/SessionLockManagerTest.php;
 * this suite exists to catch anything the mock's scripted responses cannot
 * (real `SET NX EX` semantics, real Lua script execution, real TTL expiry).
 */
#[Group('redis')]
final class SessionLockManagerRedisTest extends TestCase
{
    use InteractsWithRedisLocks;

    public function test_acquire_is_exclusive_per_scope(): void
    {
        $manager = $this->app->make(SessionLockManager::class);
        $scope   = $this->uniqueLockScope();
        $other   = $this->uniqueLockScope();

        $first  = $manager->acquire($scope);
        $second = $manager->acquire($scope);
        $third  = $manager->acquire($other);

        $this->assertNotNull($first, 'First acquisition of a free scope must succeed.');
        $this->assertNull($second, 'A second acquisition of an already-held scope must fail.');
        $this->assertNotNull($third, 'A different scope must be acquirable independently.');
    }

    public function test_lock_can_be_reacquired_once_the_ttl_expires(): void
    {
        $manager = $this->app->make(SessionLockManager::class);
        $scope   = $this->uniqueLockScope();

        $this->assertNotNull($manager->acquire($scope, ttlSeconds: 1));

        usleep(1_200_000);

        $reacquired = $manager->acquire($scope, ttlSeconds: 30);

        $this->assertNotNull($reacquired, 'The lock must become acquirable again once its TTL has lapsed.');
    }

    public function test_takeover_after_ttl_expiry_invalidates_the_original_owners_handle(): void
    {
        $manager = $this->app->make(SessionLockManager::class);
        $scope   = $this->uniqueLockScope();

        $ownerA = $manager->acquire($scope, ttlSeconds: 1);
        $this->assertNotNull($ownerA);

        usleep(1_200_000);

        $ownerB = $manager->acquire($scope, ttlSeconds: 30);
        $this->assertNotNull($ownerB, 'B must be able to claim the scope once A\'s TTL lapsed.');

        $this->assertFalse($manager->release($ownerA), 'A\'s stale token must not release B\'s claim.');
        $this->assertFalse($manager->extend($ownerA), 'A\'s stale token must not extend B\'s claim.');
        $this->assertSame(
            $ownerB->token,
            $this->redisGet($scope->key()),
            'The key must still carry B\'s token after A\'s failed release/extend attempts.',
        );
    }

    public function test_extend_by_the_owner_raises_the_ttl_in_redis(): void
    {
        $manager = $this->app->make(SessionLockManager::class);
        $scope   = $this->uniqueLockScope();

        $handle = $manager->acquire($scope, ttlSeconds: 2);
        $this->assertNotNull($handle);
        $this->assertLessThanOrEqual(2, $this->redisTtl($scope->key()));

        $this->assertTrue($manager->extend($handle, 30));

        $this->assertGreaterThan(10, $this->redisTtl($scope->key()), 'extend() must raise the TTL Redis reports.');
    }

    public function test_lock_heartbeat_keeps_a_short_lock_alive_past_its_original_expiry(): void
    {
        $manager   = $this->app->make(SessionLockManager::class);
        $heartbeat = new LockHeartbeat($manager, extendToSeconds: 2);
        $scope     = $this->uniqueLockScope();

        $handle = $manager->acquire($scope, ttlSeconds: 1);
        $this->assertNotNull($handle);

        // Tick the heartbeat before the original 1s TTL lapses.
        usleep(500_000);
        $this->assertTrue($heartbeat->extend($handle));

        // Past the original expiry point — the key must still be alive
        // because the heartbeat pushed the TTL back out to 2s.
        usleep(700_000);
        $this->assertNotNull($this->redisGet($scope->key()), 'The heartbeat must keep the lock alive past its original TTL.');
    }

    public function test_force_release_removes_the_lock_regardless_of_owner(): void
    {
        $manager = $this->app->make(SessionLockManager::class);
        $scope   = $this->uniqueLockScope();

        $this->assertNotNull($manager->acquire($scope, ttlSeconds: 30));

        $manager->forceRelease($scope);

        $this->assertNull($this->redisGet($scope->key()));
    }
}
