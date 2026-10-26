<?php

declare(strict_types=1);

namespace App\Infrastructure\Onboarding;

use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Services\TenantSwitcher;
use Fapost\Foundation\Tenancy\Contracts\TenantOnboardingInterface;
use Fapost\Foundation\Tenancy\Exceptions\TenantOnboardingException;
use Illuminate\Support\Str;

/**
 * Core's implementation of the public setup-wizard contract, and the one service behind `tenants:onboarding:restart`.
 *
 * Reads and writes inside the named tenant through {@see TenantSwitcher}, whatever context the caller is in (an
 * operator panel runs outside every tenant); the caller's own tenant, if any, is restored afterwards.
 */
final readonly class CoreTenantOnboarding implements TenantOnboardingInterface
{
    public function __construct(
        private TenantRepositoryInterface $tenants,
        private TenantSwitcher $switcher,
        private OnboardingState $state,
    ) {
    }

    public function restart(string $tenantId): void
    {
        $tenant = $this->activeTenant($tenantId);

        $this->switcher->runForTenant($tenant, function (): void {
            $this->state->start();
        });
    }

    public function isPending(string $tenantId): bool
    {
        $tenant = $this->activeTenant($tenantId);

        return (bool) $this->switcher->runForTenant($tenant, fn (): bool => $this->state->isPending());
    }

    /**
     * @throws TenantOnboardingException when the tenant does not exist or is not Active
     */
    private function activeTenant(string $tenantId): TenantInterface
    {
        $id = mb_strtolower($tenantId);

        // A malformed id never reaches PostgreSQL, where it would be a query error rather than "not found".
        $tenant = Str::isUuid($id) ? $this->tenants->findById($id) : null;

        if (! $tenant instanceof TenantInterface) {
            throw TenantOnboardingException::tenantNotFound($tenantId);
        }

        if (! $tenant->isActive()) {
            throw TenantOnboardingException::tenantNotActive($tenantId);
        }

        return $tenant;
    }
}
