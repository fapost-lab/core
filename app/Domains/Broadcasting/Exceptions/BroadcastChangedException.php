<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Exceptions;

use DomainException;

/**
 * The revision a person confirmed is no longer the draft's: it was edited after the confirmation dialog was
 * opened. Nothing was sent; the person has to look again.
 */
final class BroadcastChangedException extends DomainException
{
    public function __construct(string $broadcastId)
    {
        parent::__construct("Broadcast '{$broadcastId}' changed since it was confirmed.");
    }
}
