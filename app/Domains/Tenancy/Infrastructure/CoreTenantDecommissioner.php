<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Infrastructure;

use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\Services\TenantDecommissioner;
use Fapost\Foundation\Tenancy\Contracts\TenantDecommissionerInterface;
use Fapost\Foundation\Tenancy\DTO\DecommissionedTenant;
use Fapost\Foundation\Tenancy\Enums\DecommissionReason;
use Fapost\Foundation\Tenancy\Exceptions\TenantDecommissionFailedException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Core's implementation of the public tenant decommission contract.
 *
 * Resolves the tenant, refuses a Pending one, and hands the removal to {@see TenantDecommissioner}, which dispatches
 * the event and writes the log line. The landlord row goes last, after the event, so a run that stopped half way finds
 * the tenant again on the next call and finishes what is left. A tenant that is already gone
 * is reported as such and announces nothing.
 */
final readonly class CoreTenantDecommissioner implements TenantDecommissionerInterface
{
    public function __construct(
        private TenantRepositoryInterface $tenants,
        private TenantDecommissioner $removal,
    ) {
    }

    public function decommission(string $tenantId, DecommissionReason $reason): DecommissionedTenant
    {
        $id = mb_strtolower($tenantId);

        try {
            // Tenant ids are lowercase RFC 4122 ULIDs; any other form names no tenant and must not reach a `uuid` column.
            $state = Str::isUuid($id) ? $this->tenants->provisioningState($id) : null;

            if (null === $state) {
                return new DecommissionedTenant($tenantId, null, alreadyGone: true, mediaKeptInClientStorage: false);
            }

            if (TenantStatus::Pending === $state->status) {
                throw TenantDecommissionFailedException::pending($id);
            }

            $mediaKept = $this->removal->decommissionCompletely($state->tenant, $reason);

            return new DecommissionedTenant($id, $state->tenant->getSlug(), alreadyGone: false, mediaKeptInClientStorage: $mediaKept);
        } catch (TenantDecommissionFailedException $exception) {
            throw $exception;
        } catch (Throwable $throwable) {
            throw TenantDecommissionFailedException::failed($id, $throwable);
        }
    }
}
