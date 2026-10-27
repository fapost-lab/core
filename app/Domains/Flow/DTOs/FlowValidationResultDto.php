<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use Spatie\LaravelData\Data;

final class FlowValidationResultDto extends Data
{
    /**
     * @param  list<FlowValidationErrorDto>  $errors    Block publishing.
     * @param  list<FlowValidationErrorDto>  $warnings  Advice only: never affects `valid`.
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $errors,
        public readonly array $warnings = [],
    ) {
    }
}
