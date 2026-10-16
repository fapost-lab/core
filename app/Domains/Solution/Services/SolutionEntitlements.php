<?php

declare(strict_types=1);

namespace App\Domains\Solution\Services;

use App\Domains\Solution\Contracts\SolutionGrantSnapshotStoreInterface;
use Fapost\Foundation\Solution\Contracts\SolutionEntitlementInterface;
use Fapost\Foundation\Solution\DTO\SolutionEntitlement;
use Fapost\Foundation\Solution\DTO\SolutionGrant;
use Throwable;

/**
 * Asks the operator's {@see SolutionEntitlementInterface} which Solutions a tenant may run. The only caller of the
 * contract in Core.
 *
 * Bound `scoped` and memoised per tenant id, so the operator is asked at most once per tenant per request or job and
 * one tenant's answer is never given to another (a job may switch tenants with `runForTenant()`). An answer that
 * cannot be had is reported once and remembered as unavailable for the rest of the scope: the callers decide what
 * that means — {@see ActiveSolutions} keeps what runs (fail open), {@see SolutionActivator} refuses (fail closed).
 *
 * The grants of each successful answer are kept per tenant for {@see self::LAST_ANSWER_TTL_SECONDS}
 * ({@see SolutionGrantSnapshotStoreInterface}), and {@see self::lastGrantsFor()} hands them to {@see ActiveSolutions}
 * while the operator cannot answer, so an outage does not stop what the tenant's plan and add-ons run. Core's default
 * {@see AllSolutionsEntitled} cannot fail and grants nothing, so with it nothing is kept.
 */
final class SolutionEntitlements
{
    /** How long the grants of the last successful answer may stand in for an operator that cannot answer. */
    public const int LAST_ANSWER_TTL_SECONDS = 86_400;

    /** @var array<string, SolutionEntitlement|false> false: the operator threw */
    private array $answers = [];

    /** @var array<string, list<SolutionGrant>> */
    private array $lastGrants = [];

    public function __construct(
        private readonly SolutionEntitlementInterface $entitlement,
        private readonly SolutionGrantSnapshotStoreInterface $snapshots,
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

            if (false !== $this->answers[$tenantId]) {
                $this->keep($tenantId, $this->answers[$tenantId]);
            }
        }

        $answer = $this->answers[$tenantId];

        return false === $answer ? null : $answer;
    }

    /**
     * The grants of the last successful answer for the tenant, kept for at most a day: what runs while the operator
     * cannot answer. Empty when nothing is kept. Read once per tenant and scope.
     *
     * @return list<SolutionGrant>
     */
    public function lastGrantsFor(string $tenantId): array
    {
        if (! array_key_exists($tenantId, $this->lastGrants)) {
            try {
                $this->lastGrants[$tenantId] = $this->snapshots->get($tenantId);
            } catch (Throwable $exception) {
                report($exception);

                $this->lastGrants[$tenantId] = [];
            }
        }

        return $this->lastGrants[$tenantId];
    }

    /**
     * Drops the answer for one tenant, so the next question asks the operator again: after a connect changed it.
     */
    public function forget(string $tenantId): void
    {
        unset($this->answers[$tenantId], $this->lastGrants[$tenantId]);
    }

    private function keep(string $tenantId, SolutionEntitlement $answer): void
    {
        if ($this->entitlement instanceof AllSolutionsEntitled) {
            return;
        }

        try {
            if ([] === $answer->grants) {
                $this->snapshots->forget($tenantId);
            } else {
                $this->snapshots->put($tenantId, array_values($answer->grants), self::LAST_ANSWER_TTL_SECONDS);
            }
        } catch (Throwable $exception) {
            // The kept grants are insurance, never the answer: a cache that fails must not fail the question.
            report($exception);
        }
    }
}
