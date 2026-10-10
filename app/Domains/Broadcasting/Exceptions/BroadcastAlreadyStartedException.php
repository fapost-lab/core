<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Exceptions;

use DomainException;

/**
 * Sending found the broadcast already started, finished or cancelled: another click, tab or request got there first.
 * The message is for logs; the screen words the refusal from its own translations.
 */
final class BroadcastAlreadyStartedException extends DomainException
{
    public function __construct(string $broadcastId)
    {
        parent::__construct("Broadcast '{$broadcastId}' is not a draft any more.");
    }
}
