<?php

declare(strict_types=1);

namespace App\Domains\Solution\Contracts;

use Fapost\Foundation\Solution\DTO\SolutionGrant;

/**
 * The grants of the operator's last successful answer, kept per tenant for a bounded time so that granted Solutions
 * keep running while the operator cannot answer. A cache of the answer, never the tenant's state: it is read only when
 * the operator fails, and losing it only means the fallback has fewer Solutions to run.
 *
 * Every key carries the tenant id it is given; an implementation never derives it from ambient context.
 */
interface SolutionGrantSnapshotStoreInterface
{
    /**
     * @param  list<SolutionGrant>  $grants  not empty
     */
    public function put(string $tenantId, array $grants, int $ttlSeconds): void;

    /**
     * @return list<SolutionGrant> empty when nothing is kept or it expired
     */
    public function get(string $tenantId): array;

    public function forget(string $tenantId): void;
}
