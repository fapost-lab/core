<?php

declare(strict_types=1);

namespace Tests\Unit\Domains\Flow\Concurrency;

use App\Domains\Flow\Concurrency\LockHandle;
use App\Domains\Flow\Concurrency\LockScope;
use App\Domains\Flow\Concurrency\SessionLockRegistry;
use Tests\TestCase;

final class SessionLockRegistryTest extends TestCase
{
    public function test_current_is_null_before_any_handle_is_set(): void
    {
        $registry = new SessionLockRegistry();

        $this->assertNull($registry->current());
    }

    public function test_current_returns_the_handle_after_set(): void
    {
        $registry = new SessionLockRegistry();
        $handle   = new LockHandle('session_lock:t:c:a', 'tok-1', 30);

        $registry->set($handle);

        $this->assertSame($handle, $registry->current());
    }

    public function test_current_is_null_after_clear(): void
    {
        $registry = new SessionLockRegistry();
        $registry->set(new LockHandle('session_lock:t:c:a', 'tok-1', 30));

        $registry->clear();

        $this->assertNull($registry->current());
    }

    public function test_clear_is_idempotent_when_nothing_is_held(): void
    {
        $registry = new SessionLockRegistry();

        $registry->clear();

        $this->assertNull($registry->current());
    }

    public function test_holds_is_true_for_the_same_scope(): void
    {
        $registry = new SessionLockRegistry();
        $scope    = new LockScope('t', 'c', 'a');
        $registry->set(new LockHandle($scope->key(), 'tok-1', 30));

        $this->assertTrue($registry->holds($scope));
    }

    public function test_holds_is_false_for_a_different_scope(): void
    {
        $registry = new SessionLockRegistry();
        $registry->set(new LockHandle((new LockScope('t', 'c', 'a'))->key(), 'tok-1', 30));

        $this->assertFalse($registry->holds(new LockScope('t', 'c', 'b')));
    }

    public function test_holds_is_false_when_nothing_is_held(): void
    {
        $registry = new SessionLockRegistry();

        $this->assertFalse($registry->holds(new LockScope('t', 'c', 'a')));
    }
}
