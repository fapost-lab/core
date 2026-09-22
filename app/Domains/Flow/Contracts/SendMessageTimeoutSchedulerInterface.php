<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

use DateTimeInterface;

/**
 * Schedules the timeout of a `send_message` node that waits for a button press.
 *
 * The handler calls it when it sends a keyboard with a timeout; the scheduled
 * resume re-enters the session through the node's `no_response` handle once
 * {@code $timeoutAt} is due. The receiving side re-reads the session and
 * no-ops when the contact answered first, so a late or duplicate timeout is harmless.
 */
interface SendMessageTimeoutSchedulerInterface
{
    public function schedule(
        string $tenantId,
        string $sessionId,
        string $nodeId,
        string $platform,
        DateTimeInterface $timeoutAt,
    ): void;
}
