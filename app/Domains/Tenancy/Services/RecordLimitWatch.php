<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use Illuminate\Database\DatabaseManager;
use Throwable;

/**
 * Notices that a saved record took the last place under a record limit.
 *
 * The caller asks {@see RecordQuota::assertCanCreate()} before writing and tells this class after the
 * write; the announcement waits for the surrounding transaction to commit, so a rolled-back record
 * announces nothing, and runs at once when there is no transaction. It never throws: the record is
 * already saved.
 */
final readonly class RecordLimitWatch
{
    public function __construct(
        private RecordQuota $quota,
        private LimitAnnouncer $announcer,
        private DatabaseManager $database,
    ) {
    }

    /**
     * @param  int  $countBefore  how many records counted under the key before this one was written
     */
    public function afterSaved(string $key, int $countBefore): void
    {
        $this->database->afterCommit(function () use ($key, $countBefore): void {
            try {
                $limit = $this->quota->limit($key);

                if (null !== $limit && $countBefore + 1 === $limit) {
                    $this->announcer->filled($key, $limit);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }
}
