<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Queue;

/**
 * A queued job that does runtime work for a tenant and so stands still while the tenant is
 * stopped. Such a job names its tenant here and returns {@see RespectsTenantAccessMode} from
 * its `middleware()`; `RuntimeJobAccessModeTest` fails when a queued job is neither gated nor
 * listed as one that keeps running.
 */
interface TenantAccessGatedJob
{
    /**
     * The tenant whose access mode decides whether this job runs, in the form the operator package knows it.
     */
    public function accessModeTenantId(): string;
}
