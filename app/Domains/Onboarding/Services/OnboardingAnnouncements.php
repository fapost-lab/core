<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Services;

use App\Domains\Onboarding\Settings\OnboardingAnnouncementSettings;

/**
 * Reads and writes which late required steps were announced in the bell. Touches only its own settings group, never the
 * wizard's state. Every call builds a fresh settings instance, so it always answers for the tenant of the moment.
 */
final readonly class OnboardingAnnouncements
{
    /**
     * @return list<string>
     */
    public function announced(): array
    {
        return array_values((new OnboardingAnnouncementSettings())->announced_steps);
    }

    /**
     * @param  list<string>  $keys
     */
    public function announce(array $keys): void
    {
        $settings = new OnboardingAnnouncementSettings();

        $settings->announced_steps = array_values(array_unique([...array_values($settings->announced_steps), ...$keys]));
        $settings->save();
    }

    public function forget(): void
    {
        $settings                  = new OnboardingAnnouncementSettings();
        $settings->announced_steps = [];
        $settings->save();
    }
}
