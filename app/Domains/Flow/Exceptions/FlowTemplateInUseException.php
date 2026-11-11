<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use DomainException;

/**
 * A template is placed in a published flow or in a flow's draft, so deleting it would break that flow's next
 * publish. The message is for logs; the screen words the refusal from its own translations.
 */
final class FlowTemplateInUseException extends DomainException
{
    public function __construct(string $flowId)
    {
        parent::__construct("Flow template '{$flowId}' is used by other flows.");
    }
}
