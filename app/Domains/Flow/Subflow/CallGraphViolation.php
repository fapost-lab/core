<?php

declare(strict_types=1);

namespace App\Domains\Flow\Subflow;

/**
 * Immutable description of a single call-graph rule violation surfaced by
 * {@see CallGraphValidator}. Translated by the caller (validator service) into
 * a {@see \App\Domains\Flow\DTOs\FlowValidationErrorDto}.
 */
final readonly class CallGraphViolation
{
    public function __construct(
        public string $code,
        public string $message,
        public string $offendingFlowId,
    ) {
    }
}
