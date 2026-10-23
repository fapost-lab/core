<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Domains\Onboarding\Enums\OnboardingStatus;
use App\Domains\Onboarding\Services\OnboardingState;
use App\Domains\Onboarding\Settings\OnboardingSettings;
use App\Domains\Tenancy\Models\Tenant;
use App\Domains\Tenancy\Models\TenantStatus;
use App\Infrastructure\Onboarding\OnboardingInitializer;
use Tests\Feature\Concerns\BuildsProvisioningService;
use Tests\Feature\FeatureTestCase;

/**
 * Where the state of the wizard comes from: the settings migration for the tenants that exist, provisioning for the one
 * that is created, and the writes the wizard makes.
 */
final class OnboardingStateTest extends FeatureTestCase
{
    use BuildsProvisioningService;

    public function test_a_tenant_that_existed_when_the_settings_migration_ran_is_onboarded(): void
    {
        $settings = new OnboardingSettings();

        $this->assertSame(OnboardingStatus::Complete, $settings->state);
        $this->assertSame([], $settings->confirmed_steps);
        $this->assertNull($settings->started_at);
        $this->assertNull($settings->completed_at);
        $this->assertFalse($this->app->make(OnboardingState::class)->isPending());
    }

    public function test_provisioning_puts_the_new_tenant_into_the_wizard(): void
    {
        $service = $this->provisioningService(initializer: new OnboardingInitializer($this->app->make(OnboardingState::class)));

        $tenant = $service->provision('acme', 'admin@acme.test', 'S3cret-p@ss-value', 'Acme Admin');

        $this->assertSame(TenantStatus::Active, Tenant::on('landlord')->findOrFail($tenant->getId())->status);
        $settings = new OnboardingSettings();
        $this->assertSame(OnboardingStatus::Pending, $settings->state);
        $this->assertNotNull($settings->started_at);
        $this->assertNull($settings->completed_at);
    }

    public function test_without_an_initializer_provisioning_leaves_the_state_alone(): void
    {
        $this->provisioningService()->provision('acme', 'admin@acme.test', 'S3cret-p@ss-value', 'Acme Admin');

        $this->assertSame(OnboardingStatus::Complete, new OnboardingSettings()->state);
    }

    public function test_the_wizard_container_binds_the_initializer_for_the_provisioning_service(): void
    {
        $this->assertInstanceOf(
            OnboardingInitializer::class,
            $this->app->make(\App\Domains\Tenancy\Contracts\NewTenantInitializerInterface::class),
        );
    }

    public function test_confirming_a_step_is_kept_once_and_starting_again_forgets_it(): void
    {
        $state = $this->app->make(OnboardingState::class);
        $state->start();

        $state->confirm('languages');
        $state->confirm('languages');

        $this->assertSame(['languages'], $state->confirmedSteps());

        $state->start();

        $this->assertSame([], $state->confirmedSteps());
        $this->assertTrue($state->isPending());
    }

    public function test_completing_records_the_moment_once(): void
    {
        $state = $this->app->make(OnboardingState::class);
        $state->start();

        $state->complete();
        $first = new OnboardingSettings()->completed_at;
        $state->complete();

        $this->assertNotNull($first);
        $this->assertSame($first, new OnboardingSettings()->completed_at);
        $this->assertFalse($state->isPending());
    }
}
