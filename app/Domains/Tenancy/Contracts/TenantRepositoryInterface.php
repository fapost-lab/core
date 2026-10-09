<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\ValueObjects\ReleaseRefusal;
use App\Domains\Tenancy\ValueObjects\TenantProvisioningState;
use Carbon\CarbonInterface;

interface TenantRepositoryInterface
{
    public function existsAny(): bool;

    public function findById(string $id): ?TenantInterface;

    public function findBySlug(string $slug): ?TenantInterface;

    /**
     * @throws TenantNotFoundException
     */
    public function getById(string $id): TenantInterface;

    /**
     * @return array<int, TenantInterface>
     */
    public function findAllActive(): array;

    /**
     * Tenants whose slug starts with the given prefix, in any status.
     *
     * @return list<TenantInterface>
     */
    public function findBySlugPrefix(string $prefix): array;

    public function save(TenantInterface $tenant): void;

    /**
     * Delete the landlord row. The caller has already removed the tenant's
     * schema and webhook registry entries ({@see \App\Domains\Tenancy\Services\TenantDecommissioner}).
     */
    public function delete(TenantInterface $tenant): void;

    /**
     * Whether any row holds the slug or the schema name, in any status (Pending included).
     */
    public function isSlugOrSchemaTaken(string $slug, string $schemaName): bool;

    public function findByReservationKey(string $reservationKey): ?TenantInterface;

    public function provisioningState(string $id): ?TenantProvisioningState;

    /**
     * Takes the provisioning lease of a Pending tenant: one conditional UPDATE that succeeds only
     * when no live lease exists, and clears the last failure mark. False when it changed no row.
     */
    public function acquireProvisioningLease(string $id, CarbonInterface $now, CarbonInterface $until): bool;

    /**
     * Moves the lease end from `$heldUntil` to `$until`. Conditional on the row still carrying
     * `$heldUntil`; false when another run has taken over.
     */
    public function extendProvisioningLease(string $id, CarbonInterface $heldUntil, CarbonInterface $until): bool;

    /**
     * Marks the tenant as having claimed its schema. Idempotent; conditional on the lease the caller holds.
     * False when the lease was lost.
     */
    public function markSchemaClaimed(string $id, CarbonInterface $heldUntil, CarbonInterface $now): bool;

    /**
     * Records a failed run and frees the lease, if the caller still holds it. `$error` is a step and an
     * exception class, never a message.
     */
    public function markProvisioningFailed(string $id, CarbonInterface $heldUntil, CarbonInterface $now, string $error): bool;

    /**
     * Pending to Active, clearing the lease, if the caller still holds it. False otherwise.
     */
    public function activate(string $id, CarbonInterface $heldUntil): bool;

    /**
     * Inserts a reservation inside a transaction of its own (a savepoint when the caller already has
     * one), so a unique violation does not abort the caller's transaction and the caller can read on.
     *
     * @throws \Illuminate\Database\UniqueConstraintViolationException
     */
    public function reserve(TenantInterface $tenant): void;

    /**
     * One conditional DELETE: only a Pending row that has not claimed a schema and holds no live lease.
     * Returns null when the row was deleted or never existed, and the reason when it exists and was kept.
     */
    public function releaseUnclaimedPending(string $id, CarbonInterface $now): ?ReleaseRefusal;
}
