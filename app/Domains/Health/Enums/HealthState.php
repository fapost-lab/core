<?php

declare(strict_types=1);

namespace App\Domains\Health\Enums;

/**
 * What a probe could establish about a service.
 *
 * The states are deliberately not a traffic light. A probe that cannot reach its source has learned nothing, and
 * reporting that as healthy would be a lie while reporting it as down would blame the service for the monitor's own
 * trouble, so "could not find out" has a state of its own and no consumer may fold it into either side.
 */
enum HealthState: string
{
    /** The service answered and reported itself healthy. */
    case Up = 'up';

    /** The service answered, and what it said is that it is not healthy. */
    case Down = 'down';

    /** The source could not be read: unreachable, timed out, rejected the request or is not configured. */
    case Unknown = 'unknown';

    /** The service is switched off on purpose. Not an error and not a reason to alarm anyone. */
    case Disabled = 'disabled';
}
