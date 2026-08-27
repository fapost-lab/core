<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Concurrency;

use App\Domains\Flow\Concurrency\LockHandle;
use App\Domains\Flow\Concurrency\LockHeartbeat;
use App\Domains\Flow\Concurrency\SessionLockManager;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * The heartbeat is exercised against a real {@see SessionLockManager} driving
 * a fake Redis connection, since {@see SessionLockManager} is final per pint
 * policy and cannot be mocked directly — same pattern as
 * {@see \Tests\Unit\Domains\Flow\Concurrency\LockAcquisitionPolicyTest}.
 */
final class LockHeartbeatTest extends TestCase
{
    public function test_extend_returns_true_when_token_still_matches(): void
    {
        $heartbeat = $this->heartbeat(extendOutcome: 1);
        $handle    = new LockHandle('session_lock:t:c:a', 'tok-1', 30);

        $this->assertTrue($heartbeat->extend($handle));
    }

    public function test_extend_returns_false_when_token_has_drifted(): void
    {
        $heartbeat = $this->heartbeat(extendOutcome: 0);
        $handle    = new LockHandle('session_lock:t:c:a', 'tok-1', 30);

        $this->assertFalse($heartbeat->extend($handle));
    }

    public function test_interval_seconds_returns_the_configured_constructor_value(): void
    {
        $connection = $this->createMock(Connection::class);
        $manager    = new SessionLockManager($this->factoryFor($connection));
        $heartbeat  = new LockHeartbeat($manager, intervalSeconds: 7, extendToSeconds: 45);

        $this->assertSame(7, $heartbeat->intervalSeconds());
    }

    public function test_interval_seconds_defaults_to_ten(): void
    {
        $connection = $this->createMock(Connection::class);
        $manager    = new SessionLockManager($this->factoryFor($connection));
        $heartbeat  = new LockHeartbeat($manager);

        $this->assertSame(10, $heartbeat->intervalSeconds());
    }

    private function heartbeat(int $extendOutcome): LockHeartbeat
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->once())
            ->method('__call')
            ->willReturn($extendOutcome);

        $manager = new SessionLockManager($this->factoryFor($connection));

        return new LockHeartbeat($manager);
    }

    private function factoryFor(MockObject&Connection $connection): RedisFactory
    {
        return new class ($connection) implements RedisFactory {
            public function __construct(private readonly Connection $connection) {}

            public function connection($name = null): Connection
            {
                return $this->connection;
            }
        };
    }
}
