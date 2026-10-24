<?php

declare(strict_types=1);

namespace App\Infrastructure\Onboarding;

use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\Enums\CoreOnboardingStep;
use App\Domains\Staff\Models\User;

/**
 * Inviting the team. Optional, and derived from the data: the tenant has someone besides the person setting it up.
 *
 * The platform support user is not the team (it belongs to the operator, not the tenant), so it is not counted; an
 * invited user who has not yet activated the account is.
 */
final readonly class TeamStep implements OnboardingStepInterface
{
    public function key(): string
    {
        return CoreOnboardingStep::Team->value;
    }

    public function required(): bool
    {
        return false;
    }

    public function route(): string
    {
        return 'console.admin.onboarding.team.create';
    }

    public function routes(): array
    {
        return [
            'console.admin.onboarding.team.create',
            'console.admin.onboarding.team.store',
        ];
    }

    public function isComplete(): bool
    {
        return User::query()->withoutPlatformSupport()->count() > 1;
    }
}
