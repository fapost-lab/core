<?php

declare(strict_types=1);

namespace App\Domains\Audit\Enums;

/**
 * Who did what an entry records.
 *
 * `Staff` is a staff user signed in to the console; `Support` is the tenant's platform support user, through which a
 * platform operator acts inside the tenant (the entry names the operator, the user is the same for all of them);
 * `System` is everything that is not a signed-in person: a queued job, a command, the scheduler.
 */
enum AuditActorKind: string
{
    case Staff   = 'staff';
    case Support = 'support';
    case System  = 'system';
}
