<?php

declare(strict_types=1);

namespace App\Domains\Solution\Enums;

/**
 * What an activate or deactivate call did. A repeat in the same state changes nothing and writes no journal entry.
 */
enum ActivationChange: string
{
    case Activated   = 'activated';
    case Deactivated = 'deactivated';
    case Unchanged   = 'unchanged';

    public function changed(): bool
    {
        return self::Unchanged !== $this;
    }
}
