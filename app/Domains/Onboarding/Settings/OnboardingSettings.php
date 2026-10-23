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
 */
final class OnboardingSettings extends Settings
{
    public OnboardingStatus $state = OnboardingStatus::Complete;

    /**
     * Keys of the steps that are not derived from data and were confirmed by a person.
     *
     * @var list<string>
     */
    public array $confirmed_steps = [];

    /** ISO 8601 moment the wizard was (re)started. */
    public ?string $started_at = null;

    /** ISO 8601 moment the wizard closed. */
    public ?string $completed_at = null;

    public static function group(): string
    {
        return 'onboarding';
    }
}
