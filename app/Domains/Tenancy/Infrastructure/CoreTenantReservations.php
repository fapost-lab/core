<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Exceptions\TenantProvisioningException;
use App\Domains\Tenancy\Services\TenantProvisioningService;
use App\Domains\Tenancy\Services\TenantSlugPolicy;
use App\Domains\Tenancy\Support\TenantHost;
use App\Domains\Tenancy\ValueObjects\ProvisioningProblem;
use App\Domains\Tenancy\ValueObjects\ReleaseRefusal;
use App\Domains\Tenancy\ValueObjects\SlugRejection;
use Fapost\Foundation\Tenancy\Contracts\TenantReservationInterface;
use Fapost\Foundation\Tenancy\DTO\ProvisionedTenant;
use Fapost\Foundation\Tenancy\DTO\ReservedTenant;
use Fapost\Foundation\Tenancy\DTO\SlugCheck;
use Fapost\Foundation\Tenancy\DTO\TenantAdmin;
use Fapost\Foundation\Tenancy\Enums\SlugAvailability;
use Fapost\Foundation\Tenancy\Enums\SlugProblem;
use Fapost\Foundation\Tenancy\Exceptions\TenantProvisioningFailedException;
use Fapost\Foundation\Tenancy\Exceptions\TenantReleaseRefusedException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Core's implementation of the public slug reservation and resumable provisioning contract.
 *
 * Translates {@see TenantProvisioningService} into Foundation's DTOs and failure reasons. Holds no
 * operator logic: when a reservation expires, whom to mail or when to retry is the caller's business.
 */
final readonly class CoreTenantReservations implements TenantReservationInterface
{
    public function __construct(
        private TenantProvisioningService $provisioningService,
        private TenantRepositoryInterface $tenantRepository,
        private TenantSlugPolicy $slugPolicy,
        private FirstAdminCredentialsGuard $credentials,
    ) {
    }

    public function check(string $slug): SlugCheck
    {
        try {
            $this->slugPolicy->assertAssignable($slug);
        } catch (InvalidTenantSlugException $exception) {
            return SlugRejection::Reserved === $exception->reason
                ? new SlugCheck($slug, SlugAvailability::Reserved)
                : new SlugCheck($slug, SlugAvailability::Invalid, $this->problemOf($exception->reason));
        }

        if ($this->tenantRepository->isSlugOrSchemaTaken($slug, $this->slugPolicy->schemaNameFor($slug))) {
            return new SlugCheck($slug, SlugAvailability::Taken);
        }

        return new SlugCheck($slug, SlugAvailability::Available);
    }

    public function reserve(string $slug, string $reservationKey): ReservedTenant
    {
        try {
            $tenant = $this->provisioningService->reserveSlug($slug, $reservationKey);
        } catch (InvalidTenantSlugException $exception) {
            throw SlugRejection::Reserved === $exception->reason
                ? TenantProvisioningFailedException::slugReserved($slug, $exception)
                : TenantProvisioningFailedException::slugInvalid($slug, $exception);
        } catch (TenantProvisioningException $exception) {
            throw ProvisioningProblem::SlugTaken === $exception->problem
                ? TenantProvisioningFailedException::slugTaken($slug, $exception)
                : TenantProvisioningFailedException::failed($slug, $exception);
        }

        return new ReservedTenant($tenant->getId(), $tenant->getSlug());
    }

    public function release(string $tenantId): void
    {
        $id = $this->normalizeId($tenantId);

        if (null === $id) {
            return;
        }

        $refusal = $this->provisioningService->releaseReservation($id);

        if (null === $refusal) {
            return;
        }

        throw match ($refusal) {
            ReleaseRefusal::NotPending    => TenantReleaseRefusedException::notPending($id),
            ReleaseRefusal::SchemaClaimed => TenantReleaseRefusedException::provisioningStarted($id),
            ReleaseRefusal::InProgress    => TenantReleaseRefusedException::inProgress($id),
        };
    }

    public function provision(string $tenantId, TenantAdmin $admin): ProvisionedTenant
    {
        $this->credentials->assertValid($admin->email, $admin->passwordHash);

        $id = $this->normalizeId($tenantId) ?? throw TenantProvisioningFailedException::tenantNotFound($tenantId);

        try {
            $tenant = $this->provisioningService->provisionReserved(
                $id,
                $admin->email,
                $admin->passwordHash,
                $this->credentials->nameOrDefault($admin->name),
            );
        } catch (TenantNotFoundException $exception) {
            throw TenantProvisioningFailedException::tenantNotFound($id, $exception);
        } catch (TenantProvisioningException $exception) {
            throw match ($exception->problem) {
                ProvisioningProblem::NotPending => TenantProvisioningFailedException::tenantNotPending($id, $exception),
                ProvisioningProblem::InProgress => TenantProvisioningFailedException::inProgress($id, $exception),
                ProvisioningProblem::Conflict   => TenantProvisioningFailedException::conflict($id, $exception),
                default                         => TenantProvisioningFailedException::failed($exception->slug ?? $id, $exception, $id),
            };
        } catch (Throwable $throwable) {
            throw TenantProvisioningFailedException::failed($id, $throwable, $id);
        }

        return $this->provisioned($tenant);
    }

    private function provisioned(TenantInterface $tenant): ProvisionedTenant
    {
        return new ProvisionedTenant(
            id: $tenant->getId(),
            slug: $tenant->getSlug(),
            loginUrl: TenantHost::urlFor($tenant, TenantHost::ADMIN_LOGIN_PATH),
        );
    }

    private function problemOf(SlugRejection $rejection): SlugProblem
    {
        return match ($rejection) {
            SlugRejection::TooLong            => SlugProblem::TooLong,
            SlugRejection::PunycodePrefix     => SlugProblem::PunycodePrefix,
            SlugRejection::ConsecutiveHyphens => SlugProblem::ConsecutiveHyphens,
            SlugRejection::Malformed,
            SlugRejection::Reserved => SlugProblem::Malformed,
        };
    }

    /**
     * Tenant ids are lowercase RFC 4122 ULIDs; any other form names no tenant, and must not
     * reach a `uuid` column where Postgres would reject it.
     */
    private function normalizeId(string $id): ?string
    {
        $lower = mb_strtolower($id);

        return Str::isUuid($lower) ? $lower : null;
    }
}
