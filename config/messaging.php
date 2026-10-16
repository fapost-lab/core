<?php

declare(strict_types=1);

return [
    /*
     * Platform fallback for the per-chat send limit, used only outside a tenant and for a tenant whose
     * `tenant.messaging_rate_limit` row is absent. Provisioning stores 30 in every tenant schema, so a
     * tenant's limit is changed in Tenant settings, not here; the stored value always wins.
     */
    'rate_limit_per_minute' => (int) env('MESSAGING_RATE_LIMIT_PER_MINUTE', 30),
];
