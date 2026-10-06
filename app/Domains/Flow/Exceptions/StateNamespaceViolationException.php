<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use RuntimeException;

/**
 * Raised when a node handler attempts to write a session-state key into a namespace
 * it does not own (see .ai/knowledge/domains/flow/RULES.md, state namespaces). Enforced at persist time so
 * Solution/Plugin handlers cannot bypass the namespace contract.
 */
final class StateNamespaceViolationException extends RuntimeException
{
    public static function forWrite(string $nodeType, string $key): self
    {
        return new self(
            "Node type '{$nodeType}' is not allowed to write state key '{$key}'.",
        );
    }
}
