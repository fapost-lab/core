<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use DomainException;

/**
 * A flow still has sessions that are running or waiting, so deleting it would pull the flow from under them.
 * The message is for logs; the screen words the refusal from its own translations.
 */
final class FlowHasLiveSessionsException extends DomainException
{
    public function __construct(string $flowId)
    {
        parent::__construct("Flow '{$flowId}' has live sessions.");
    }
}
