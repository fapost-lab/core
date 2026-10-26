<?php

declare(strict_types=1);

namespace App\Infrastructure\Onboarding;

use App\Domains\Channels\Models\Channel;
use App\Domains\Onboarding\Contracts\OnboardingStepInterface;
use App\Domains\Onboarding\Enums\CoreOnboardingStep;

/**
 * Connecting a first channel. Optional, and derived from the data: a tenant with any channel has done it.
 *
 * The wizard offers it after the required steps; skipping writes nothing, so a skipped step stays open in the checklist.
 */
final readonly class ChannelStep implements OnboardingStepInterface
{
    public function key(): string
    {
        return CoreOnboardingStep::Channel->value;
    }

    public function required(): bool
    {
        return false;
    }

    public function route(): string
    {
        return 'console.admin.onboarding.channel.create';
    }

    public function routes(): array
    {
        return [
            'console.admin.onboarding.channel.create',
            'console.admin.onboarding.channel.store',
        ];
    }

    public function isComplete(): bool
    {
        return Channel::query()->exists();
    }
}
