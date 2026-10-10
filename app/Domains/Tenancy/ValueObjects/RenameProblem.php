<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

/**
 * Why a slug change was refused for a reason that is neither a bad slug nor a missing tenant.
 */
enum RenameProblem: string
{
    /** The slug belongs to another tenant now, was another tenant's slug or schema, or is its former slug. */
    case SlugTaken = 'slug_taken';

    /** The tenant is Pending: an unfinished registration holds its slug. */
    case TenantPending = 'tenant_pending';
}
