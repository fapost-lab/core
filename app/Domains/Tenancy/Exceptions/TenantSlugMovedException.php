<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Exceptions;

use App\Domains\Tenancy\Contracts\TenantInterface;
use RuntimeException;

/**
 * The host names a slug the tenant used to have and still redirects from. The resolver throws it instead of
 * {@see TenantNotFoundException} (which Pint keeps final, so it cannot be a subclass); the HTTP layer turns it
 * into a 302 for GET and HEAD and into the same 404 as a missing tenant for any other method.
 */
final class TenantSlugMovedException extends RuntimeException
{
    public function __construct(string $formerSlug, public readonly TenantInterface $tenant)
    {
        parent::__construct("Tenant slug [{$formerSlug}] moved to [{$tenant->getSlug()}].");
    }
}
