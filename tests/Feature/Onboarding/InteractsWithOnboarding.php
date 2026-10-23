<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Staff\Enums\RoleEnum;
use App\Domains\Staff\Models\User;
use App\Domains\Tenancy\Settings\TenantSettings;

/**
 * Helpers for the setup-wizard tests: a tenant held in the wizard and the users that meet it.
 */
trait InteractsWithOnboarding
{
    protected function holdTenantInWizard(): void
    {
        $this->app->make(OnboardingState::class)->start();
    }

    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Admin->value);

        return $user;
    }

    /**
     * A staff member who may use the console but not manage settings.
     */
    protected function manager(): User
    {
        $user = User::factory()->create();
        $user->assignRole(RoleEnum::Analyst->value);

        return $user;
    }

    protected function freshTenantSettings(): TenantSettings
    {
        $this->app->forgetInstance(TenantSettings::class);

        return $this->app->make(TenantSettings::class)->refresh();
    }
}
