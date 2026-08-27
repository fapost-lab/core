<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Flow;

use App\Domains\Flow\Concurrency\LockAcquisitionPolicy;
use App\Domains\Flow\Concurrency\LockHandle;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use App\Domains\Flow\Concurrency\SessionLockRegistry;
use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Infrastructure\Flow\FlowExecutionGuard;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Tests\TestCase;

/**
 * The guard is exercised against a real {@see SessionLockManager} driving a
 * fake Redis connection, since {@see SessionLockManager} is final per pint
 * policy and cannot be mocked directly — same pattern as
 * {@see \Tests\Unit\Domains\Flow\Concurrency\LockAcquisitionPolicyTest}.
 */
final class FlowExecutionGuardTest extends TestCase
{
    public function test_it_executes_callback_and_returns_result_when_lock_is_acquired(): void
    {
        $connection = $this->connectionAcquiring(true);
        [$guard, , $registry] = $this->guard($connection);

        $result = $guard->run('tenant-1', 'contact-1', 'assistant-1', static fn (): string => 'ok');

        $this->assertSame('ok', $result);
        $this->assertNull($registry->current());
    }

    public function test_it_throws_when_lock_is_busy(): void
    {
        $connection = $this->connectionAcquiring(false);
        [$guard] = $this->guard($connection);

        $this->expectException(SessionLockTimeoutException::class);

        $guard->run('tenant-1', 'contact-1', 'assistant-1', static fn (): null => null);
    }

    public function test_it_releases_lock_when_callback_throws(): void
    {
        $connection = $this->connectionAcquiring(true);
        [$guard, , $registry] = $this->guard($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        try {
            $guard->run('tenant-1', 'contact-1', 'assistant-1', static function (): never {
                throw new RuntimeException('boom');
            });
        } finally {
            $this->assertNull($registry->current());
        }
    }

    public function test_it_skips_acquisition_and_reuses_the_existing_handle_for_the_same_scope(): void
    {
        // Connection asserts it is never touched: the registry already holds
        // the lock for this scope (routing pipeline acquired it upstream), so
        // the guard must pass straight through instead of re-acquiring.
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('__call');

        [$guard, , $registry] = $this->guard($connection);

        $handle = new LockHandle((new LockScope('tenant-1', 'contact-1', 'assistant-1'))->key(), 'token-outer', 30);
        $registry->set($handle);

        $result = $guard->run('tenant-1', 'contact-1', 'assistant-1', static fn (): string => 'ok');

        $this->assertSame('ok', $result);
        // Re-entrant path never touches the registry — the outer caller
        // (whoever set the handle) remains responsible for releasing it.
        $this->assertSame($handle, $registry->current());
    }

    public function test_it_acquires_its_own_lock_when_registry_holds_a_different_scope(): void
    {
        $connection = $this->connectionAcquiring(true);
        [$guard, , $registry] = $this->guard($connection);

        $outerHandle = new LockHandle((new LockScope('tenant-1', 'contact-A', 'assistant-1'))->key(), 'token-outer', 30);
        $registry->set($outerHandle);

        $result = $guard->run('tenant-1', 'contact-B', 'assistant-1', static fn (): string => 'ok');

        $this->assertSame('ok', $result);
        // The guard took its own lock for the unrelated scope, then handed the
        // single registry slot back to the outer claim. Clearing it instead
        // would silently stop the engine heartbeating the outer lock while the
        // outer caller still believes it is held.
        $this->assertSame($outerHandle, $registry->current());
    }

    /**
     * @return array{0: FlowExecutionGuard, 1: SessionLockManager, 2: SessionLockRegistry}
     */
    private function guard(MockObject&Connection $connection): array
    {
        $manager  = new SessionLockManager($this->factoryFor($connection));
        $policy   = new LockAcquisitionPolicy($manager, retryDelayMs: 0);
        $registry = new SessionLockRegistry();

        return [new FlowExecutionGuard($policy, $manager, $registry), $manager, $registry];
    }

    private function connectionAcquiring(bool $acquired): MockObject&Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('__call')
            ->willReturnCallback(static fn (string $method): mixed => match ($method) {
                'set'   => $acquired,
                'eval'  => 1, // release script: token matches, deletes the key.
                default => null,
            });

        return $connection;
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
