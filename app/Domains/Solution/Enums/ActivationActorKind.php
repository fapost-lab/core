<?php

declare(strict_types=1);

namespace App\Domains\Solution\Enums;

/**
 * Who changed a Solution's activation.
 */
enum ActivationActorKind: string
{
    /** A staff user of the tenant. */
    case User = 'user';

    /** An artisan command run by whoever has shell access. */
    case Console = 'console';

    /** The platform operator, such as support staff or a billing job. */
    case Operator = 'operator';

    /** Core or the operator acting on its own, such as after a lost entitlement. */
    case System = 'system';
}
