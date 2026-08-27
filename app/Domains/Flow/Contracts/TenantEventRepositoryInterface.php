<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

interface TenantEventRepositoryInterface
{
    /**
     * Tenant-wide list of known event names (across all assistants and flows).
     *
     * @return list<string>
     */
    public function getEventNamesByTenant(string $tenantId): array;

    /**
     * Declaratively register event names emitted within the tenant (e.g. from
     * `emit_event` nodes at publish time) so the trigger picker can reference
     * events of any flow/assistant before they ever fire at runtime. Idempotent.
     *
     * @param  list<string>  $eventNames
     */
    public function registerEventNames(string $tenantId, array $eventNames): void;
}
