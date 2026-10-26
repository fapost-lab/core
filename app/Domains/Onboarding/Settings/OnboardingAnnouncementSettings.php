<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Which late required steps already reached the staff's notification bell.
 *
 * A group of its own, apart from {@see OnboardingSettings}: spatie saves every property of a class, so a second writer
 * of the wizard's state (a page load announcing a step) could save a stale `state` over a restart. The wizard's state
 * keeps one writer; this class is written only by {@see \App\Domains\Onboarding\Services\OnboardingAnnouncements} and
 * cleared when the wizard starts. Built fresh for every read and write, like the wizard's state.
 */
final class OnboardingAnnouncementSettings extends Settings
{
    /**
     * @var list<string>
     */
    public array $announced_steps = [];

    public static function group(): string
    {
        return 'onboarding_announcements';
    }
}
