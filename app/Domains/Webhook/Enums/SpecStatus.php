<?php

declare(strict_types=1);

namespace App\Domains\Webhook\Enums;

/**
 * How the spec published to Redis for a platform compares with what its adapter declares now.
 */
enum SpecStatus: string
{
    case Matches = 'matches';

    /** Nothing is published, or what is there cannot be read; the gateway proxies the platform's deliveries. */
    case Missing = 'missing';

    /** A spec is published, but not the adapter's current one. */
    case Differs = 'differs';
}
