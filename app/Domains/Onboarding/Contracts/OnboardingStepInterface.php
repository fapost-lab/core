<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Contracts;

/**
 * One step of the first-run setup wizard.
 *
 * A step is built per scope (see {@see \App\Domains\Onboarding\Services\OnboardingProgress}), so it may hold scoped
 * collaborators; it must not memoize an answer across calls, since the wizard asks again after every write.
 */
interface OnboardingStepInterface
{
    /**
     * The stable key a confirmation is stored under.
     */
    public function key(): string;

    /**
     * Whether the console stays closed until the step is complete.
     */
    public function required(): bool;

    /**
     * The name of the route that shows the step's page.
     */
    public function route(): string;

    /**
     * Every route name the gate lets through while the step is open: the page and the writes it makes.
     *
     * @return list<string>
     */
    public function routes(): array;

    /**
     * Whether the step is done, derived from the tenant's data or from a stored confirmation.
     */
    public function isComplete(): bool;
}
