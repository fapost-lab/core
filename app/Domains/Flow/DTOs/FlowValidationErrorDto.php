<?php

declare(strict_types=1);

namespace App\Domains\Flow\DTOs;

use Spatie\LaravelData\Data;

final class FlowValidationErrorDto extends Data
{
    public function __construct(
        public readonly string $path,
        public readonly string $code,
        public readonly string $message,
    ) {
    }
}
