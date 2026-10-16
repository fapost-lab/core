<?php

declare(strict_types=1);

namespace Tests\Support;

use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use RuntimeException;

/**
 * An operator's entitlement answer for tests: one answer per tenant id (default: everything), or an outage, and a
 * record of the tenants it was asked about.
 */
final class FakeSolutionEntitlement implements SolutionEntitlementInterface
{
    /** @var array<string, SolutionEntitlement> */
    public array $answers = [];

    /** the answer for a tenant not in $answers; null: everything */
    public ?SolutionEntitlement $default = null;

    public bool $failing = false;

    /** @var list<string> */
    public array $asked = [];

    public function entitlementFor(string $tenantId): SolutionEntitlement
    {
        $this->asked[] = $tenantId;

        if ($this->failing) {
            throw new RuntimeException('The operator is down.');
        }

        return $this->answers[$tenantId] ?? $this->default ?? SolutionEntitlement::all();
    }
}
