<?php

declare(strict_types=1);

namespace App\Domains\Flow\Contracts;

/**
 * Whether the action of a given owner may run for the tenant of the current scope.
 *
 * The action registry and the handler transport are singletons and cannot hold tenant-scoped state,
 * so they ask this port on every call; the implementation answers from the current scope.
 */
interface ActionAvailabilityInterface
{
    /**
     * @param  string|null  $solutionId  id of the Solution that registered the action; null for Core's own actions, which are always available
     */
    public function isAvailable(?string $solutionId): bool;
}
