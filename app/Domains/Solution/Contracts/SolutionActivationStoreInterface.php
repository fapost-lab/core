<?php

declare(strict_types=1);

namespace App\Domains\Solution\Contracts;

use App\Domains\Solution\DTO\ActivationActor;
use App\Domains\Solution\DTO\SolutionActivation;
use App\Domains\Solution\DTO\SolutionActivationEvent;
use App\Domains\Solution\Enums\ActivationChange;
use App\Domains\Solution\Enums\SolutionActivationStatus;

/**
 * Where a tenant's Solution activation lives: the tenant's own schema. Every method acts on the tenant the
 * caller is switched into; none takes a tenant, so a caller outside `runForTenant()` reaches the wrong schema
 * and the registry in front of it ({@see \App\Domains\Solution\Services\ActiveSolutions}) is the only
 * source of the effective state. Raw rows and the journal are read here, by screens and commands that list them.
 */
interface SolutionActivationStoreInterface
{
    /**
     * Ids whose stored status is active, installed or not.
     *
     * @return list<string> sorted
     */
    public function activeIds(): array;

    /**
     * Every stored row, orphans included (activated, no longer installed).
     *
     * @return list<SolutionActivation> ordered by Solution id
     */
    public function all(): array;

    /**
     * The journal of one Solution, oldest first.
     *
     * @return list<SolutionActivationEvent>
     */
    public function events(string $solutionId): array;

    /**
     * Brings the Solution to `$target` and journals the change, as one transaction holding a lock on the row.
     * Idempotent: a repeat in the same state, or a deactivation of a Solution that has no row, writes nothing.
     */
    public function change(string $solutionId, SolutionActivationStatus $target, ActivationActor $actor, ?string $reason): ActivationChange;
}
