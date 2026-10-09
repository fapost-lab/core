<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;
use App\Domains\Tenancy\Exceptions\TenantProvisioningException;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Support\TenantHost;
use App\Domains\Tenancy\ValueObjects\ProvisioningProblem;
use Fapost\Foundation\Tenancy\Contracts\TenantProvisionerInterface;
use Fapost\Foundation\Tenancy\DTO\ProvisionedTenant;
use Fapost\Foundation\Tenancy\DTO\ProvisionTenant;
use Fapost\Foundation\Tenancy\Exceptions\TenantProvisioningFailedException;
use Throwable;

/**
 * Core's implementation of the public tenant provisioning contract.
 *
 * Translates {@see TenantProvisioningService} into Foundation's request, result and failure
 * reasons, so a package that depends only on Foundation never sees Core exceptions or models.
 * Synchronous and slow (the full tenant schema is migrated): callers run it outside HTTP requests.
 *
 * After a `Failed` the slug is held by a Pending tenant whose id is on the exception; the caller continues
 * it with {@see CoreTenantReservations::provision()}.
 */
final readonly class CoreTenantProvisioner implements TenantProvisionerInterface
{
    public function __construct(
        private TenantProvisioningService $provisioningService,
        private TenantRepositoryInterface $tenantRepository,
        private TenantSlugPolicy $slugPolicy,
        private FirstAdminCredentialsGuard $credentials,
    ) {
    }

    public function provision(ProvisionTenant $request): ProvisionedTenant
    {
        $this->assertSlugAvailable($request->slug);

        $this->credentials->assertValid($request->adminEmail, $request->adminPasswordHash);

        try {
            $tenant = $this->provisioningService->provision(
                $request->slug,
                $request->adminEmail,
                $request->adminPasswordHash,
                $this->credentials->nameOrDefault($request->adminName),
            );
        } catch (TenantProvisioningException $exception) {
            // A slug taken here lost a race on insert after the check above. Any other failure leaves
            // the row Pending; its id lets the caller continue through TenantReservationInterface.
            throw match ($exception->problem) {
                ProvisioningProblem::SlugTaken => TenantProvisioningFailedException::slugTaken($request->slug, $exception),
                ProvisioningProblem::Conflict  => TenantProvisioningFailedException::conflict((string) $exception->tenantId, $exception),
                default                        => TenantProvisioningFailedException::failed($request->slug, $exception, $exception->tenantId),
            };
        } catch (Throwable $throwable) {
            throw TenantProvisioningFailedException::failed($request->slug, $throwable);
        }

        return new ProvisionedTenant(
            id: $tenant->getId(),
            slug: $tenant->getSlug(),
            loginUrl: TenantHost::urlFor($tenant, TenantHost::ADMIN_LOGIN_PATH),
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
