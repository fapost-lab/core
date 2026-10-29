<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\ChannelWebhookRemoverInterface;
use App\Domains\Tenancy\Contracts\TenantDatabaseManagerInterface;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantMediaPurgerInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Contracts\WebhookRegistryWriterInterface;
use Fapost\Foundation\Tenancy\Enums\DecommissionReason;
use Fapost\Foundation\Tenancy\Events\TenantDecommissioned;
use Illuminate\Contracts\Events\Dispatcher;
use Psr\Log\LoggerInterface;

/**
 * Removes a tenant, in an order that is safe to repeat after a failure at any step.
 *
 * {@see self::decommission()} is the local removal: webhook registry entries (landlord table and Redis cache),
 * support access tokens, the schema and the landlord row, so a webhook can never route into a schema that is being
 * dropped. The load-test harness uses it for the throwaway tenants it provisioned itself.
 *
 * {@see self::decommissionCompletely()} is the removal of a real tenant, in this order:
 *  1. the tenant stops being served (Inactive), so a run that fails half way leaves it unserved and retriable;
 *  2. the channel webhooks are taken down at the provider and the tenant's cache entries forgotten, while the schema
 *     still holds the bot tokens;
 *  3. the webhook routing and the support tokens go;
 *  4. the media the installation holds goes;
 *  5. the schema is dropped;
 *  6. {@see TenantDecommissioned} is dispatched and the log line written, still with the landlord row in place;
 *  7. the landlord row goes.
 * The row is the last thing to go and the event comes before it, so a listener that fails (or a worker that dies) leaves
 * the row, and the next attempt finds the tenant again and dispatches the event again.
 *
 * Irreversible.
 */
final readonly class TenantDecommissioner
{
    public function __construct(
        private TenantRepositoryInterface $tenants,
        private TenantDatabaseManagerInterface $databaseManager,
        private WebhookRegistryWriterInterface $webhookRegistry,
        private SupportAccessTokenStore $supportTokens,
        private TenantSwitcher $switcher,
        private ChannelWebhookRemoverInterface $providerWebhooks,
        private TenantMediaPurgerInterface $media,
        private TenantCaches $caches,
        private Dispatcher $events,
        private LoggerInterface $logger,
    ) {
    }

    public function decommission(TenantInterface $tenant): void
    {
        $this->removeRouting($tenant);
        $this->removeSchemaAndRow($tenant);
    }

    /**
     * @return bool true when the tenant's media lives in storage of its own, which was left untouched
     */
    public function decommissionCompletely(TenantInterface $tenant, DecommissionReason $reason): bool
    {
        $this->tenants->stopServing($tenant->getId());

        // Needs the tenant's schema for the channels and their tokens; once it is dropped there is nothing to call,
        // and the schema is dropped only after this has been done.
        if ($this->databaseManager->schemaExists($tenant)) {
            $this->switcher->runForTenant($tenant, function () use ($tenant): void {
                $this->providerWebhooks->removeAll($tenant->getId());
                $this->caches->forget($tenant);
            });
        }

        $this->removeRouting($tenant);

        $mediaKept = $this->media->purge($tenant);

        if ($this->databaseManager->schemaExists($tenant)) {
            $this->databaseManager->dropSchema($tenant);
        }

        $this->events->dispatch(new TenantDecommissioned($tenant->getId(), $tenant->getSlug(), $reason));

        $this->logger->info('tenant.decommissioned', [
            'tenant_id' => $tenant->getId(),
            'slug'      => $tenant->getSlug(),
            'reason'    => $reason->value,
        ]);

        $this->tenants->delete($tenant);

        return $mediaKept;
    }

    private function removeRouting(TenantInterface $tenant): void
    {
        $this->webhookRegistry->deleteForTenant($tenant->getId());
        $this->supportTokens->deleteForTenant($tenant->getId());
    }

    private function removeSchemaAndRow(TenantInterface $tenant): void
    {
        if ($this->databaseManager->schemaExists($tenant)) {
            $this->databaseManager->dropSchema($tenant);
        }

        $this->tenants->delete($tenant);
    }
}
