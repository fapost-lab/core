<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Services;

use Fapost\Foundation\Tenancy\Contracts\TenantAccessModeInterface;
use Fapost\Foundation\Tenancy\DTO\TenantAccessState;
use Throwable;

/**
 * Asks the operator's {@see TenantAccessModeInterface} and fails open.
 *
 * An operator outage (its database, its cache, a bug) must not stop every tenant at once, so an
 * answer that cannot be had is reported and counts as active. Every Core call site that decides
 * something from the access mode goes through here rather than to the contract.
 */
final readonly class TenantAccessStates
{
    public function __construct(
        private TenantAccessModeInterface $accessMode,
    ) {
    }

    public function stateFor(string $tenantId): TenantAccessState
    {
        try {
            return $this->accessMode->stateFor($tenantId);
        } catch (Throwable $exception) {
            report($exception);

            return TenantAccessState::active();
        }
    }
}
