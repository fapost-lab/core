<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

/**
 * Mirrors Foundation's `TenantStatus`; a test keeps the two in step.
 *
 * `Pending` holds a slug and a schema name while provisioning has not completed. `Inactive` is no
 * longer set by provisioning: it remains for older rows and manual deactivation.
 */
enum TenantStatus: string
{
    case Pending   = 'pending';
    case Active    = 'active';
    case Inactive  = 'inactive';
    case Suspended = 'suspended';
}
