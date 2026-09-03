<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Concurrency;

use App\Domains\Flow\Concurrency\LockHandle;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Tests\TestCase;

/**
 * Unit tests with a mocked Redis connection. Real Redis integration is covered
 * separately in feature tests that require the devilbox Redis container.
 */
final class SessionLockManagerTest extends SessionLockManagerTestBase
{
    public function test_acquire_returns_handle_when_set_succeeds(): void
    {
        $scope = new LockScope('t-1', 'c-1', 'a-1');
        $this->connection->expects($this->once())
            ->method('__call')
            ->with('set', $this->callback(static fn (array $args): bool => 'session_lock:t-1:c-1:a-1' === $args[0]
                && 'EX' === $args[2]
                && 30 === $args[3]
                && 'NX' === $args[4]))
            ->willReturn(true);

        $manager = new SessionLockManager($this->factory);
        $handle  = $manager->acquire($scope);

        $this->assertNotNull($handle);
        $this->assertSame('session_lock:t-1:c-1:a-1', $handle->key);
        $this->assertSame(30, $handle->ttlSeconds);
        $this->assertNotEmpty($handle->token);
    }

    public function test_acquire_returns_null_when_set_fails(): void
    {
        $scope = new LockScope('t-1', 'c-1', 'a-1');
        $this->connection->method('__call')->willReturn(false);

        $manager = new SessionLockManager($this->factory);
        $this->assertNull($manager->acquire($scope));
    }

    public function test_release_returns_true_when_token_matches(): void
    {
        $handle = new LockHandle('session_lock:t:c:a', 'tok-1', 30);
        $this->connection->expects($this->once())
            ->method('__call')
            ->with('eval', $this->callback(static fn (array $args): bool => 1 === $args[1] && 'tok-1' === $args[3]))
            ->willReturn(1);

        $manager = new SessionLockManager($this->factory);
        $this->assertTrue($manager->release($handle));
    }

    public function test_release_returns_false_when_token_drifted(): void
    {
        $handle = new LockHandle('session_lock:t:c:a', 'tok-1', 30);
        $this->connection->method('__call')->willReturn(0);

        $manager = new SessionLockManager($this->factory);
        $this->assertFalse($manager->release($handle));
    }

    public function test_extend_returns_true_when_owner_still_holds(): void
    {
        $handle = new LockHandle('session_lock:t:c:a', 'tok-1', 30);
        $this->connection->expects($this->once())
            ->method('__call')
            ->with('eval', $this->callback(static fn (array $args): bool => 'tok-1' === $args[3] && 30 === $args[4]))
            ->willReturn(1);

        $manager = new SessionLockManager($this->factory);
        $this->assertTrue($manager->extend($handle));
    }

    public function test_force_release_calls_del(): void
    {
        $scope = new LockScope('t', 'c', 'a');
        $this->connection->expects($this->once())
            ->method('__call')
            ->with('del', ['session_lock:t:c:a']);

        (new SessionLockManager($this->factory))->forceRelease($scope);
    }
}

abstract class SessionLockManagerTestBase extends TestCase
{
    protected RedisFactory $factory;
    protected Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = $this->createMock(Connection::class);
        $this->factory    = new class ($this->connection) implements RedisFactory {
            public function __construct(private readonly Connection $connection)
            {
            }

            public function connection($name = null): Connection
            {
                return $this->connection;
            }
        };
    }
}
