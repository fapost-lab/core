<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use Fapost\Foundation\Flow\Call\CallResult;

/**
 * What the `call` node got for one execution: the result, and whether it was sent (and as which attempt) or
 * replayed from the {@see CallExecutionLedgerInterface}.
 *
 * `$sends` counts the sends of this execution ({@see CallRetryPolicy}); `$retryStopped` is true when a retryable
 * failure was not sent again because the next send did not fit the node's window.
 */
final readonly class CallOutcome
{
    public function __construct(
        public CallResult $result,
        public ?int $attempt,
        public bool $replayed,
        public int $sends = 1,
        public bool $retryStopped = false,
    ) {
    }
}
