<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\Exceptions\TenantNotFoundException;
use App\Domains\Tenancy\ValueObjects\ReleaseRefusal;
use App\Domains\Tenancy\ValueObjects\TenantProvisioningState;
use Carbon\CarbonInterface;
use Closure;

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
     * Whether any row holds the slug or the schema name, in any status (Pending included), or the
     * slug is a tenant's former slug.
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

    /**
     * Runs the callback in a transaction on the landlord connection (a savepoint when the caller already
     * has one). An exception rolls it back and propagates.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function transaction(Closure $callback): mixed;

    /**
     * Serializes everyone who claims a slug (a rename, a reservation) until the current transaction ends:
     * a transaction-scoped advisory lock on PostgreSQL, nothing elsewhere. Call it first inside
     * {@see self::transaction()}, before reading what the slug is checked against.
     */
    public function lockSlugClaims(): void;

    /**
     * The tenant row, locked with FOR UPDATE until the current transaction ends, with its status; null when
     * there is none.
     */
    public function findForRename(string $id): ?TenantProvisioningState;

    /**
     * Whether the slug, or the schema name derived from it, belongs to a tenant other than `$exceptTenantId`:
     * as its slug, as its schema name (its original slug) or as its former slug.
     */
    public function isSlugClaimedByOther(string $slug, string $schemaName, string $exceptTenantId): bool;

    /**
     * One conditional UPDATE of the slug, only while the row still carries the slug the tenant has. On success
     * the given tenant carries the new slug. False when it changed no row.
     *
     * @throws \Illuminate\Database\UniqueConstraintViolationException
     */
    public function renameSlug(TenantInterface $tenant, string $newSlug, CarbonInterface $now): bool;

    /**
     * Records a slug the tenant gave up. It stays reserved for the tenant; it redirects until `$redirectUntil`
     * (never when null).
     */
    public function addFormerSlug(string $slug, string $tenantId, ?CarbonInterface $redirectUntil, CarbonInterface $now): void;

    /**
     * Forgets a former slug of this tenant (it takes the slug back). Another tenant's record is not touched.
     */
    public function removeFormerSlug(string $slug, string $tenantId): void;

    /**
     * The tenant a slug moved away from, while the redirect period has not ended; null otherwise.
     */
    public function findByFormerSlug(string $slug, CarbonInterface $now): ?TenantInterface;
}
