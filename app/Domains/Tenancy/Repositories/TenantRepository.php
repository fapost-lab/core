<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Repositories;

use App\Domains\Tenancy\Contracts\TenantInterface;
use App\Domains\Tenancy\Contracts\TenantRepositoryInterface;
use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Domains\Tenancy\ValueObjects\ReleaseRefusal;
use App\Domains\Tenancy\ValueObjects\TenantProvisioningState;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Landlord-backed repository for {@see Tenant} aggregates.
 *
 * All queries are explicitly performed on the `landlord` connection.
 */
final class TenantRepository implements TenantRepositoryInterface
{
    /**
     * Check if at least one tenant exists.
     */
    public function existsAny(): bool
    {
        return Tenant::on('landlord')->exists();
    }

    /**
     * Find tenant by id or return null.
     */
    public function findById(string $id): ?TenantInterface
    {
        return Tenant::on('landlord')->find($id);
    }

    /**
     * Find tenant by slug or return null.
     */
    public function findBySlug(string $slug): ?TenantInterface
    {
        return Tenant::on('landlord')->where('slug', $slug)->first();
    }

    /**
     * Get tenant by id or throw {@see TenantNotFoundException}.
     */
    public function getById(string $id): TenantInterface
    {
        return $this->findById($id) ?? throw TenantNotFoundException::forId($id);
    }

    /**
     * Return all active tenants.
     *
     * @return list<TenantInterface>
     */
    public function findAllActive(): array
    {
        return Tenant::on('landlord')
            ->where('status', TenantStatus::Active)
            ->get()
            ->all();
    }

    /**
     * Persist tenant on landlord connection.
     *
     * @throws InvalidArgumentException When given non-Eloquent implementation.
     */
    public function save(TenantInterface $tenant): void
    {
        if (! $tenant instanceof Tenant) {
            throw new InvalidArgumentException(
                sprintf('Expected %s, got %s.', Tenant::class, $tenant::class),
            );
        }

        $tenant->setConnection('landlord');
        $tenant->save();
    }

    /**
     * Tenants whose slug starts with the given prefix, in any status.
     *
     * @return list<TenantInterface>
     */
    public function findBySlugPrefix(string $prefix): array
    {
        return Tenant::on('landlord')
            ->where('slug', 'like', addcslashes($prefix, '%_\\') . '%')
            ->get()
            ->all();
    }

    /**
     * Delete the tenant's landlord row.
     */
    public function delete(TenantInterface $tenant): void
    {
        Tenant::on('landlord')->whereKey($tenant->getId())->delete();
    }

    public function isSlugOrSchemaTaken(string $slug, string $schemaName): bool
    {
        return Tenant::on('landlord')
            ->where(static function (Builder $query) use ($slug, $schemaName): void {
                $query->where('slug', $slug)->orWhere('schema_name', $schemaName);
            })
            ->exists();
    }

    public function findByReservationKey(string $reservationKey): ?TenantInterface
    {
        return Tenant::on('landlord')->where('reservation_key', $reservationKey)->first();
    }

    public function provisioningState(string $id): ?TenantProvisioningState
    {
        $tenant = Tenant::on('landlord')->find($id);

        if (! $tenant instanceof Tenant) {
            return null;
        }

        return new TenantProvisioningState(
            tenant: $tenant,
            status: $tenant->status,
            schemaClaimed: null !== $tenant->schema_claimed_at,
            leaseUntil: $tenant->provisioning_lease_until,
        );
    }

    public function acquireProvisioningLease(string $id, CarbonInterface $now, CarbonInterface $until): bool
    {
        return 1 === $this->pending($id)
            ->where(static function (Builder $query) use ($now): void {
                $query->whereNull('provisioning_lease_until')->orWhere('provisioning_lease_until', '<', $now);
            })
            ->update([
                'provisioning_lease_until' => $until,
                'provisioning_failed_at'   => null,
                'provisioning_error'       => null,
            ]);
    }

    public function extendProvisioningLease(string $id, CarbonInterface $heldUntil, CarbonInterface $until): bool
    {
        return 1 === $this->held($id, $heldUntil)->update(['provisioning_lease_until' => $until]);
    }

    public function markSchemaClaimed(string $id, CarbonInterface $heldUntil, CarbonInterface $now): bool
    {
        $held = $this->held($id, $heldUntil);

        if (1 === (clone $held)->whereNotNull('schema_claimed_at')->count()) {
            return true;
        }

        return 1 === $held->update(['schema_claimed_at' => $now]);
    }

    public function markProvisioningFailed(string $id, CarbonInterface $heldUntil, CarbonInterface $now, string $error): bool
    {
        return 1 === $this->held($id, $heldUntil)->update([
            'provisioning_failed_at'   => $now,
            'provisioning_error'       => mb_substr($error, 0, 255),
            'provisioning_lease_until' => null,
        ]);
    }

    public function activate(string $id, CarbonInterface $heldUntil): bool
    {
        return 1 === $this->held($id, $heldUntil)->update([
            'status'                   => TenantStatus::Active->value,
            'provisioning_lease_until' => null,
        ]);
    }

    public function reserve(TenantInterface $tenant): void
    {
        if (! $tenant instanceof Tenant) {
            throw new InvalidArgumentException(sprintf('Expected %s, got %s.', Tenant::class, $tenant::class));
        }

        $tenant->setConnection('landlord');
        $tenant->getConnection()->transaction(static function () use ($tenant): void {
            $tenant->save();
        });
    }

    public function releaseUnclaimedPending(string $id, CarbonInterface $now): ?ReleaseRefusal
    {
        $deleted = $this->pending($id)
            ->whereNull('schema_claimed_at')
            ->where(static function (Builder $query) use ($now): void {
                $query->whereNull('provisioning_lease_until')->orWhere('provisioning_lease_until', '<', $now);
            })
            ->delete();

        if (1 === $deleted) {
            return null;
        }

        $state = $this->provisioningState($id);

        if (null === $state) {
            return null;
        }

        if (TenantStatus::Pending !== $state->status) {
            return ReleaseRefusal::NotPending;
        }

        return $state->schemaClaimed ? ReleaseRefusal::SchemaClaimed : ReleaseRefusal::InProgress;
    }

    /**
     * The row, only while it is Pending and its lease still ends at `$heldUntil`.
     *
     * @return Builder<Tenant>
     */
    private function held(string $id, CarbonInterface $heldUntil): Builder
    {
        return $this->pending($id)->where('provisioning_lease_until', $heldUntil);
    }

    /**
     * @return Builder<Tenant>
     */
    private function pending(string $id): Builder
    {
        return Tenant::on('landlord')->whereKey($id)->where('status', TenantStatus::Pending->value);
    }
}
