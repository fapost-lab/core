<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use App\Domains\Flow\DTOs\FlowValidationErrorDto;
use DomainException;
use Throwable;

final class FlowValidationException extends DomainException
{
    /**
     * @var list<FlowValidationErrorDto>
     */
    public readonly array $errors;

    /**
     * @param  list<FlowValidationErrorDto>  $errors
     */
    public function __construct(array $errors, ?Throwable $previous = null)
    {
        $this->errors = $errors;
        parent::__construct('Flow validation failed.', previous: $previous);
    }
}
