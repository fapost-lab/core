<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Queue;

/**
 * What a gated job does when its tenant is stopped.
 */
enum StoppedTenantAction
{
    /** The work is lost and a log line says so: it belongs to a moment that has passed (an inbound message, an event). */
    case Drop;

    /** The work waits: a copy is queued for later, so it runs once the tenant is active again (a wake-up, a broadcast). */
    case Postpone;
}
