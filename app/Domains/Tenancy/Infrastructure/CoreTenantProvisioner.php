<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Support\TenantHost;
use Fapost\Foundation\Tenancy\Contracts\TenantProvisionerInterface;
use Fapost\Foundation\Tenancy\DTO\ProvisionedTenant;
use Fapost\Foundation\Tenancy\DTO\ProvisionTenant;
use Fapost\Foundation\Tenancy\Exceptions\TenantProvisioningFailedException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Hashing\HashManager;
use Throwable;

/**
 * Core's implementation of the public tenant provisioning contract.
 *
 * Translates {@see TenantProvisioningService} into Foundation's request, result and failure
 * reasons, so a package that depends only on Foundation never sees Core exceptions or models.
 * Synchronous and slow (the full tenant schema is migrated): callers run it outside HTTP requests.
 */
final readonly class CoreTenantProvisioner implements TenantProvisionerInterface
{
    /** Where a tenant's administrators sign in, on the tenant's own host. */
    private const string LOGIN_PATH = '/admin/login';

    private const string DEFAULT_ADMIN_NAME = 'Administrator';

    public function __construct(
        private TenantProvisioningService $provisioningService,
        private TenantRepositoryInterface $tenantRepository,
        private TenantSlugPolicy $slugPolicy,
        private HashManager $hasher,
    ) {
    }

    public function provision(ProvisionTenant $request): ProvisionedTenant
    {
        $this->assertSlugAvailable($request->slug);

        if ('' === mb_trim($request->adminEmail) || '' === $request->adminPasswordHash) {
            throw TenantProvisioningFailedException::adminCredentialsMissing();
        }

        // The User model's `hashed` cast stores a hash as-is only when it matches the configured
        // algorithm; anything else would be hashed a second time and the admin could not sign in.
        if (! $this->hasher->isHashed($request->adminPasswordHash) || ! $this->hasher->verifyConfiguration($request->adminPasswordHash)) {
            throw TenantProvisioningFailedException::adminPasswordHashInvalid();
        }

        try {
            $tenant = $this->provisioningService->provision(
                $request->slug,
                $request->adminEmail,
                $request->adminPasswordHash,
                '' === mb_trim($request->adminName) ? self::DEFAULT_ADMIN_NAME : $request->adminName,
            );
        } catch (UniqueConstraintViolationException $exception) {
            // Concurrent provisioning of one slug passed the check above and lost the race on save.
            throw TenantProvisioningFailedException::slugTaken($request->slug, $exception);
        } catch (Throwable $throwable) {
            throw TenantProvisioningFailedException::failed($request->slug, $throwable);
        }

        return new ProvisionedTenant(
            id: $tenant->getId(),
            slug: $tenant->getSlug(),
            loginUrl: TenantHost::urlFor($tenant, self::LOGIN_PATH),
        );
    }

    /**
     * Checked here, before the service, so a taken slug is reported as such without a schema
     * being touched and without reading the reason out of an exception message.
     */
    private function assertSlugAvailable(string $slug): void
    {
        try {
            $this->slugPolicy->assertAssignable($slug);
        } catch (InvalidTenantSlugException $exception) {
            throw $exception->reservedByPlatform
                ? TenantProvisioningFailedException::slugReserved($slug, $exception)
                : TenantProvisioningFailedException::slugInvalid($slug, $exception);
        }

        if (null !== $this->tenantRepository->findBySlug($slug)) {
            throw TenantProvisioningFailedException::slugTaken($slug);
        }
    }
}
