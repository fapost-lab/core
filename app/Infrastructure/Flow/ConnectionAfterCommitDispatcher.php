<?php

declare(strict_types=1);

namespace App\Infrastructure\Flow;

use App\Domains\Flow\Contracts\AfterCommitDispatcherInterface;
use Illuminate\Database\Connection;

/**
 * Binds post-commit callbacks to one explicit connection — the same one the flow
 * engine opens its transactions on.
 */
final readonly class ConnectionAfterCommitDispatcher implements AfterCommitDispatcherInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function afterCommit(callable $callback): void
    {
        $this->connection->afterCommit($callback);
    }
}
