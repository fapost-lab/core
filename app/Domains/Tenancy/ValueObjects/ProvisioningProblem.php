<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

/**
 * Why provisioning stopped, as a code callers can branch on without reading a message.
 */
enum ProvisioningProblem: string
{
    /** The slug or its schema name is held by a row, or by a schema without one. */
    case SlugTaken = 'slug_taken';

    /** The first admin's e-mail or password is missing. */
    case CredentialsMissing = 'credentials_missing';

    /** The tenant is neither Pending nor Active. */
    case NotPending = 'not_pending';

    /** Another run holds the provisioning lease; repeat later. */
    case InProgress = 'in_progress';

    /**
     * Repeating cannot help and an operator must decide: a schema of the tenant's name exists although
     * the tenant never claimed it, or the tenant already has a user other than the first admin.
     */
    case Conflict = 'conflict';

    /** A provisioning step threw. */
    case StepFailed = 'step_failed';
}
