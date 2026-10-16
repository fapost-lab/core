<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Throwable;

/**
 * Asks the operator's {@see SolutionEntitlementInterface} which Solutions a tenant may run. The only caller of the
 * contract in Core.
 *
 * Bound `scoped` and memoised per tenant id, so the operator is asked at most once per tenant per request or job and
 * one tenant's answer is never given to another (a job may switch tenants with `runForTenant()`). An answer that
 * cannot be had is reported once and remembered as unavailable for the rest of the scope: the callers decide what
 * that means — {@see ActiveSolutions} keeps what runs (fail open), {@see SolutionActivator} refuses (fail closed).
 */
final class SolutionEntitlements
{
    /** @var array<string, SolutionEntitlement|false> false: the operator threw */
    private array $answers = [];

    public function __construct(
        private readonly SolutionEntitlementInterface $entitlement,
    ) {
    }

    /**
     * @return SolutionEntitlement|null null when the operator's answer could not be had
     */
    public function forTenant(string $tenantId): ?SolutionEntitlement
    {
        if (! array_key_exists($tenantId, $this->answers)) {
            try {
                $this->answers[$tenantId] = $this->entitlement->entitlementFor($tenantId);
            } catch (Throwable $exception) {
                report($exception);

                $this->answers[$tenantId] = false;
            }
        }

        $answer = $this->answers[$tenantId];

        return false === $answer ? null : $answer;
    }
}
