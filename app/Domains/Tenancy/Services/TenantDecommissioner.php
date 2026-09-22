<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;

/**
 * Removes a tenant completely: its webhook registry entries (landlord table and
 * Redis cache), its schema, and its landlord row — in that order, so a webhook
 * can never route into a schema that is being dropped.
 *
 * Irreversible. Today only the load-test harness decommissions tenants, and only
 * the throwaway tenants it provisioned itself.
 */
final readonly class TenantDecommissioner
{
    public function __construct(
        private TenantRepositoryInterface $tenants,
        private TenantDatabaseManagerInterface $databaseManager,
        private WebhookRegistryWriterInterface $webhookRegistry,
    ) {
    }

    public function decommission(TenantInterface $tenant): void
    {
        $this->webhookRegistry->deleteForTenant($tenant->getId());

        if ($this->databaseManager->schemaExists($tenant)) {
            $this->databaseManager->dropSchema($tenant);
        }

        $this->tenants->delete($tenant);
    }
}
