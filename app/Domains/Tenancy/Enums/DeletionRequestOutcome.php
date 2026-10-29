<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Enums;

/**
 * What came of asking to delete the workspace.
 */
enum DeletionRequestOutcome
{
    /** The request was recorded and the admins are told. */
    case Scheduled;

    /** The workspace already has an active request; nothing was changed. */
    case AlreadyScheduled;
}
