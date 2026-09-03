<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Concurrency;

use App\Domains\Flow\Concurrency\LockAcquisitionPolicy;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockManager;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use Tests\TestCase;

/**
 * Acquisition policy is exercised against a real {@see SessionLockManager}
 * driving a fake Redis connection that returns scripted acquire outcomes.
 * SessionLockManager is final per pint policy, so mocking goes one level
 * deeper at the connection boundary.
 */
final class LockAcquisitionPolicyTest extends TestCase
{
    public function test_returns_handle_when_first_attempt_succeeds(): void
    {
        $policy = $this->policy(acquireOutcomes: [true]);

        $this->assertNotNull($policy->acquireWithRetry(new LockScope('t', 'c', 'a')));
    }

    public function test_retries_until_acquisition_succeeds(): void
    {
        $policy = $this->policy(acquireOutcomes: [false, false, true]);

        $this->assertNotNull($policy->acquireWithRetry(new LockScope('t', 'c', 'a')));
    }

    public function test_returns_null_after_exhausting_retries(): void
    {
        $policy = $this->policy(acquireOutcomes: [false, false, false]);

        $this->assertNull($policy->acquireWithRetry(new LockScope('t', 'c', 'a')));
    }

    /**
     * Build a policy backed by a real SessionLockManager whose Redis client
     * returns the supplied outcomes for each `set NX EX` call.
     *
     * @param  list<bool>  $acquireOutcomes
     */
    private function policy(array $acquireOutcomes): LockAcquisitionPolicy
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('__call')
            ->willReturnCallback(static function (string $method, array $args) use (&$acquireOutcomes): bool {
                if ('set' !== $method) {
                    return false;
                }
                /** @var bool $outcome */
                $outcome = array_shift($acquireOutcomes) ?? false;

                return $outcome;
            });

        $factory = $this->factoryFor($connection);

        return new LockAcquisitionPolicy(new SessionLockManager($factory), retryDelayMs: 0);
    }

    private function factoryFor(MockObject&Connection $connection): RedisFactory
    {
        return new class ($connection) implements RedisFactory {
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
