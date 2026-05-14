<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use RuntimeException;

/**
 * Thrown during flow validation when a variable is declared with a type
 * that conflicts with a declaration already established by another active flow.
 *
 * The message contains the human-readable description of the conflict;
 * use {@see $path}, {@see $existingType}, and {@see $newType} for programmatic handling.
 */
final class VariableTypeConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $path,
        public readonly string $existingType,
        public readonly string $newType,
        public readonly string $declaringFlowName,
        string $message = '',
    ) {
        parent::__construct(
            $message ?: "Variable \"{$path}\" is declared as \"{$existingType}\" in flow \"{$declaringFlowName}\", cannot redeclare as \"{$newType}\"."
        );
    }
}
