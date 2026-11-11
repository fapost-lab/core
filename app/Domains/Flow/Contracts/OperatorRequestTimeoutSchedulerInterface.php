<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use DateTimeInterface;

/**
 * Schedules the end of the wait of a `request_operator` node.
 *
 * Once `$expiresAt` is due, a request nobody accepted goes back to the bot as timed out, and the waiting session leaves
 * the node by its `timeout` handle. A request accepted or cancelled first is left alone, so a late or duplicate
 * timeout is harmless.
 */
interface OperatorRequestTimeoutSchedulerInterface
{
    public function schedule(string $tenantId, string $requestId, DateTimeInterface $expiresAt): void;
}
