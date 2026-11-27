<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\AfterCommitDispatcherInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use LogicException;

/**
 * Binds post-commit callbacks to the connection resolved when the callback is registered — the same one the flow
 * engine opens its transactions on at that moment.
 */
final readonly class ConnectionAfterCommitDispatcher implements AfterCommitDispatcherInterface
{
    /**
     * @param  ConnectionResolverInterface  $db  asked for its connection at the moment a callback is registered, never
     *                                         kept: a tenant switch moves the default connection the models write
     *                                         through, and a connection taken when this is built — before the switch,
     *                                         in a queue job — would defer the callback to another transaction
     */
    public function __construct(
        private ConnectionResolverInterface $db,
    ) {
    }

    /**
     * @throws LogicException  if the resolved connection cannot defer a callback until its commit
     */
    public function afterCommit(callable $callback): void
    {
        $connection = $this->db->connection();

        if (!$connection instanceof Connection) {
            throw new LogicException(sprintf('The connection %s cannot defer a callback until its commit.', $connection::class));
        }

        $connection->afterCommit($callback);
    }
}
