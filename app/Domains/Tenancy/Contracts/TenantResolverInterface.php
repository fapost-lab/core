<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\Exceptions\TenantNotActiveException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use Illuminate\Http\Request;

/**
 * Picks the tenant that owns the incoming request (or equivalent unit of work).
 *
 * Resolution strategy is implementation-defined (e.g. self-hosted config vs SaaS host/header).
 */
interface TenantResolverInterface
{
    /**
     * @throws TenantNotFoundException when tenant cannot be resolved.
     * @throws TenantNotActiveException when resolved tenant must not run.
     */
    public function resolve(Request $request): TenantInterface;
}
