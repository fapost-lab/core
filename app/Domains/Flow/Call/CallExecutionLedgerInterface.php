<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use Fapost\Foundation\Flow\Call\CallResult;

/**
 * The record the `call` node keeps of its executions, by the execution key
 * ({@see \Fapost\Foundation\Flow\Call\CallContext::$idempotencyKey}): which attempt this is, and the outcome of
 * a call that already succeeded, so a repeated execution of the same step is not sent again
 * (`adr-20261118-call-outbound-idempotency-strategy`).
 *
 * The ledger never blocks a call: when its store is unreachable it answers as if it had no record.
 */
interface CallExecutionLedgerInterface
{
    /**
     * The outcome of an earlier successful execution of this key, still inside the dedup window.
     */
    public function completed(string $tenantId, string $key): ?CallResult;

    /**
     * Counts one more send of this key and returns its number (1 for the first).
     */
    public function startAttempt(string $tenantId, string $key): int;

    /**
     * Remembers a successful outcome for the dedup window.
     */
    public function complete(string $tenantId, string $key, CallResult $result): void;
}
