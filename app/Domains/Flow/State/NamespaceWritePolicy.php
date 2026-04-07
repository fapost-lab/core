<?php

declare(strict_types=1);

namespace App\Domains\Flow\State;

enum NamespaceWritePolicy
{
    case EngineOnly;

    case NodeRestricted;

    case Writable;

    case AccessorOnly;
}
