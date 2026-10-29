<?php

declare(strict_types=1);

namespace App\Domains\Onboarding\Settings;

use App\Domains\Onboarding\Enums\OnboardingStatus;
use Spatie\LaravelSettings\Settings;

/**
 * Where a tenant stands in the first-run wizard, kept apart from {@see \App\Domains\Tenancy\Settings\TenantSettings}
 * so the settings screen, which saves that class whole, never writes it.
 *
 * Not bound or cached like TenantSettings: {@see \App\Domains\Onboarding\Services\OnboardingState} builds a fresh
 * instance for every read and write, which loads from the connection that is current. A fresh instance cannot
 * outlive a tenant switch, so no hook has to hide it (the spatie cache key already carries the tenant).
 *
 * The properties have no defaults on purpose: spatie fills a missing row with the property's default on load, so a
 * tenant the settings migrations have not reached would read as onboarded with no known step, and every required step
 * would look late. Without defaults that load throws `MissingSettings`, which every reader already treats as
 * "unreadable".
 */
final class OnboardingSettings extends Settings
{
    public OnboardingStatus $state;

    /**
     * Keys of the steps that are not derived from data and were confirmed by a person.
     *
     * @var list<string>
     */
    public array $confirmed_steps;

    /**
     * Keys of the steps that were registered when the wizard closed. A required step outside this list appeared after
     * the tenant finished onboarding: that is a late step.
     *
     * @var list<string>
     */
    public array $known_steps;

    /** ISO 8601 moment the wizard was (re)started. */
    public ?string $started_at;

    /** ISO 8601 moment the wizard closed. */
    public ?string $completed_at;

    public static function group(): string
    {
        return 'onboarding';
    }
}
