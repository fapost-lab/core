<?php

declare(strict_types=1);

namespace App\Domains\Flow\Exceptions;

use RuntimeException;

final class InvalidFlowGraphException extends RuntimeException
{
    public static function missingNode(string $nodeId): self
    {
        return new self("Flow graph has no node with id '{$nodeId}'.");
    }

    public static function missingEntryNode(): self
    {
        return new self('Flow graph has no entry node (expected exactly one node without incoming edges).');
    }

    public static function nodeMissingType(string $nodeId): self
    {
        return new self("Flow node '{$nodeId}' is missing a valid type.");
    }

    public static function sessionHasNoCurrentNode(): self
    {
        return new self('Flow session has no current node id; cannot resume execution.');
    }
}
