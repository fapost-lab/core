<?php

declare(strict_types=1);

namespace App\Domains\Audit\Contracts;

use App\Domains\Audit\DTOs\AuditActor;

/**
 * Who is acting right now: the signed-in staff user, the platform support user (with the operator behind it), or the
 * system when nobody is signed in (a job, a command). Bound `scoped`, so one job's actor never reaches the next.
 */
interface AuditActorResolverInterface
{
    public function current(): AuditActor;
}
