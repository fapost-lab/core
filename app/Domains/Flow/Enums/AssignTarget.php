<?php

declare(strict_types=1);

namespace App\Domains\Flow\Enums;

/**
 * Storage target for the legacy single-write form of the `assign` node.
 * The multi-operation form encodes the same dichotomy through
 * {@see \App\Domains\Flow\State\Variables\VariableStorage}.
 */
enum AssignTarget: string
{
    case Flow    = 'flow';
    case Contact = 'contact';
}
