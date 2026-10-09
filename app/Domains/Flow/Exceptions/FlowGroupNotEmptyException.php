<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use DomainException;

/**
 * A flow group still holds flows, so deleting it would leave them pointing nowhere (the column has no foreign key).
 * The message is for logs; the screen words the refusal from its own translations.
 */
final class FlowGroupNotEmptyException extends DomainException
{
    public function __construct(string $groupId)
    {
        parent::__construct("Flow group '{$groupId}' still has flows.");
    }
}
