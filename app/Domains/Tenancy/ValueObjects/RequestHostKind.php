<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

enum RequestHostKind: string
{
    /** The base domain itself: platform pages, no tenant. */
    case Platform = 'platform';

    /** A host that names a tenant. */
    case Tenant = 'tenant';

    /** Any other host. */
    case Foreign = 'foreign';
}
