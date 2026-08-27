<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use Spatie\LaravelData\Data;

final class FlowValidationResultDto extends Data
{
    /**
     * @param  list<FlowValidationErrorDto>  $errors
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors,
    ) {
    }
}
