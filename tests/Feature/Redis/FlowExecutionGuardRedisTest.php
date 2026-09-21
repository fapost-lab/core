<?php

declare(strict_types=1);

namespace Tests\Feature\Redis;

use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Contracts\FlowExecutionGuardInterface;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * {@see \App\Infrastructure\Flow\FlowExecutionGuard} exercised against a real
 * Redis instance, complementing the mocked coverage in
 * tests/Unit/Infrastructure/Flow/FlowExecutionGuardTest.php.
 *
 * config('flow.lock.*') is tightened in setUp(), before the container ever
 * resolves {@see \App\Domains\Flow\Concurrency\LockAcquisitionPolicy} (a
 * singleton) — the default 3 attempts * 2s budget would make the busy-lock
 * assertion slow and the config change would otherwise arrive too late.
 */
#[Group('redis')]
final class FlowExecutionGuardRedisTest extends TestCase
{
    use InteractsWithRedisLocks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pingRedisOrFail();

        config([
            'flow.lock.acquisition_retries' => 1,
            'flow.lock.retry_delay_ms'      => 50,
        ]);
    }

    public function test_it_acquires_and_releases_the_lock_around_the_callback(): void
    {
        $guard = $this->app->make(FlowExecutionGuardInterface::class);
        $scope = $this->uniqueLockScope();

        $result = $guard->run($scope->tenantId, $scope->contactId, $scope->assistantId, static fn (): string => 'ok');

        $this->assertSame('ok', $result);
        $this->assertNull($this->redisGet($scope->key()), 'The lock key must be gone once run() returns.');
    }

    public function test_a_nested_run_for_the_same_scope_does_not_deadlock(): void
    {
        $guard = $this->app->make(FlowExecutionGuardInterface::class);
        $scope = $this->uniqueLockScope();

        $result = $guard->run(
            $scope->tenantId,
            $scope->contactId,
            $scope->assistantId,
            fn (): mixed => $guard->run(
                $scope->tenantId,
                $scope->contactId,
                $scope->assistantId,
                static fn (): string => 'inner',
            ),
        );

        $this->assertSame('inner', $result);
        $this->assertNull($this->redisGet($scope->key()), 'The outer run() must still release the lock on the way out.');
    }

    public function test_it_throws_when_another_token_already_holds_the_scope(): void
    {
        $manager = $this->app->make(SessionLockManager::class);
        $guard   = $this->app->make(FlowExecutionGuardInterface::class);
        $scope   = $this->uniqueLockScope();

        $otherHolder = $manager->acquire($scope, ttlSeconds: 30);
        $this->assertNotNull($otherHolder);

        $this->expectException(SessionLockTimeoutException::class);

        try {
            $guard->run($scope->tenantId, $scope->contactId, $scope->assistantId, static fn (): null => null);
        } finally {
            $this->assertSame(
                $otherHolder->token,
                $this->redisGet($scope->key()),
                'The other holder\'s claim must be untouched by the failed acquisition attempt.',
            );
        }
    }

    public function test_it_releases_the_lock_when_the_callback_throws(): void
    {
        $guard = $this->app->make(FlowExecutionGuardInterface::class);
        $scope = $this->uniqueLockScope();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $guard->run($scope->tenantId, $scope->contactId, $scope->assistantId, static function (): never {
                throw new RuntimeException('boom');
            });
        } finally {
            $this->assertNull($this->redisGet($scope->key()), 'A thrown callback must still release the lock.');
        }
    }
}
