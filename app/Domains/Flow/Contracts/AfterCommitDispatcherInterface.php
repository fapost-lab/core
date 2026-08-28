<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Defers work until the surrounding database transaction commits.
 *
 * Exists so flow execution can schedule post-commit side effects without reaching
 * for the {@code DB} facade, which binds the engine to a globally resolved
 * connection instead of the one it was constructed with.
 */
interface AfterCommitDispatcherInterface
{
    /**
     * Run $callback after the current transaction commits, or immediately when
     * no transaction is open.
     */
    public function afterCommit(callable $callback): void;
}
