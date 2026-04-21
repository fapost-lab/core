<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use DomainException;

final class DraftVersionConflictException extends DomainException
{
    public function __construct(string $flowId)
    {
        parent::__construct("Draft version conflict for flow '{$flowId}'.");
    }
}
