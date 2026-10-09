<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

/**
 * Why {@see \App\Domains\Tenancy\Services\TenantSlugPolicy} refuses a slug.
 */
enum SlugRejection: string
{
    case Malformed          = 'malformed';
    case TooLong            = 'too_long';
    case PunycodePrefix     = 'punycode_prefix';
    case ConsecutiveHyphens = 'consecutive_hyphens';
    case Reserved           = 'reserved';
}
