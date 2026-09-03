<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Database;

use Illuminate\Database\Concerns\ParsesSearchPath;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\QueryException;
use PDO;

/**
 * PostgreSQL connection whose search_path can be moved while it stays open.
 *
 * Laravel applies `search_path` once, when the PDO handle is created, and the
 * schema builder reads it back from the connection's own copy of the config.
 * Changing the global config therefore does nothing to a live connection; the
 * stock answer is to purge and reconnect, which also throws away any open
 * transaction. This class changes the session and its config copy in place,
 * so a tenant switch inside a transaction (a queue job, a test) keeps it.
 *
 * The config copy is the source of truth for the session's search_path: a
 * session that is not open yet is set when it opens, and one whose
 * transaction is aborted is set again as soon as that transaction is rolled
 * back (PostgreSQL rejects every statement, `SET` included, in between).
 */
final class TenantPostgresConnection extends PostgresConnection
{
    use ParsesSearchPath;

    /**
     * PostgreSQL: "current transaction is aborted, commands ignored until end
     * of transaction block".
     */
    private const string IN_FAILED_TRANSACTION = '25P02';

    /**
     * Whether the session still has to be told about a search_path change
     * made while it was not open.
     */
    private bool $searchPathPending = false;

    /**
     * Point this connection at the given schemas.
     *
     * Null (or an empty list) resets the session to the server default, which
     * is what a fresh connection without a `search_path` entry would get.
     *
     * @param  array<int, string>|string|null  $searchPath
     */
    public function useSearchPath(array|string|null $searchPath): void
    {
        $this->config['search_path'] = $searchPath;

        if (! $this->pdo instanceof PDO) {
            $this->searchPathPending = true;

            return;
        }

        try {
            $this->statement($this->compileSearchPath($searchPath));
        } catch (QueryException $exception) {
            if (self::IN_FAILED_TRANSACTION !== (string) $exception->getCode()) {
                throw $exception;
            }

            // The transaction is doomed and its rollback re-applies the config,
            // see rollBack(); throwing here would only mask the failure that
            // aborted it, typically from inside a finally block.
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getPdo()
    {
        $pdo = parent::getPdo();

        if ($this->searchPathPending && $pdo instanceof PDO) {
            $this->searchPathPending = false;
            $pdo->exec($this->compileSearchPath($this->config['search_path'] ?? null));
        }

        return $pdo;
    }

    /**
     * {@inheritDoc}
     *
     * A rollback also undoes any `SET search_path` issued inside the rolled
     * back (sub)transaction, so the session is brought back in line with the
     * config, which the tenant switch stack keeps current.
     */
    public function rollBack($toLevel = null): void
    {
        $levelBefore = $this->transactions;

        parent::rollBack($toLevel);

        if ($levelBefore !== $this->transactions && $this->pdo instanceof PDO) {
            $this->pdo->exec($this->compileSearchPath($this->config['search_path'] ?? null));
        }
    }

    /**
     * @param  array<int, string>|string|null  $searchPath
     */
    private function compileSearchPath(array|string|null $searchPath): string
    {
        $schemas = $this->parseSearchPath($searchPath);

        if ([] === $schemas) {
            return 'reset search_path';
        }

        return 'set search_path to "' . implode('", "', $schemas) . '"';
    }
}
