<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Models;

enum TenantStatus: string
{
    case Active    = 'active';
    case Inactive  = 'inactive';
    case Suspended = 'suspended';
}
