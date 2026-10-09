<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\ValueObjects;

use Carbon\CarbonImmutable;

/**
 * The provisioning lease one run holds, identified by its end. Every write the run makes to the row
 * is conditional on the row still carrying this end, so a run that outlived its lease cannot renew,
 * clear or activate on behalf of the run that took over.
 */
final class ProvisioningLease
{
    public function __construct(
        public CarbonImmutable $until,
    ) {
    }
}
