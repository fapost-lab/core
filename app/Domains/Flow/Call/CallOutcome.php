<?php

declare(strict_types=1);

namespace App\Domains\Flow\Call;

use Fapost\Foundation\Flow\Call\CallResult;

/**
 * What the `call` node got for one execution: the result, and whether it was sent (and as which attempt) or
 * replayed from the {@see CallExecutionLedgerInterface}.
 */
final readonly class CallOutcome
{
    public function __construct(
        public CallResult $result,
        public ?int $attempt,
        public bool $replayed,
    ) {
    }
}
