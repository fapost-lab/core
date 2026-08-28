<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Flow;

use App\Infrastructure\Flow\ConnectionAfterCommitDispatcher;
use Illuminate\Database\Connection;
use Mockery;
use Tests\TestCase;

final class ConnectionAfterCommitDispatcherTest extends TestCase
{
    public function test_it_defers_the_callback_to_the_injected_connection(): void
    {
        $callback = static fn (): string => 'ran';

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('afterCommit')->once()->with($callback);

        (new ConnectionAfterCommitDispatcher($connection))->afterCommit($callback);
    }

    public function test_it_does_not_reach_for_the_globally_resolved_connection(): void
    {
        $other = Mockery::mock(Connection::class);
        $other->shouldNotReceive('afterCommit');

        $injected = Mockery::mock(Connection::class);
        $injected->shouldReceive('afterCommit')->once();

        $this->app->instance('db.connection', $other);

        (new ConnectionAfterCommitDispatcher($injected))->afterCommit(static fn (): null => null);
    }
}
