<?php

declare(strict_types=1);

namespace App\Domains\Tenancy\Contracts;

use App\Domains\Tenancy\Models\TenantDeletionRequest;
use App\Domains\Tenancy\ValueObjects\TenantAdmin;
use Carbon\CarbonInterface;

/**
 * The landlord table of requests to delete a workspace: at most one active (neither cancelled nor started) per tenant.
 */
interface TenantDeletionRequestRepositoryInterface
{
    public function active(string $tenantId): ?TenantDeletionRequest;

    public function find(string $id): ?TenantDeletionRequest;

    /**
     * Records the request, or returns null when the tenant already has an active one (also when another request won
     * the race on the unique index).
     */
    public function schedule(string $tenantId, TenantAdmin $by, CarbonInterface $now, CarbonInterface $startsAt): ?TenantDeletionRequest;

    /**
     * One conditional UPDATE: only a request that is neither cancelled nor started. False when it changed no row.
     */
    public function cancel(string $id, TenantAdmin $by, CarbonInterface $now): bool;

    /**
     * Active requests whose start has come.
     *
     * @return list<TenantDeletionRequest>
     */
    public function due(CarbonInterface $now): array;

    /**
     * Takes the request for the removal: marks it started if it was not, and answers whether the removal may go on.
     * False when the request is gone or was cancelled; true again for a retry of a request already started.
     */
    public function claim(string $id, CarbonInterface $now): bool;

    /**
     * Runs the work in one landlord transaction: a request and the queued notice about it commit together.
     *
     * @template T
     *
     * @param  callable(): T  $work
     *
     * @return T
     */
    public function transaction(callable $work): mixed;
}
