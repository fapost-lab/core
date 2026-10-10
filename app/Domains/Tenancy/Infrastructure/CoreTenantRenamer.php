<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Exceptions\InvalidTenantSlugException;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Exceptions\TenantRenameException;
use App\Domains\Tenancy\Services\TenantSlugChanger;
use App\Domains\Tenancy\Support\TenancyResolutionMode;
use App\Domains\Tenancy\Support\TenantHost;
use App\Domains\Tenancy\ValueObjects\RenameProblem;
use App\Domains\Tenancy\ValueObjects\SlugRejection;
use DateTimeImmutable;
use DateTimeZone;
use Fapost\Foundation\Tenancy\Contracts\TenantRenamerInterface;
use Fapost\Foundation\Tenancy\DTO\RenamedTenant;
use Fapost\Foundation\Tenancy\Enums\SlugProblem;
use Fapost\Foundation\Tenancy\Exceptions\TenantRenameFailedException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Core's implementation of the public tenant rename contract.
 *
 * Checks the mode, maps {@see TenantSlugChanger}'s exceptions to Foundation's failure reasons and
 * builds the DTO. Notifies nobody and keeps no history: that is the caller's product.
 */
final readonly class CoreTenantRenamer implements TenantRenamerInterface
{
    public function __construct(private TenantSlugChanger $slugChanger)
    {
    }

    public function rename(string $tenantId, string $newSlug): RenamedTenant
    {
        // In `single` mode the default tenant's slug is set by TENANT_SLUG and frozen by route:cache.
        if (TenancyResolutionMode::Host !== TenancyResolutionMode::tryFrom((string) config('tenancy.resolution'))) {
            throw TenantRenameFailedException::unavailable($tenantId);
        }

        $id = $this->normalizeId($tenantId) ?? throw TenantRenameFailedException::tenantNotFound($tenantId);

        try {
            $change = $this->slugChanger->change($id, $newSlug);
        } catch (InvalidTenantSlugException $exception) {
            throw SlugRejection::Reserved === $exception->reason
                ? TenantRenameFailedException::slugReserved($id, $newSlug, $exception)
                : TenantRenameFailedException::slugInvalid($id, $newSlug, $this->problemOf($exception->reason), $exception);
        } catch (TenantNotFoundException $exception) {
            throw TenantRenameFailedException::tenantNotFound($id, $exception);
        } catch (TenantRenameException $exception) {
            throw RenameProblem::TenantPending === $exception->problem
                ? TenantRenameFailedException::tenantPending($id, $exception)
                : TenantRenameFailedException::slugTaken($id, $newSlug, $exception);
        } catch (Throwable $throwable) {
            throw TenantRenameFailedException::failed($id, $throwable);
        }

        return new RenamedTenant(
            id: $change->tenant->getId(),
            slug: $change->tenant->getSlug(),
            previousSlug: $change->previousSlug,
            changed: $change->changed,
            url: mb_rtrim(TenantHost::urlFor($change->tenant, '/'), '/') . '/',
            loginUrl: TenantHost::urlFor($change->tenant, TenantHost::ADMIN_LOGIN_PATH),
            redirectUntil: null === $change->redirectUntil
                ? null
                : new DateTimeImmutable('@' . $change->redirectUntil->getTimestamp())->setTimezone(new DateTimeZone('UTC')),
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
