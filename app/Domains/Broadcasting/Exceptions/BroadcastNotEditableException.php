<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Exceptions;

use DomainException;

/**
 * A change reached a broadcast that is not a draft any more. A started run reads the message for every recipient
 * as it delivers, so changing it would change what the rest of the audience receives.
 */
final class BroadcastNotEditableException extends DomainException
{
    public function __construct(string $broadcastId)
    {
        parent::__construct("Broadcast '{$broadcastId}' is not a draft and cannot be changed.");
    }
}
