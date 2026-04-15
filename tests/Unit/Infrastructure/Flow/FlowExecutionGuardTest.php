<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Flow;

use App\Domains\Flow\Exceptions\SessionLockTimeoutException;
use App\Infrastructure\Flow\FlowExecutionGuard;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use RuntimeException;
use Tests\TestCase;

final class FlowExecutionGuardTest extends TestCase
{
    public function test_it_executes_callback_and_returns_result_when_lock_is_acquired(): void
    {
        $lock = $this->mock(Lock::class, function ($mock): void {
            $mock->shouldReceive('get')->once()->andReturn(true);
            $mock->shouldReceive('release')->once();
        });

        $store = $this->mock(LockProvider::class, function ($mock) use ($lock): void {
            $mock->shouldReceive('lock')
                ->once()
                ->with('session_lock:tenant-1:contact-1:assistant-1', 30)
                ->andReturn($lock);
        });

        $guard = new FlowExecutionGuard($store);

        $result = $guard->run('tenant-1', 'contact-1', 'assistant-1', static fn (): string => 'ok');

        $this->assertSame('ok', $result);
    }

    public function test_it_throws_when_lock_is_busy(): void
    {
        $lock = $this->mock(Lock::class, function ($mock): void {
            $mock->shouldReceive('get')->once()->andReturn(false);
            $mock->shouldNotReceive('release');
        });

        $store = $this->mock(LockProvider::class, function ($mock) use ($lock): void {
            $mock->shouldReceive('lock')
                ->once()
                ->with('session_lock:tenant-1:contact-1:assistant-1', 30)
                ->andReturn($lock);
        });

        $guard = new FlowExecutionGuard($store);

        $this->expectException(SessionLockTimeoutException::class);

        $guard->run('tenant-1', 'contact-1', 'assistant-1', static fn (): null => null);
    }

    public function test_it_releases_lock_when_callback_throws(): void
    {
        $lock = $this->mock(Lock::class, function ($mock): void {
            $mock->shouldReceive('get')->once()->andReturn(true);
            $mock->shouldReceive('release')->once();
        });

        $store = $this->mock(LockProvider::class, function ($mock) use ($lock): void {
            $mock->shouldReceive('lock')
                ->once()
                ->with('session_lock:tenant-1:contact-1:assistant-1', 30)
                ->andReturn($lock);
        });

        $guard = new FlowExecutionGuard($store);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $guard->run('tenant-1', 'contact-1', 'assistant-1', static function (): never {
            throw new RuntimeException('boom');
        });
    }
}
