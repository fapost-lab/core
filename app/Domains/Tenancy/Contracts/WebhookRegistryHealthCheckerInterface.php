<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\ValueObjects\WebhookRegistryHealthReport;

/**
 * Read-only comparison of the Redis webhook routing cache with the landlord registry, for domains that report on it
 * without touching the landlord themselves.
 */
interface WebhookRegistryHealthCheckerInterface
{
    public function check(): WebhookRegistryHealthReport;
}
