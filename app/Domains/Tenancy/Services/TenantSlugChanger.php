<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Exceptions\TenantRenameException;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\ValueObjects\SlugChange;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Changes a tenant's slug, and so its host, in one landlord transaction.
 *
 * Only the `tenants.slug` column moves. `schema_name` stays what it was at creation, so the tenant's
 * schema, data, webhooks and tokens are untouched. The slug the tenant gives up is written to the
 * former-slug table in the same transaction: it redirects for a while and stays the tenant's for good,
 * so an old link can never reach another tenant.
 *
 * Slug claims (this and {@see TenantProvisioningService::reserveSlug()}) take one advisory lock on
 * PostgreSQL, so a claim cannot slip in between another claim's check and its write.
 */
final readonly class TenantSlugChanger
{
    public function __construct(
        private TenantRepositoryInterface $tenants,
        private TenantSlugPolicy $slugPolicy,
        private LoggerInterface $logger,
        private int $redirectDays = 30,
    ) {
    }

    /**
     * Renaming to the slug the tenant already has changes nothing.
     *
     * @throws InvalidTenantSlugException  the new slug is malformed, too long or reserved.
     * @throws TenantNotFoundException     no tenant has this id.
     * @throws TenantRenameException       the tenant is Pending, or the slug is taken.
     */
    public function change(string $tenantId, string $newSlug): SlugChange
    {
        $this->slugPolicy->assertAssignable($newSlug);

        try {
            $change = $this->tenants->transaction(fn (): SlugChange => $this->changeLocked($tenantId, $newSlug));
        } catch (UniqueConstraintViolationException $exception) {
            // A concurrent claim that holds no advisory lock (SQLite, or a write outside Core) won the unique index.
            throw TenantRenameException::slugTaken($newSlug, $exception);
        }

        if ($change->changed) {
            $this->logger->info('Tenant slug changed.', [
                'tenant_id'     => $tenantId,
                'previous_slug' => $change->previousSlug,
                'slug'          => $newSlug,
            ]);
        }

        return $change;
    }

    private function changeLocked(string $tenantId, string $newSlug): SlugChange
    {
        $this->tenants->lockSlugClaims();

        $state = $this->tenants->findForRename($tenantId) ?? throw TenantNotFoundException::forId($tenantId);

        // Active, Inactive and Suspended tenants can be renamed; a Pending one cannot, as its slug is held
        // by a registration that has not finished.
        if (TenantStatus::Pending === $state->status) {
            throw TenantRenameException::tenantPending($tenantId);
        }

        $tenant = $state->tenant;

        $previousSlug = $tenant->getSlug();

        if ($previousSlug === $newSlug) {
            return new SlugChange($tenant, $previousSlug, false, null);
        }

        if ($this->tenants->isSlugClaimedByOther($newSlug, $this->slugPolicy->schemaNameFor($newSlug), $tenantId)) {
            throw TenantRenameException::slugTaken($newSlug);
        }

        // The tenant takes one of its own former slugs back.
        $this->tenants->removeFormerSlug($newSlug, $tenantId);

        $now = CarbonImmutable::now();

        if (! $this->tenants->renameSlug($tenant, $newSlug, $now)) {
            throw new RuntimeException("Tenant [{$tenantId}] changed while it was being renamed.");
        }

        $redirectUntil = $this->redirectDays > 0 ? $now->addDays($this->redirectDays) : null;
        $this->tenants->addFormerSlug($previousSlug, $tenantId, $redirectUntil, $now);

        return new SlugChange($tenant, $previousSlug, true, $redirectUntil);
    }
}
