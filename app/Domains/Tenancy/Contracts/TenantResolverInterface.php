<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\Exceptions\TenantNotActiveException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Exceptions\TenantSlugMovedException;
use Illuminate\Http\Request;

/**
 * Picks the tenant that owns the incoming request (or equivalent unit of work).
 *
 * Resolution strategy is implementation-defined (e.g. static config vs host or header lookup).
 */
interface TenantResolverInterface
{
    /**
     * @throws TenantNotFoundException when tenant cannot be resolved.
     * @throws TenantNotActiveException when resolved tenant must not run.
     * @throws TenantSlugMovedException when the host is a former slug of an active tenant that still redirects (host resolution only).
     */
    public function resolve(Request $request): TenantInterface;
}
