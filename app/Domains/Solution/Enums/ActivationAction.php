<?php

declare(strict_types=1);

namespace App\Domains\Solution\Enums;

/**
 * What a journal entry records.
 */
enum ActivationAction: string
{
    case Activated   = 'activated';
    case Deactivated = 'deactivated';
}
