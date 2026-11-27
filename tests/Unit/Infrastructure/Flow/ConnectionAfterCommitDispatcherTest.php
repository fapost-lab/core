<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Flow;

use App\Domains\Flow\Contracts\AfterCommitDispatcherInterface;
use App\Infrastructure\Flow\ConnectionAfterCommitDispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;
use Mockery;
use Tests\Support\RecordingConnectionResolver;
use Tests\TestCase;

final class ConnectionAfterCommitDispatcherTest extends TestCase
{
    public function test_it_defers_the_callback_to_the_connection_the_resolver_hands_out(): void
    {
        $callback = static fn (): string => 'ran';

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('afterCommit')->once()->with($callback);

        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->once()->andReturn($connection);

        (new ConnectionAfterCommitDispatcher($resolver))->afterCommit($callback);
    }

    public function test_it_refuses_a_connection_that_cannot_defer_a_callback(): void
    {
        $resolver = Mockery::mock(ConnectionResolverInterface::class);
        $resolver->shouldReceive('connection')->andReturn(Mockery::mock(ConnectionInterface::class));

        $this->expectException(LogicException::class);

        (new ConnectionAfterCommitDispatcher($resolver))->afterCommit(static fn (): null => null);
    }

    public function test_it_resolves_its_connection_when_the_callback_is_deferred_and_runs_it_after_the_commit(): void
    {
        $resolver = new RecordingConnectionResolver($this->app->make('db'));
        $this->app->instance(ConnectionResolverInterface::class, $resolver);
        $this->app->forgetScopedInstances();

        $dispatcher = $this->app->make(AfterCommitDispatcherInterface::class);

        $this->assertSame([], $resolver->resolved, 'A connection captured when the dispatcher is built would outlive a tenant switch.');

        $ran = false;
        $this->app->make('db')->transaction(function () use ($dispatcher, &$ran): void {
            $dispatcher->afterCommit(function () use (&$ran): void {
                $ran = true;
            });

            $this->assertFalse($ran);
        });

        $this->assertTrue($ran);
        $this->assertNotEmpty($resolver->resolved);
        $this->assertSame([$this->app->make('db')->getDefaultConnection()], array_values(array_unique($resolver->resolved)));
    }
}
