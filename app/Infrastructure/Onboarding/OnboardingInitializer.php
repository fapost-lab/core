<?php

declare(strict_types=1);

namespace App\Infrastructure\Onboarding;

use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Tenancy\Contracts\NewTenantInitializerInterface;

/**
 * Puts a tenant that has just been provisioned into the setup wizard: the one place that writes `pending`, besides a
 * restart. A tenant created already populated is closed by whatever populates it.
 */
final readonly class OnboardingInitializer implements NewTenantInitializerInterface
{
    public function __construct(private OnboardingState $state)
    {
    }

    public function initialize(): void
    {
        $this->state->start();
    }
}
