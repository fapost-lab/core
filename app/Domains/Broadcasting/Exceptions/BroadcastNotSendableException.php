<?php

declare(strict_types=1);

namespace App\Domains\Broadcasting\Exceptions;

use DomainException;

/**
 * A draft that must not be sent as it stands: the base language has no text (every recipient would be skipped), or
 * the audience points at something that is gone or is not the tenant's.
 */
final class BroadcastNotSendableException extends DomainException
{
    public const string BASE_LANGUAGE = 'base_language';

    public const string AUDIENCE = 'audience';

    /**
     * @param  self::BASE_LANGUAGE|self::AUDIENCE  $reason
     */
    public function __construct(string $broadcastId, public readonly string $reason)
    {
        parent::__construct("Broadcast '{$broadcastId}' cannot be sent: {$reason}.");
    }
}
