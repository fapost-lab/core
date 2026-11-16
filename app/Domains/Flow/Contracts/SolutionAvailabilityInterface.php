<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Whether the action of a given owner may run for the tenant of the current scope.
 *
 * The action registry and the handler transport are singletons and cannot hold tenant-scoped state,
 * so they ask this port on every call; the implementation answers from the current scope.
 */
interface SolutionAvailabilityInterface
{
    /**
     * @param  string|null  $solutionId  id of the Solution that registered the action; null for Core's own actions, which are always available
     */
    public function isAvailable(?string $solutionId): bool;

    /**
     * Whether the tenant's schema holds the tables the Solution's code needs. Ask it after {@see isAvailable()}: an
     * available Solution that is not ready answers `solution_not_ready` instead of failing on a missing table, and
     * the implementation starts preparing it.
     *
     * @param  string  $solutionId  id of the Solution that registered the action or node type
     */
    public function isReady(string $solutionId): bool;
}
