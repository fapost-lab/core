<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;

/**
 * Default {@see SolutionEntitlementInterface}: every tenant may run every installed Solution. An installation
 * without an operator package behaves as if entitlement did not exist.
 */
final class AllSolutionsEntitled implements SolutionEntitlementInterface
{
    public function entitlementFor(string $tenantId): SolutionEntitlement
    {
        return SolutionEntitlement::all();
    }
}
