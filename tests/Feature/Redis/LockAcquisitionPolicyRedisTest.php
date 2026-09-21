<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Flow\Concurrency\LockAcquisitionPolicy;
use App\Domains\Flow\Concurrency\SessionLockManager;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * {@see LockAcquisitionPolicy} exercised against a real
 * {@see SessionLockManager} backed by real Redis, complementing the mocked
 * coverage in tests/Unit/Domains/Flow/Concurrency/LockAcquisitionPolicyTest.php.
 */
#[Group('redis')]
final class LockAcquisitionPolicyRedisTest extends TestCase
{
    use InteractsWithRedisLocks;

    public function test_succeeds_once_the_holders_lock_expires_within_the_retry_budget(): void
    {
        $manager = $this->app->make(SessionLockManager::class);
        $scope   = $this->uniqueLockScope();

        // Holder claims the scope for 1s — short enough to lapse inside the
        // 5 * 300ms retry budget below.
        $holder = $manager->acquire($scope, ttlSeconds: 1);
        $this->assertNotNull($holder);

        $policy = new LockAcquisitionPolicy($manager, maxAttempts: 5, retryDelayMs: 300, ttlSeconds: 5);

        $handle = $policy->acquireWithRetry($scope);

        $this->assertNotNull($handle, 'The policy must succeed once the holder\'s TTL lapses inside the budget.');
    }

    public function test_returns_null_when_the_holder_keeps_the_lock_for_the_whole_budget(): void
    {
        $manager = $this->app->make(SessionLockManager::class);
        $scope   = $this->uniqueLockScope();

        // Holder claims the scope for 30s — far outside the tiny retry budget below.
        $holder = $manager->acquire($scope, ttlSeconds: 30);
        $this->assertNotNull($holder);

        $policy = new LockAcquisitionPolicy($manager, maxAttempts: 2, retryDelayMs: 50, ttlSeconds: 5);

        $this->assertNull($policy->acquireWithRetry($scope));
    }
}
